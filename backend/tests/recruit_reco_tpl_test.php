<?php
/**
 * 推荐信模板：php tests/recruit_reco_tpl_test.php（纯函数 + 测试库集成，假模型，自清理）
 * 要守住的：内置默认模板与模板上线前的推荐信版式一致；块清洗（白名单、key 唯一、空块丢）；变量替换；
 * 渲染按模板顺序、手填 / 固定微调生效、空的不出；必填手填没填 → 拦导出；重新生成保留模板 / 手填 / 微调。
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/openai_vision.php';
require_once $root . '/includes/recruit_reco.php';
require_once $root . '/includes/recruit_reco_tpl.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

echo "一、默认模板与块清洗\n";
$d = recruitRecoDefaultTemplate('en');
ok($d['title'] === 'Candidate Recommendation' && array_column($d['blocks'], 'key') === ['subject', 'greeting', 'intro', 'match', 'paragraphs', 'availability', 'interview', 'closing', 'signature', 'confidential'],
   '内置默认：与原推荐信同顺序（AI 各段 → 面谈评价 → 结束语 → 落款 → 保密声明）');
$b = recruitNormalizeTplBlocks([
    ['type' => 'fixed', 'key' => 'Intro Co!', 'text' => '我们为 {{client}} 推荐'],
    ['type' => 'fixed', 'key' => 'intro_co', 'text' => '第二段同 key'],
    ['type' => 'fixed', 'text' => '   '],                        // 空固定文字丢
    ['type' => 'ai', 'slot' => 'hack'],                          // 非法 slot 丢
    ['type' => 'manual', 'title' => '', 'key' => 'x'],           // 手填没标题丢
    ['type' => 'manual', 'title' => '期望薪资', 'key' => 'salary', 'required' => 1],
    ['type' => 'field', 'source' => 'education', 'title' => '教育背景'],
    ['type' => 'script', 'text' => 'x'],
]);
ok(array_column($b, 'type') === ['fixed', 'fixed', 'manual', 'field'], '非法类型 / slot / 空块被丢');
ok($b[0]['key'] === 'introco' && $b[1]['key'] !== 'introco' && $b[2]['required'] === true, 'key 规整且唯一；required 布尔');

echo "\n二、变量与渲染\n";
$vars = recruitTplVars(['client' => 'PT ABC', 'position' => 'HR Manager', 'candidate_name' => 'Budi', 'candidate_code' => 'OH-CD-000050-45',
                        'recruiter_name' => 'Alice'], 'zh', '2026-09-24');
ok(recruitTplFill('致 {{client}}：推荐 {{candidate}}（{{candidate_code}}）应聘 {{position}}，{{nope}}', $vars)
   === '致 PT ABC：推荐 Budi（OH-CD-000050-45）应聘 HR Manager，{{nope}}', '变量替换；不认识的原样留着');
$tpl = ['id' => 5, 'name' => 'T', 'title' => '{{company}} 推荐函', 'blocks' => [
    ['key' => 'co', 'type' => 'fixed', 'title' => '关于我们', 'text' => '我们为 {{client}} 推荐 {{candidate}}。'],
    ['key' => 'intro', 'type' => 'ai', 'slot' => 'intro'],
    ['key' => 'match', 'type' => 'ai', 'slot' => 'match', 'title' => '要求对照'],
    ['key' => 'salary', 'type' => 'manual', 'title' => '期望薪资', 'required' => true],
    ['key' => 'note', 'type' => 'manual', 'title' => '面谈评价'],
    ['key' => 'edu', 'type' => 'field', 'source' => 'education', 'title' => '教育'],
    ['key' => 'sig', 'type' => 'field', 'source' => 'signature'],
]];
$content = ['tpl' => $tpl, 'manual' => ['salary' => 'IDR 20 jt'], 'fixed' => ['co' => '（单封微调）为 {{client}} 推荐。'],
            'letter' => ['to' => 'PT ABC', 'intro' => '**8 年 HR** 经验', 'match' => [['requirement' => 'S1', 'met' => 'yes', 'evidence' => 'S1 Hukum']]],
            'resume' => ['education' => [['school' => 'UI', 'degree' => 'S1', 'major' => 'Law', 'period' => '2010']]]];
$meta = ['position' => 'HR Manager', 'candidate_name' => 'Budi', 'candidate_code' => 'OH-CD-000050-45', 'recruiter_name' => 'Alice', 'recruiter_email' => 'c@x.com'];
$items = recruitRecoLetterItems($content, $meta, 'zh', '2026-09-24');
$kinds = array_column($items, 'kind');
ok($kinds === ['title', 'to', 'meta', 'heading', 'para', 'para', 'heading', 'match', 'heading', 'para', 'heading', 'para', 'lines'], '按模板顺序出条目；空的面谈评价不出');
ok($items[0]['text'] === 'OpenHunter 推荐函' && str_contains($items[2]['text'], 'OH-CD-000050-45'), '标题变量替换；职位 / 编号 / 日期行固定有');
ok($items[4]['text'] === '（单封微调）为 PT ABC 推荐。', '固定段落的单封微调生效（模板不变）');
ok($items[9]['text'] === 'IDR 20 jt' && $items[11]['text'] === 'UI · S1 · Law · 2010', '手填项 / 档案字段');
ok($items[12]['lines'][0] === '推荐顾问：Alice' && in_array('c@x.com', $items[12]['lines'], true), '顾问落款');
ok(recruitRecoMissingManual($content) === [] && recruitRecoMissingManual(['manual' => []] + $content) === ['期望薪资'], '必填手填项没填 → 拦导出');
$old = recruitRecoLetterItems(['letter' => ['to' => 'X', 'subject' => 'S', 'intro' => 'I', 'closing' => 'C']], $meta, 'zh', '2026-09-24');
ok(array_column($old, 'kind') === ['title', 'to', 'meta', 'subject', 'para', 'para', 'lines', 'small'], '模板系统前的旧信（没快照）按内置默认渲染，版式不变');
$bin = recruitRecoDocx('letter', $content, $meta + ['lang' => 'zh', 'date' => '2026-09-24']);
ok(strlen($bin) > 1000 && substr($bin, 0, 2) === 'PK', 'Word 按模板导出成功');

echo "\n三、重新生成保留模板 / 手填 / 微调（测试库）\n";
$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = dbNow();
$made = ['cand' => 0, 'proj' => 0, 'job' => 0, 'reco' => 0];
try {
    $pdo->prepare("INSERT INTO recruit_projects (name, kind, status, created_at, updated_at) VALUES ('__tpl', 'internal', 'open', $now, $now)")->execute();
    $made['proj'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recruit_jobs (project_id, title, jd_text, status, jd_rev, created_at, updated_at) VALUES (?, 'HR', 'x', 'open', 1, $now, $now)")->execute([$made['proj']]);
    $made['job'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, status, created_at, updated_at) VALUES ('__tplc', '{}', 1, 'new', $now, $now)")->execute();
    $made['cand'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recruit_recommendations (candidate_id, job_id, lang, status, content_json, created_at, updated_at) VALUES (?, ?, 'zh', 'generating', ?, $now, $now)")
        ->execute([$made['cand'], $made['job'], json_encode(['tpl' => $tpl, 'manual' => ['salary' => 'IDR 20 jt', 'ghost' => 'x'], 'fixed' => ['co' => '微调']], JSON_UNESCAPED_UNICODE)]);
    $made['reco'] = (int)$pdo->lastInsertId();
    $llm = fn($sys, $content) => ['ok' => true, 'data' => ['resume' => ['name' => 'Budi'], 'letter' => ['intro' => '新的 AI 概述']]];
    ok(recruitRecoRun($pdo, $made['reco'], $llm) === 'ready', '重新生成 → ready');
    $c = json_decode((string)$pdo->query("SELECT content_json FROM recruit_recommendations WHERE id={$made['reco']}")->fetchColumn(), true);
    ok($c['letter']['intro'] === '新的 AI 概述' && ($c['tpl']['id'] ?? 0) === 5, 'AI 内容换新，选的模板保留');
    ok($c['manual'] === ['salary' => 'IDR 20 jt'] && $c['fixed'] === ['co' => '微调'], '手填 / 微调保留；模板里没有的键丢掉');
    $pdo->prepare("UPDATE recruit_recommendations SET status='generating', content_json=NULL WHERE id=?")->execute([$made['reco']]);
    recruitRecoRun($pdo, $made['reco'], $llm);
    $c = json_decode((string)$pdo->query("SELECT content_json FROM recruit_recommendations WHERE id={$made['reco']}")->fetchColumn(), true);
    ok(!empty($c['tpl']['blocks']) && $c['tpl']['title'] !== '', '第一次生成：自动套该语言默认模板的快照');

    echo "\n四、标记已发送 → 阶段推到「已推给客户」（只往前推）\n";
    $pdo->prepare("INSERT INTO recruit_candidate_jobs (candidate_id, job_id, origin, stage, created_at, updated_at) VALUES (?, ?, 'ai', 'shortlisted', $now, $now)")
        ->execute([$made['cand'], $made['job']]);
    $lid = (int)$pdo->lastInsertId();
    $stage = fn() => $pdo->query("SELECT stage FROM recruit_candidate_jobs WHERE id=$lid")->fetchColumn();
    ok(recruitRecoAdvanceOnSent($pdo, $made['cand'], $made['job'], 'PT ABC', '邮件发 HR', 1, 'T') === true && $stage() === 'submitted', '初筛通过 → 已推给客户');
    $f = $pdo->query("SELECT stage_before, stage_after, event_code, content FROM recruit_followups WHERE candidate_id={$made['cand']} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok($f === ['stage_before' => 'shortlisted', 'stage_after' => 'submitted', 'event_code' => 'reco_sent', 'content' => 'PT ABC · 邮件发 HR'], '记一条跟进：客户 + 备注，stage_after=submitted（绩效「推荐给客户」按它算）');
    $pdo->exec("UPDATE recruit_candidate_jobs SET stage='interviewing' WHERE id=$lid");
    ok(recruitRecoAdvanceOnSent($pdo, $made['cand'], $made['job'], 'PT ABC', '', 1, 'T') === false && $stage() === 'interviewing', '已在面试 → 不往回拉');
    $pdo->exec("UPDATE recruit_candidate_jobs SET stage='rejected' WHERE id=$lid");
    ok(recruitRecoAdvanceOnSent($pdo, $made['cand'], $made['job'], 'PT ABC', '', 1, 'T') === false && $stage() === 'rejected', '已淘汰 → 不复活');
} finally {
    if ($made['cand']) { $pdo->exec("DELETE FROM recruit_followups WHERE candidate_id={$made['cand']}"); $pdo->exec("DELETE FROM recruit_candidate_jobs WHERE candidate_id={$made['cand']}"); }
    if ($made['reco']) $pdo->exec("DELETE FROM recruit_recommendations WHERE id={$made['reco']}");
    if ($made['cand']) $pdo->exec("DELETE FROM recruit_candidates WHERE id={$made['cand']}");
    if ($made['job']) $pdo->exec("DELETE FROM recruit_jobs WHERE id={$made['job']}");
    if ($made['proj']) $pdo->exec("DELETE FROM recruit_projects WHERE id={$made['proj']}");
    $pdo->exec("DELETE FROM ai_api_usage WHERE scene='recruit_reco' AND biz_id=" . (int)$made['reco']);
}
echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
