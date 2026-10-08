<?php
/**
 * OpenHunter · 企业库（2026-09-25「以公司为主体挖人」），cron_recruit_run.php 第 ⑧ 步，每 5 分钟：
 *   ⑧a 挂靠：档案或企业库变了的候选人重挂，没见过的公司自动建档（纯 PHP，不花钱，不看开关，每轮 500 人）
 *       + 简历推断归类：按简历里写的行业 / 地点给公司归大类、判本地海外（不花钱，即时）
 *   ⑧b 职能归类：没归过的职位名批量问模型（每轮 ≤240 个）+ AI 快速归类公司（不联网，每轮 ≤60 家）
 *       （受「自动解析」开关与每日 AI 预算控制）
 *   ⑧c 资料补全：搜索 + Firecrawl + 模型。「立即检索」点过的先做；自动队列另受
 *       recruit.company.enrich_enabled（默认关）与 recruit.company.enrich_daily（每天几家，默认 30）控制
 * 逻辑在 includes/recruit_company.php。
 *
 *   php scripts/cron_recruit_company.php               # 跑一轮
 *   php scripts/cron_recruit_company.php --dry-run     # 只看队列
 *   php scripts/cron_recruit_company.php --only=link|func|enrich
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }
// ⛔ CLI 下 dvChatJson* 用 [120,60] 秒尝试超时；不定义就走 web 的 25/18/8 秒，30 家一批的归类 / 150 家的赛道方案必然超时、反复重跑
define('JCT_DOCUMENT_WORKER', true);
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_company.php';
require_once $root . '/includes/recruit_cost.php';

$dry = false; $only = ''; $oneId = 0;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    elseif (preg_match('/^--only=(link|func|classify|enrich)$/', $a, $m)) $only = $m[1];
    elseif (preg_match('/^--id=(\d+)$/', $a, $m)) $oneId = (int)$m[1];   // 只检索这一家（页面「检索」触发）
    else { fwrite(STDERR, "未知参数 $a\n"); exit(1); }
}
$lock = fopen(sys_get_temp_dir() . '/openhunter_recruit_company.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo date('c'), " ⑧ 企业库：上一轮仍在运行，跳过\n"; exit(0); }

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!recruitCompanyReady($pdo)) { echo date('c'), " ⑧ 企业库：表未建（跑 create_recruit_companies_20260925.php --apply），跳过\n"; exit(0); }
$run = fn(string $s) => $only === '' || $only === $s;

if ($dry) {
    $ver = recruitCompanyVer($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM recruit_candidates WHERE profile_rev>0 AND (company_rev<>? OR company_prof_rev<>profile_rev)");
    $st->execute([$ver]);
    echo "待挂靠候选人：", $st->fetchColumn(), " 人（企业库版本 {$ver}）\n";
    echo "企业：", $pdo->query("SELECT COUNT(*) FROM recruit_companies WHERE merged_into=0")->fetchColumn(), " 家；待补全 ",
         $pdo->query("SELECT COUNT(*) FROM recruit_companies WHERE enrich_status='queued' AND merged_into=0")->fetchColumn(), " 家；立即检索 ",
         $pdo->query("SELECT COUNT(*) FROM recruit_companies WHERE enrich_priority=1")->fetchColumn(), " 家\n";
    echo "未归类职位名：", $pdo->query("SELECT COUNT(DISTINCT x.title_key) FROM recruit_candidate_companies x LEFT JOIN recruit_title_functions tf ON tf.title_key=x.title_key
                                       WHERE x.title_key<>'' AND (tf.id IS NULL OR (tf.source='' AND tf.attempts<3))")->fetchColumn(), " 个\n";
    echo "自动补全：", recruitCompanySetting($pdo, 'recruit.company.enrich_enabled', '0') === '1' ? '开' : '关',
         " · 每天 ", recruitCompanySetting($pdo, 'recruit.company.enrich_daily', '30'), " 家\n";
    exit(0);
}

$out = [];
if ($oneId > 0) {   // 页面上点「检索」：只做这一家，不看自动补全开关与每日上限（仍受 AI 预算）
    try {
        $r = recruitTokensToday($pdo) < recruitDailyBudget($pdo) ? recruitCompanyEnrichOne($pdo, $oneId, recruitCompanyRealIo($pdo)) : ['status' => 'skipped', 'error' => 'budget'];
        echo date('c'), " ⑧ 企业库检索 #$oneId：{$r['status']} {$r['error']}\n";
    } catch (RecruitEnrichUnavailable $e) {
        $pdo->prepare("UPDATE recruit_companies SET enrich_status='failed', enrich_error=?, enrich_priority=0 WHERE id=?")->execute([mb_substr($e->getMessage(), 0, 255), $oneId]);
        echo date('c'), " ⑧ 企业库检索 #$oneId：接口不可用 ", $e->getMessage(), "\n";
    }
    exit(0);
}
if ($run('link')) {
    try {
        $l = recruitCompanyLinkBatch($pdo);
        $inf = recruitCompanyInferFromResumes($pdo, 2000, $l['touched']);
        $seed = recruitSegmentSeed($pdo, 2000, $l['touched']);   // 内置细分赛道（关键词，不花钱）：全量每天一次，其余轮次只看新挂进人的公司
        if ($seed['assigned']) $out[] = "内置赛道挂上 {$seed['assigned']} 家";
        if ($l['candidates'] || $inf) $out[] = "挂靠 {$l['candidates']} 人 {$l['links']} 段 · 新建档 {$l['created']} 家 · 简历推断归类 {$inf} 家";
    } catch (Throwable $e) { $out[] = '挂靠失败：' . $e->getMessage(); }
}
// 花钱的两步：看「自动解析」开关与每日预算（与解析 / 匹配同一个预算）
$aiOn = recruitCompanySetting($pdo, 'ai_intake.recruit.enabled', '0') === '1';
$budgetOk = recruitTokensToday($pdo) < recruitDailyBudget($pdo);
$llm = fn(array $req) => dvChatJsonMulti($pdo, $req);
if ($run('func')) {
    if (!$aiOn || !$budgetOk) $out[] = '职能归类跳过（' . (!$aiOn ? '自动解析未开' : '今日 AI 预算已用完') . '）';
    else {
        try { $f = recruitFuncBatch($pdo, $llm); if ($f['titles'] || $f['abort']) $out[] = "职能归类 {$f['titles']} 个职位名 · 成功 {$f['ok']} · 失败 {$f['failed']}" . ($f['abort'] ? " · ⛔ {$f['abort']}" : ''); }
        catch (Throwable $e) { $out[] = '职能归类失败：' . $e->getMessage(); }
    }
}
if ($run('classify')) {
    if (!$aiOn || !$budgetOk) $out[] = 'AI 快速归类跳过（' . (!$aiOn ? '自动解析未开' : '今日 AI 预算已用完') . '）';
    else {
        try { $c = recruitCompanyAiClassify($pdo, $llm); if ($c['companies'] || $c['abort']) $out[] = "AI 快速归类 {$c['companies']} 家 · 成功 {$c['ok']}" . ($c['abort'] ? " · ⛔ {$c['abort']}" : ''); }
        catch (Throwable $e) { $out[] = 'AI 快速归类失败：' . $e->getMessage(); }
    }
}
// ⑧b' 每日一次：还没赛道的公司按行业自动分细分赛道（AI 建赛道 + 挂上；人工定过的不动）
if ($run('classify') && $aiOn && $budgetOk) {
    try {
        $sg = recruitSegmentDaily($pdo, $llm);
        if ($sg['industries']) $out[] = "自动分赛道 {$sg['industries']} 个行业 · 新赛道 {$sg['created']} 个 · 挂上 {$sg['assigned']} 家";
        if ($sg['skipped'] !== '' && $sg['skipped'] !== 'done_today') $out[] = "自动分赛道部分失败（今天不再重试）：{$sg['skipped']}";
    } catch (Throwable $e) { $out[] = '自动分赛道失败：' . $e->getMessage(); }
}
if ($run('enrich')) {
    if (!$budgetOk) $out[] = '资料补全跳过（今日 AI 预算已用完）';
    else {
        try {
            $r = recruitCompanyEnrichBatch($pdo, recruitCompanyRealIo($pdo));
            if ($r['done'] + $r['failed'] > 0) $out[] = sprintf('资料补全 %d 家 · 失败 %d · 约 $%.3f', $r['done'], $r['failed'], $r['cost']);
            elseif ($r['skipped'] !== '' && $r['skipped'] !== 'disabled') $out[] = '资料补全跳过（' . $r['skipped'] . '）';
        } catch (Throwable $e) { $out[] = '资料补全失败：' . $e->getMessage(); }
    }
}
$line = $out ? implode(' ｜ ', $out) : '无事可做';
// 页面顶部说明条显示「上次什么时候跑的、做了什么」
if (function_exists('setSystemSetting')) setSystemSetting($pdo, 'recruit.company.last_run', json_encode(['at' => date('Y-m-d H:i:s'), 'text' => $line], JSON_UNESCAPED_UNICODE));
echo date('c'), ' ⑧ 企业库：', $line, "\n";
