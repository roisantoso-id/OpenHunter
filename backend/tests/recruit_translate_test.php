<?php
/**
 * 简历 / 档案翻译：php tests/recruit_translate_test.php（假模型，测试库自清理）
 * 要守住的：只送给人读的句子（人名 / 公司 / 学校 / 日期 / 电话不送）；按路径写回、结构不变；
 * 模型漏键保留原文、编造的键丢掉；工作内容 1500 字完整翻、不截断；失败落 failed 可重试。
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_translate.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

$long = "• Lead HRGA operations for site workforce\n• " . str_repeat('Ensure full compliance with labor law. ', 30);
$p = ['person' => ['full_name' => 'Andreas Sihotang'], 'contacts' => ['phones' => ['+62812']], 'headline' => 'HRGA Supervisor',
      'experience' => [['title' => 'Supervisor HRGA', 'company' => 'PT Marina Bara Lestari', 'start' => '2022-01', 'end' => 'present', 'description' => $long]],
      'education' => [['school' => 'Universitas X', 'major' => 'Manajemen', 'start' => '2000']],
      'skills' => ['Payroll', '2020'], 'expected_salary' => ['text' => 'IDR 15 juta']];

echo "一、挑字段 / 写回\n";
$pk = recruitTransPick($p);
ok(isset($pk['experience.0.description'], $pk['experience.0.title'], $pk['education.0.major'], $pk['skills.0'], $pk['expected_salary.text']), '职位 / 工作内容 / 专业 / 技能 / 期望薪资要翻');
ok(!array_filter(array_keys($pk), fn($k) => preg_match('/full_name|company|school|phones|start|end/', $k)), '人名 / 公司 / 学校 / 电话 / 日期不送');
ok(!isset($pk['skills.1']), '纯数字不送');
$t = ['experience.0.description' => '• 负责现场员工的 HRGA 工作', 'headline' => 'HRGA 主管', 'experience.9.title' => '编造的', 'skills.0' => ['x']];
$m = recruitTransMerge($p, $pk, $t);
ok($m['headline'] === 'HRGA 主管' && str_starts_with($m['experience'][0]['description'], '• 负责'), '按路径写回');
ok($m['experience'][0]['title'] === 'Supervisor HRGA' && $m['skills'][0] === 'Payroll', '模型漏掉 / 给错类型的键保留原文');
ok(count($m['experience']) === 1 && $m['experience'][0]['company'] === 'PT Marina Bara Lestari' && $m['experience'][0]['start'] === '2022-01', '编造的路径丢掉，结构 / 公司 / 日期不变');

echo "\n二、整条跑（假模型）\n";
$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = dbNow();
$cid = 0; $tid = 0;
try {
    $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, status, created_at, updated_at) VALUES ('__trans', ?, 1, 'new', $now, $now)")
        ->execute([json_encode($p, JSON_UNESCAPED_UNICODE)]);
    $cid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recruit_translations (owner_type, owner_id, lang, status, created_at, updated_at) VALUES ('candidate', ?, 'zh', 'generating', $now, $now)")->execute([$cid]);
    $tid = (int)$pdo->lastInsertId();
    $seen = null;
    $llm = function ($sys, $content) use (&$seen) {
        $seen = $content[0]['text'];
        preg_match('/\{.*\}/s', $seen, $mm);
        $in = json_decode($mm[0], true);
        return ['ok' => true, 'data' => ['t' => array_map(fn($v) => '【译】' . $v, $in)]];
    };
    ok(recruitTranslateRun($pdo, $tid, $llm) === 'ready', '翻好 → ready');
    ok(strpos($seen, 'Andreas') === false && strpos($seen, '+62812') === false, '送给模型的内容里没有姓名、电话');
    $out = json_decode((string)$pdo->query("SELECT content_json FROM recruit_translations WHERE id=$tid")->fetchColumn(), true);
    ok(mb_strlen($out['experience'][0]['description']) >= mb_strlen($long), '工作内容完整翻译，不截断');
    ok($out['person']['full_name'] === 'Andreas Sihotang', '原样保留不翻的字段');
    ok(recruitTranslateRun($pdo, $tid, fn() => ['ok' => false, 'error_kind' => 'rejected', 'error' => 'HTTP 403']) === 'failed'
       && $pdo->query("SELECT status FROM recruit_translations WHERE id=$tid")->fetchColumn() === 'failed', '模型失败 → failed（页面可重试）');
} finally {
    if ($tid) $pdo->exec("DELETE FROM recruit_translations WHERE id=$tid");
    if ($cid) $pdo->exec("DELETE FROM recruit_candidates WHERE id=$cid");
    $pdo->exec("DELETE FROM ai_api_usage WHERE scene='recruit_translate' AND biz_type='recruit_candidate' AND biz_id=$cid");
}
echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
