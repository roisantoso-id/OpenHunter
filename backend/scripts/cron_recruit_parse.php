<?php
/**
 * OpenHunter · 解析 / 拆 JD 要求 / 匹配 / 高分提醒 worker
 *
 * 每轮依次：① 解析新简历 → ② 给新建/改过的职位拆要求 → ③ 候选人/职位分维度向量化 → ④ 算语义分（纯 PHP，不调模型）
 *          → ⑤ 大模型只评语义分靠前的组合（召回 → 精排，见 recruit_match.php 头注释）
 *          → ⑥ 高分人选企微提醒（includes/recruit_alert.php；「高分提醒」开关关着就跳过）。
 * 只匹配在招的职位：职位「招聘中」且所属项目「进行中」，已关闭/暂停/不存在的一律不关联（recruit_match.php $jobOk）。
 *
 * 定时任务不直接配这个脚本，配 cron_recruit_run.php（先拉邮件再调本脚本，一条 crontab 串完全流程）。
 * 本脚本另由页面「立即 AI 匹配」、上传简历 / 改职位后的自动触发拉起（includes/recruit_pipeline.php）。
 *
 * 手动：
 *   php scripts/cron_recruit_parse.php --dry-run            # 看队列，不认领不调用
 *   php scripts/cron_recruit_parse.php --limit=5            # 只解析 5 份（显式给 --limit 只跑一批，不循环）
 *   php scripts/cron_recruit_parse.php --resume=123         # 只处理指定简历（须是 pending/retry 且到期）
 *   php scripts/cron_recruit_parse.php --ignore-switch      # 忽略总开关（联调用；生产走开关）
 *   php scripts/cron_recruit_parse.php --only=parse|jd|embed|sem|match|alert  # 只跑某一步
 *   终端里手动跑会逐步、逐份打印进度；输出重定向时默认只打汇总，加 --verbose 强制打进度
 *
 * 总开关：system_settings `ai_intake.recruit.enabled` = '1' 才跑——补拉历史/新建职位会产生大量调用，
 * 要能一键停。每轮时间预算 100 秒，到点停在两批之间，下一轮 cron 接着做。
 * 成本闸：当天 recruit_* 已用 token 超过 `recruit.ai.daily_token_budget`（默认 200 万）就整轮不跑，次日恢复
 *   （includes/recruit_cost.php）。用量与每份简历成本：php scripts/ops/recruit_cost_report.php
 *
 * ⛔ define('JCT_DOCUMENT_WORKER')：让 dvChatJson* 在 CLI 下用 [120,60] 秒的尝试超时。
 *    §7.10 的「超时 < FPM 100 秒」只约束 web 请求；这里是 CLI，不经过 FPM。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }
define('JCT_DOCUMENT_WORKER', true);

$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_match.php';   // 含 recruit_parse.php
require_once $root . '/includes/recruit_cost.php';
require_once $root . '/includes/recruit_alert.php';

$opt = ['dry-run' => false, 'ignore-switch' => false, 'limit' => 20, 'limit_set' => false, 'resume' => 0, 'only' => '', 'quiet' => false, 'run' => 0];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $opt['dry-run'] = true;
    elseif ($a === '--ignore-switch') $opt['ignore-switch'] = true;
    elseif (preg_match('/^--(limit|resume)=(\d+)$/', $a, $m)) { $opt[$m[1]] = (int)$m[2]; if ($m[1] === 'limit') $opt['limit_set'] = true; }
    elseif (preg_match('/^--run=(\d+)$/', $a, $m)) $opt['run'] = (int)$m[1];   // 页面「立即 AI 匹配」预建的运行记录 id
    elseif ($a === '--quiet') $opt['quiet'] = true;
    elseif ($a === '--verbose') $opt['verbose'] = true;   // 输出重定向（| tee 日志）时也打进度
    elseif (preg_match('/^--only=(parse|jd|embed|sem|match|alert)$/', $a, $m)) $opt['only'] = $m[1];
    else { fwrite(STDERR, "未知参数 $a\n"); exit(1); }
}

// 终端里手动跑：逐步、逐份打印进度；cron（输出重定向到日志）只留最后一行汇总
if (empty($opt['verbose']) && function_exists('posix_isatty') && !posix_isatty(STDOUT)) $opt['quiet'] = true;

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* 运行记录（recruit_pipeline_runs，页面「运行记录」里看）：
   页面按钮拉起的（--run=id）逐行实时写进 log；crontab 的先攒在内存，跑完有东西才落一行，空跑不留 */
$runId = $opt['run'];
$runLog = [];
$hasRunTable = true;
try { $pdo->query("SELECT 1 FROM recruit_pipeline_runs LIMIT 1"); } catch (Throwable $e) { $hasRunTable = false; }
$appendLog = $hasRunTable && $runId > 0
    ? $pdo->prepare("UPDATE recruit_pipeline_runs SET log=" . dbConcat("COALESCE(log,'')", '?') . " WHERE id=?") : null;
$say = function (string $msg) use (&$opt, &$runLog, $appendLog, $runId) {
    $line = date('H:i:s') . ' ' . $msg;
    $runLog[] = $line;
    if ($appendLog) { try { $appendLog->execute([$line . "\n", $runId]); } catch (Throwable $e) {} }
    if (!$opt['quiet']) { echo $line, "\n"; flush(); }
};
$finishRun = function (string $status, array $summary = []) use ($pdo, &$runId, &$runLog, $hasRunTable) {
    if (!$hasRunTable) return;
    try {
        if ($runId > 0) {
            $pdo->prepare("UPDATE recruit_pipeline_runs SET status=?, summary_json=?, finished_at=(" . dbNow() . ") WHERE id=?")
                ->execute([$status, json_encode($summary, JSON_UNESCAPED_UNICODE), $runId]);
        } elseif (!empty($summary['worked'])) {   // cron：有实际处理才留记录
            $pdo->prepare("INSERT INTO recruit_pipeline_runs (trigger_type, status, summary_json, log, started_at, finished_at)
                           VALUES ('cron', ?, ?, ?, ?, (" . dbNow() . "))")
                ->execute([$status, json_encode($summary, JSON_UNESCAPED_UNICODE), implode("\n", $runLog) . "\n", date('Y-m-d H:i:s', (int)$GLOBALS['__runStart'])]);
        }
    } catch (Throwable $e) {}
};
$GLOBALS['__runStart'] = time();
$finished = false;
register_shutdown_function(function () use (&$finished, $finishRun, $say) {
    if ($finished) return;
    $err = error_get_last();
    if ($err) $say('✗ 异常退出：' . $err['message']);
    $finishRun('failed', ['worked' => true, 'error' => $err['message'] ?? 'exit']);
});

/* 锁文件 root（crontab）和 www（页面「立即 AI 匹配」拉起的 worker）都要能用：
   新建时放开权限；已被别的用户建过、写不了就只读打开——flock 不要求写权限 */
$lockPath = sys_get_temp_dir() . '/openhunter_recruit_parse.lock';
$lock = @fopen($lockPath, 'c') ?: @fopen($lockPath, 'r');
if ($lock) @chmod($lockPath, 0666);
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('c'), " 上一轮仍在运行，跳过\n";
    if ($runId > 0) { $say('已有一轮正在运行，本次跳过（等它跑完再看结果）'); $finishRun('skipped', []); }
    $finished = true; exit(0);
}
if ($runId > 0 && $hasRunTable) $pdo->prepare("UPDATE recruit_pipeline_runs SET status='running', started_at=(" . dbNow() . ") WHERE id=?")->execute([$runId]);

$st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='ai_intake.recruit.enabled'");
$st->execute();
if ((string)$st->fetchColumn() !== '1' && !$opt['ignore-switch'] && !$opt['dry-run']) {
    echo date('c'), " ai_intake.recruit.enabled 未开启，跳过\n"; $finished = true; exit(0);
}

$budget = recruitDailyBudget($pdo);
$usedToday = recruitTokensToday($pdo);
if ($opt['dry-run']) {
    echo "=== 成本 ===\n  今天已用 " . number_format($usedToday) . " / 预算 " . number_format($budget) . " token\n";
}
if (!$opt['dry-run'] && $usedToday >= $budget) {
    echo date('c'), " 今天已用 ", number_format($usedToday), " token，超过预算 ", number_format($budget), "，本轮不跑（次日恢复；要放宽改 recruit.ai.daily_token_budget）\n";
    $say('⛔ 今天 AI 用量已超过每日预算，本轮不跑（次日恢复，或在招聘设置调高预算）');
    $finishRun('aborted', ['worked' => $runId > 0, 'abort' => 'budget']);
    $finished = true; exit(0);
}
if ($opt['dry-run']) {
    echo "=== 解析队列（dry-run）===\n";
    foreach ($pdo->query("SELECT parse_status, COUNT(*) n, SUM(CASE WHEN next_retry_at IS NULL OR next_retry_at <= " . dbNow() . " THEN 1 ELSE 0 END) due
                          FROM recruit_resumes GROUP BY parse_status ORDER BY parse_status") as $r)
        printf("  %-12s %5d（到期 %d）\n", $r['parse_status'], $r['n'], $r['due']);
    $jd = (int)$pdo->query("SELECT COUNT(*) FROM recruit_jobs j JOIN recruit_projects p ON p.id=j.project_id AND p.status='open'
                            WHERE j.status='open' AND j.jd_req_source<>'manual' AND j.jd_req_rev<j.jd_rev")->fetchColumn();
    echo "  待拆要求的职位 $jd 个\n";
    $vc = (int)$pdo->query("SELECT COUNT(*) FROM recruit_candidates WHERE profile_rev>0 AND vec_rev<profile_rev")->fetchColumn();
    $vj = (int)$pdo->query("SELECT COUNT(*) FROM recruit_jobs WHERE status='open' AND vec_rev<jd_rev")->fetchColumn();
    echo "  待向量化：候选人 $vc 个 · 职位 $vj 个；语义分已算 " . (int)$pdo->query("SELECT COUNT(*) FROM recruit_sem_scores")->fetchColumn() . " 个组合\n";
    $ac = recruitAlertConfig($pdo);
    echo "  高分提醒: " . ($ac['enabled'] ? "开 · ≥{$ac['threshold']} 分 · 固定接收人 " . implode(',', $ac['user_ids']) . " · {$ac['since']} 起" : '关') . "\n";
    $cfg = openaiOcrConfig($pdo);
    echo "  Gemini 配置: " . ($cfg ? "model={$cfg['model']}" : '⚠️ 未配置或密钥解不开——正式跑会整轮中止、不扣次数') . "\n";
    $finished = true; exit(0);
}

$cfg = openaiOcrConfig($pdo);
$llm = fn(array $req) => dvChatJsonMulti($pdo, $req);
$model = (string)($cfg['model'] ?? '');
$deadline = microtime(true) + 100;
$abort = '';
$run = fn(string $step) => $opt['only'] === '' || $opt['only'] === $step;

// ⓪ 提示词版本：代码里升了版本号的内置提示词入库成草稿并排回归评测（worker 异步跑，更好就自动启用，见 includes/recruit_prompts.php）
require_once $root . '/includes/recruit_prompts.php';
foreach (recruitPromptSeed($pdo) as $pid) {
    $q = recruitPromptQueueEval($pdo, $pid);
    $say("⓪ 新的内置提示词版本入库（#$pid），" . ($q['ok'] ? "已排回归评测 #{$q['id']}" : "评测没排上：{$q['error']}"));
}

// ① 解析
$tot = ['claimed' => 0, 'parsed' => 0, 'dedupe' => 0, 'retry' => 0, 'failed' => 0, 'candidates_new' => 0];
if ($run('parse')) {
    $show = $pdo->prepare("SELECT r.id, r.file_name, r.parse_status, r.parse_mode, r.parse_error_kind, r.doc_type, COALESCE(c.name,'') cand,
                                  (SELECT total_tokens FROM ai_api_usage u WHERE u.biz_type='recruit_resume' AND u.biz_id=r.id ORDER BY u.id DESC LIMIT 1) tok
                           FROM recruit_resumes r LEFT JOIN recruit_candidates c ON c.id=r.candidate_id WHERE r.id=?");
    // 「每批 N 份」不是「只读 N 份」（2026-09-24 问「这个只读 20 份简历吗」）：
    // 一批一批循环到队列清空或本轮 100 秒用完，剩下的下一轮定时任务接着做。日志把这层说清楚
    $queued = (int)$pdo->query("SELECT COUNT(*) FROM recruit_resumes WHERE parse_status IN ('pending','retry')
                                AND (next_retry_at IS NULL OR next_retry_at <= " . dbNow() . ")")->fetchColumn();
    $say($opt['limit_set'] || $opt['resume'] > 0
        ? "① 解析：队列 {$queued} 份，本次只跑一批（最多 {$opt['limit']} 份）"
        : "① 解析：队列 {$queued} 份待解析，每批 {$opt['limit']} 份，循环到清空或本轮 100 秒用完（剩下的下一轮接着做）");
    $batchNo = 0;
    do {
        $batchNo++;
        $t0 = microtime(true);
        $rep = recruitParseBatch($pdo, $llm, ['limit' => $opt['limit'], 'model' => $model,
            'only_ids' => $opt['resume'] > 0 ? [$opt['resume']] : []]);
        foreach ($tot as $k => $_) $tot[$k] += (int)$rep[$k];
        if ($rep['claimed'] === 0 && !$rep['aborted']) { $say($batchNo === 1 ? '   队列是空的' : '   队列已清空'); break; }
        $say("   第 {$batchNo} 批：{$rep['claimed']} 份");
        foreach ($rep['ids'] ?? [] as $rid) {
            $show->execute([$rid]);
            $x = $show->fetch(PDO::FETCH_ASSOC);
            $say(sprintf("   #%d %s → %s%s%s%s", $rid, mb_strimwidth((string)($x['file_name'] ?: '（邮件正文）'), 0, 40, '…'), $x['parse_status'],
                $x['parse_status'] === 'parsed' ? " · {$x['doc_type']}" . ($x['cand'] !== '' ? " · {$x['cand']}" : '') . " · {$x['parse_mode']}" : '',
                $x['parse_error_kind'] !== '' && $x['parse_status'] !== 'parsed' ? " · {$x['parse_error_kind']}" : '',
                $x['tok'] ? " · {$x['tok']} token" : ''));
        }
        $say(sprintf("   第 %d 批 %d 份：成功 %d（复用 %d）· 待重试 %d · 失败 %d · %.0fs", $batchNo, $rep['claimed'], $rep['parsed'], $rep['dedupe'], $rep['retry'], $rep['failed'], microtime(true) - $t0));
        if ($rep['aborted']) { $abort = $rep['abort_reason']; break; }
        // 显式给了 --limit：只跑这一批（先试 5 份看效果与成本），不循环到队列清空
    } while ($rep['claimed'] > 0 && $opt['resume'] === 0 && !$opt['limit_set'] && microtime(true) < $deadline);
}
// ①b 解析失败的邮件：AI 判是不是广告 / 垃圾邮件，是就把发件人拉黑（includes/recruit_sender_guard.php，2026-09-24）
$sg = ['checked' => 0, 'blocked' => 0, 'resumes' => 0];
if ($abort === '' && $run('parse') && microtime(true) < $deadline) {
    require_once $root . '/includes/recruit_sender_guard.php';
    try {
        $sg = recruitAutoBlockSenders($pdo, $llm);
        if ($sg['checked'] + $sg['blocked'] > 0) {
            $say(sprintf('①b 解析失败邮件查发件人：判定 %d 封 · 新拉黑 %d 个发件人 · %d 份简历标为已拉黑', $sg['checked'], $sg['blocked'], $sg['resumes']));
            foreach ($sg['list'] as $x) $say("   ⛔ {$x['email']}（{$x['reason_key']}）");
        }
    } catch (Throwable $e) { $say('①b 查发件人失败：' . $e->getMessage()); }
}
// ② 拆职位要求（匹配要等要求拆好，统一尺子）
$jd = ['jobs' => 0, 'ok' => 0, 'failed' => 0];
if ($abort === '' && $run('jd') && microtime(true) < $deadline) {
    $say('② 拆职位要求……');
    $rep = recruitJdReqBatch($pdo, $llm, ['model' => $model]);
    foreach ($jd as $k => $_) $jd[$k] += (int)$rep[$k];
    if ($rep['aborted']) $abort = $rep['abort_reason'];
    $say(sprintf('   拆要求：%d 个职位 · 成功 %d · 失败 %d%s', $jd['jobs'], $jd['ok'], $jd['failed'], $abort !== '' ? " · ⛔ {$abort}" : ''));
}
// ③ 向量化。失败不挡后面：已挂着的组合照样可以重评
$em = ['candidates' => 0, 'jobs' => 0, 'texts' => 0, 'failed' => 0];
$embedAbort = ''; $embedErr = '';
if ($abort === '' && $run('embed')) {
    $say('③ 向量化……');
    $embed = recruitEmbedHttp($pdo);
    while (microtime(true) < $deadline) {
        $rep = recruitEmbedBatch($pdo, $embed, ['limit' => 60]);
        foreach ($em as $k => $_) $em[$k] += (int)$rep[$k];
        if ($rep['aborted']) { $embedAbort = $rep['abort_reason']; $embedErr = (string)($rep['abort_error'] ?? ''); break; }
        if ($rep['failed'] > 0 || $rep['candidates'] + $rep['jobs'] === 0) break;   // 有失败就等下轮，不在本轮反复重试
    }
    $say(sprintf('   向量：%d 人 · %d 个职位 · %d 段文本 · 失败 %d%s', $em['candidates'], $em['jobs'], $em['texts'], $em['failed'],
        $embedAbort !== '' ? " · ⛔ {$embedAbort}（没有向量的组合改由大模型直接评）" : ''));
    if (!empty($embedErr)) $say('   向量接口返回：' . preg_replace('/(key|token)=[^&\s"]+/i', '$1=***', $embedErr));
}
// ④ 语义分
$sm = ['jobs' => 0, 'pairs' => 0];
if ($run('sem') && microtime(true) < $deadline) {
    $say('④ 算语义分……');
    $rep = recruitSemScoreBatch($pdo);
    foreach ($sm as $k => $_) $sm[$k] += (int)$rep[$k];
    $say(sprintf('   语义分：%d 个职位 · %d 个组合', $sm['jobs'], $sm['pairs']));
}
// ⑤ 匹配（大模型精排）
$mt = ['candidates' => 0, 'calls' => 0, 'pairs' => 0, 'failed' => 0];
if ($abort === '' && $run('match')) {
    $say('⑤ 大模型精排……');
    while (microtime(true) < $deadline) {
        $rep = recruitMatchBatch($pdo, $llm, ['limit' => 30, 'model' => $model]);
        foreach ($mt as $k => $_) $mt[$k] += (int)$rep[$k];
        if ($rep['candidates'] > 0) $say(sprintf('   本批：%d 人 · %d 次调用 · %d 个组合打分 · 失败 %d', $rep['candidates'], $rep['calls'], $rep['pairs'], $rep['failed']));
        if ($rep['aborted']) { $abort = $rep['abort_reason']; break; }
        if ($rep['candidates'] === 0) break;
    }
    $say(sprintf('   精排合计：%d 人 · %d 个组合打分 · 失败 %d%s', $mt['candidates'], $mt['pairs'], $mt['failed'], $abort !== '' ? " · ⛔ {$abort}" : ''));
}

// ⑥ 高分提醒：不调模型，前面中止了也照发（中止前已打的分）
$al = ['enabled' => false, 'links' => 0, 'messages' => 0];
if ($run('alert')) {
    try {
        $al = recruitHighScoreAlerts($pdo);
        $say($al['enabled'] ? sprintf('⑥ 高分提醒：%d 个高分组合 · 发出 %d 条企微', $al['links'], $al['messages']) : '⑥ 高分提醒：开关未开（系统设置 · 招聘设置 · 高分提醒）');
    } catch (Throwable $e) { $say('⑥ 高分提醒失败：' . $e->getMessage()); }
}

printf("%s 解析：认领 %d · 成功 %d（复用 %d）· 新建候选人 %d · 待重试 %d · 失败 %d ｜ 拆要求：%d 个职位 成功 %d 失败 %d ｜ 向量：%d 人 %d 职位 %d 段文本 失败 %d%s ｜ 语义分：%d 职位 %d 组合 ｜ 匹配：%d 人 %d 次调用 %d 个组合 失败 %d ｜ 高分提醒：%s%s\n",
    date('c'), $tot['claimed'], $tot['parsed'], $tot['dedupe'], $tot['candidates_new'], $tot['retry'], $tot['failed'],
    $jd['jobs'], $jd['ok'], $jd['failed'],
    $em['candidates'], $em['jobs'], $em['texts'], $em['failed'], $embedAbort !== '' ? "（⛔ {$embedAbort}）" : '',
    $sm['jobs'], $sm['pairs'],
    $mt['candidates'], $mt['calls'], $mt['pairs'], $mt['failed'],
    $al['enabled'] ? "{$al['links']} 个组合 {$al['messages']} 条" : '未开',
    $abort !== '' ? " · ⛔ 本轮中止（{$abort}）" : '');
$summary = ['parse' => $tot, 'jd' => $jd, 'embed' => $em + ['abort' => $embedAbort], 'sem' => $sm, 'match' => $mt, 'alert' => $al, 'abort' => $abort,
            'worked' => $runId > 0 || $tot['claimed'] + $jd['jobs'] + $em['texts'] + $sm['pairs'] + $mt['pairs'] + $mt['failed'] + $al['links'] > 0 || $abort !== ''];
$say($abort !== '' ? "⛔ 本轮中止：{$abort}" : '✓ 本轮完成');
$finishRun($abort !== '' ? 'aborted' : 'done', $summary);
$finished = true;
exit($abort !== '' || $embedAbort !== '' ? 1 : 0);
