<?php
/**
 * 人才库跟进情况 / 排序 / 团队进度（测试库只读）：php tests/recruit_follow_test.php
 * 要守住的：跟进情况三态（人工 / 仅 AI / 未跟进）互斥且合起来 = 全部人；筛选 follow= 与逐人按原定义算的一致；
 * 「今日已跟进」= 今天写过人工跟进的人；每个排序字段升降序都能跑、空值排最后、非白名单字段不生效；
 * 团队进度每行 人工+仅AI+未跟进 = 名下总数。
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ids = function (array $f) use ($pdo): array {
    [$w, $a] = recruitCandidateFilterSql($f);
    $st = $pdo->prepare("SELECT c.id FROM recruit_candidates c WHERE $w");
    $st->execute($a);
    $r = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    sort($r);
    return $r;
};

echo "一、跟进情况三态\n";
$all = $ids([]);
$h = $ids(['follow' => 'human']); $ai = $ids(['follow' => 'ai']); $no = $ids(['follow' => 'none']);
ok(count($h) + count($ai) + count($no) === count($all) && !array_intersect($h, $ai) && !array_intersect($h, $no) && !array_intersect($ai, $no),
   '三态互斥，合起来 = 全部 ' . count($all) . ' 人（人工 ' . count($h) . ' / 仅AI ' . count($ai) . ' / 未跟进 ' . count($no) . '）');
// 逐人按原定义算：写过跟进 或 人动过匹配 → 人工；否则有匹配 → 仅 AI
$noted = array_flip(array_map('intval', $pdo->query("SELECT DISTINCT candidate_id FROM recruit_followups WHERE kind<>'system'")->fetchAll(PDO::FETCH_COLUMN)));
$touched = array_flip(array_map('intval', $pdo->query("SELECT DISTINCT candidate_id FROM recruit_candidate_jobs WHERE origin='manual' OR stage NOT IN ('suggested','removed')")->fetchAll(PDO::FETCH_COLUMN)));
$linked = array_flip(array_map('intval', $pdo->query("SELECT DISTINCT candidate_id FROM recruit_candidate_jobs WHERE stage<>'removed'")->fetchAll(PDO::FETCH_COLUMN)));
$expH = array_values(array_filter($all, fn($i) => isset($noted[$i]) || isset($touched[$i])));
$expA = array_values(array_filter($all, fn($i) => !isset($noted[$i]) && !isset($touched[$i]) && isset($linked[$i])));
ok($h === $expH, 'follow=human 与逐人判定一致');
ok($ai === $expA, 'follow=ai 与逐人判定一致');
ok(!array_diff(array_keys($noted), $h), '写过人工跟进的人全部算「人工跟进」');

echo "\n二、今日已跟进\n";
$today = date('Y-m-d');
$exp = array_map('intval', $pdo->query("SELECT DISTINCT candidate_id FROM recruit_followups WHERE kind<>'system' AND created_at>='$today 00:00:00' ORDER BY candidate_id")->fetchAll(PDO::FETCH_COLUMN));
ok($ids(['noted_from' => $today]) === $exp, 'noted_from=今天 → 今天写过跟进的 ' . count($exp) . ' 人');
$by = (int)$pdo->query("SELECT created_by FROM recruit_followups WHERE kind<>'system' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($by) {
    $exp = array_map('intval', $pdo->query("SELECT DISTINCT candidate_id FROM recruit_followups WHERE kind<>'system' AND created_by=$by ORDER BY candidate_id")->fetchAll(PDO::FETCH_COLUMN));
    ok($ids(['noted_by' => $by]) === $exp, "noted_by=$by → 这个人跟进过的 " . count($exp) . ' 人（不限归属）');
}

echo "\n三、排序\n";
ok(recruitCandidateOrderSql([]) === null && recruitCandidateOrderSql(['sort' => 'c.id; DROP']) === null, '没选 / 非白名单 → null（用默认顺序）');
foreach (['id', 'years', 'edu', 'score', 'status', 'follow', 'next', 'received', 'owner'] as $k) {
    foreach (['asc', 'desc'] as $d) {
        $o = recruitCandidateOrderSql(['sort' => $k, 'order' => $d]);
        try { $n = count($pdo->query("SELECT c.id FROM recruit_candidates c ORDER BY $o")->fetchAll()); $good = $n === count($all); }
        catch (PDOException $e) { $good = false; echo '    ' . $e->getMessage() . "\n"; }
        ok($good, "sort=$k $d 可执行");
    }
}
$o = recruitCandidateOrderSql(['sort' => 'follow', 'order' => 'asc']);
$seq = $pdo->query("SELECT c.id, (SELECT MAX(created_at) FROM recruit_followups f WHERE f.candidate_id=c.id AND f.kind<>'system') at FROM recruit_candidates c ORDER BY $o")->fetchAll(PDO::FETCH_ASSOC);
$firstNull = null; $bad = false; $prev = null;
foreach ($seq as $i => $r) {
    if ($r['at'] === null) { $firstNull = $firstNull ?? $i; continue; }
    if ($firstNull !== null || ($prev !== null && $r['at'] < $prev)) $bad = true;
    $prev = $r['at'];
}
ok(!$bad, '按最近跟进升序：有值的递增，没跟进过的排最后');

echo "\n四、团队进度\n";
$rows = recruitTeamProgress($pdo, date('Y-m-d', strtotime('-6 days')));
ok(count($rows) >= 0, count($rows) . ' 行（空库为 0，有数据的库 > 0）');
$sum = 0; $okRow = true;
foreach ($rows as $r) { $sum += $r['owned']; if ($r['human'] + $r['ai'] + $r['none'] !== $r['owned']) $okRow = false; }
ok($okRow, '每行 人工 + 仅AI + 未跟进 = 名下人数');
ok($sum === count($all), '各行名下人数合计 = 全部（含待认领）');

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
