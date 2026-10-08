<?php
/**
 * 人才关联测试：纯函数 + 测试库集成（假向量，自清理）。php tests/recruit_related_test.php
 * 要守住的：公司/学校名归一（PT/Tbk/有限公司/括号/标点）；太泛的名字不参与；同期判断；
 * 前同事按公司 + 同期排在前、只在自己名下找（recruit 权限）；其他在招职位只看职位招聘中 + 项目进行中、标出已关联。
 */

$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_match.php';
require_once $root . '/includes/recruit_related.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

echo "一、名字归一\n";
ok(recruitOrgKey('PT. Bank Mandiri (Persero) Tbk') === 'bank mandiri', 'PT. / (Persero) / Tbk 去掉');
ok(recruitOrgKey('pt bank mandiri tbk') === recruitOrgKey('PT Bank Mandiri'), '大小写、后缀不同 → 同一家');
ok(recruitOrgKey('PT XL-Axiata') === 'xl axiata', '连字符当空格');
ok(recruitOrgKey('阿里巴巴（中国）网络技术有限公司') === '阿里巴巴 网络技术', '中文：括号地区、有限公司去掉');
ok(recruitOrgKey('Freelance') === '' && recruitOrgKey('Wiraswasta') === '' && recruitOrgKey('PT A') === '' && recruitOrgKey('PT 123') === '', '自由职业 / 单字 / 纯数字 → 不参与关联');
ok(recruitOrgKey('PT BCA') === 'bca' && recruitOrgKey('XL') === 'xl', 'BCA / XL 这种缩写本身是大雇主，照常关联（2026-09-25 起下限 2 个字）');
ok(recruitOrgKey('Universitas Indonesia') === 'universitas indonesia', '学校名照常');

echo "\n二、同期\n";
$as = '2026-09-24';
ok(recruitPeriodsOverlap(['start' => '2019-03', 'end' => '2021-06'], ['start' => '2020-01', 'end' => '2022-01'], $as), '2019-03~2021-06 与 2020-01~2022-01 重叠');
ok(!recruitPeriodsOverlap(['start' => '2015-01', 'end' => '2017-12'], ['start' => '2018-01', 'end' => '2020-01'], $as), '首尾相接不算重叠');
ok(recruitPeriodsOverlap(['start' => '2023-01', 'end' => '', 'is_current' => true], ['start' => '2025-05', 'end' => 'present'], $as), '都在职 → 重叠');
ok(!recruitPeriodsOverlap(['start' => '', 'end' => ''], ['start' => '2020-01', 'end' => '2021-01'], $as), '缺开始日期 → 不算同期');

echo "\n三、测试库集成\n";
$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = dbNow();
$made = ['cand' => [], 'job' => [], 'proj' => []];
try {
    $cand = function (string $name, array $exp, array $edu, int $owner) use ($pdo, $now, &$made) {
        $p = ['experience' => $exp, 'education' => $edu];
        $search = mb_strtolower(implode(' ', array_merge([$name], array_column($exp, 'company'), array_column($edu, 'school'))));
        $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, status, owner_user_id, search_text, latest_school, created_at, updated_at)
                       VALUES (?, ?, 1, 'new', ?, ?, ?, $now, $now)")
            ->execute([$name, json_encode($p, JSON_UNESCAPED_UNICODE), $owner, $search, $edu[0]['school'] ?? '']);
        return $made['cand'][] = (int)$pdo->lastInsertId();
    };
    $me = $cand('__Me', [['company' => 'PT Medika Alat Tbk', 'start' => '2019-01', 'end' => '2022-12', 'title' => 'Sales'],
                        ['company' => 'Freelance', 'start' => '2023-01', 'end' => 'present']],
                [['school' => 'Universitas Zzqx Test', 'start' => '2012-09', 'end' => '2016-07']], 91001);
    $coll = $cand('__Colleague', [['company' => 'PT. MEDIKA ALAT', 'start' => '2021-03', 'end' => '2024-01', 'title' => 'Engineer']], [], 91001);
    $old = $cand('__OldTimer', [['company' => 'Medika Alat', 'start' => '2010-01', 'end' => '2015-01', 'title' => 'Admin']], [], 91001);
    $alum = $cand('__Alumni', [], [['school' => 'universitas zzqx test', 'start' => '2014-09', 'end' => '2018-07']], 91001);
    $other = $cand('__OtherOwner', [['company' => 'PT Medika Alat', 'start' => '2020-01', 'end' => '2021-01']], [], 91002);
    $cand('__FreelanceOnly', [['company' => 'Freelance', 'start' => '2023-01', 'end' => 'present']], [], 91001);

    $rows = recruitRelatedPeople($pdo, $me, null, 30);
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    ok(in_array($coll, $ids, true) && in_array($old, $ids, true) && in_array($alum, $ids, true) && in_array($other, $ids, true), '同公司（不同写法）/ 校友都找到');
    ok(!in_array($me, $ids, true), '不含自己');
    ok(count(array_filter($rows, fn($r) => $r['name'] === '__FreelanceOnly')) === 0, '只同为 Freelance 的不算同事');
    $pos = array_flip($ids);
    ok($pos[$coll] < $pos[$old] && $pos[$old] < $pos[$alum], '排序：同期同事 → 同公司不同期 → 校友');
    $byId = array_column($rows, null, 'id');
    ok($byId[$coll]['relations'][0]['overlap'] === true && $byId[$old]['relations'][0]['overlap'] === false, '同期标记正确');
    ok($byId[$alum]['relations'][0]['kind'] === 'school' && $byId[$alum]['relations'][0]['overlap'] === true, '校友 + 在读时间重叠');
    $scoped = array_map(fn($r) => (int)$r['id'], recruitRelatedPeople($pdo, $me, 91001, 30));
    ok(!in_array($other, $scoped, true) && in_array($coll, $scoped, true), '只看自己名下：别人名下的不出现');

    // 其他在招职位：假向量——job A 与他同方向，job B 正交；关闭项目里的职位不出现
    $model = (string)recruitSemConfig($pdo, true)['model'];
    $basis = function (int $k) { $v = array_fill(0, RECRUIT_EMBED_DIMS, 0.0); $v[$k] = 1.0; return $v; };
    $store = recruitVectorStore($pdo);
    $proj = function (string $status) use ($pdo, $now, &$made) {
        $pdo->prepare("INSERT INTO recruit_projects (name, kind, status, created_at, updated_at) VALUES (?, 'client', ?, $now, $now)")->execute(["__rel_$status", $status]);
        return $made['proj'][] = (int)$pdo->lastInsertId();
    };
    $job = function (int $pid, string $title, int $k) use ($pdo, $now, &$made, $store, $model, $basis) {
        $pdo->prepare("INSERT INTO recruit_jobs (project_id, title, jd_text, status, jd_rev, vec_rev, created_at, updated_at) VALUES (?, ?, 'x', 'open', 1, 1, $now, $now)")
            ->execute([$pid, $title]);
        $id = $made['job'][] = (int)$pdo->lastInsertId();
        foreach (RECRUIT_FACETS as $f) $store->upsert('job', $id, $f, $model, $basis($k), "t$id$f");
        return $id;
    };
    foreach (RECRUIT_FACETS as $f) $store->upsert('cand', $me, $f, $model, $basis(7), "c$f");
    $pOpen = $proj('open'); $pClosed = $proj('closed');
    $jA = $job($pOpen, '__Medical Sales', 7);
    $jB = $job($pOpen, '__Unrelated', 9);
    $jC = $job($pClosed, '__Closed Project Job', 7);
    $pdo->prepare("INSERT INTO recruit_candidate_jobs (candidate_id, job_id, origin, stage, ai_score, created_at, updated_at) VALUES (?, ?, 'ai', 'shortlisted', 4.5, $now, $now)")
        ->execute([$me, $jA]);
    $r = recruitRelatedJobs($pdo, $me, 50);
    $jids = array_map(fn($x) => (int)$x['id'], $r['rows']);
    ok($r['has_vector'] && ($jids[0] ?? 0) === $jA, '最相关的职位排第一');
    ok(!in_array($jC, $jids, true), '项目已关闭的职位不出现');
    $ja = $r['rows'][0];
    ok($ja['linked_stage'] === 'shortlisted' && (float)$ja['linked_score'] === 4.5, '已关联的标出阶段与分数');
    $jb = array_values(array_filter($r['rows'], fn($x) => (int)$x['id'] === $jB))[0] ?? null;
    ok($jb && $jb['linked_stage'] === '' && $jb['score'] < $ja['score'], '未关联的可加入、相关度更低');
    ok(recruitRelatedJobs($pdo, $coll)['has_vector'] === false, '没有向量 → has_vector=false，页面提示');
} finally {
    if ($made['cand']) {
        $ids = implode(',', $made['cand']);
        $pdo->exec("DELETE FROM recruit_candidate_jobs WHERE candidate_id IN ($ids)");
        $pdo->exec("DELETE FROM recruit_vectors WHERE owner_type='cand' AND owner_id IN ($ids)");
        $pdo->exec("DELETE FROM recruit_candidates WHERE id IN ($ids)");
    }
    if ($made['job']) {
        $ids = implode(',', $made['job']);
        $pdo->exec("DELETE FROM recruit_vectors WHERE owner_type='job' AND owner_id IN ($ids)");
        $pdo->exec("DELETE FROM recruit_jobs WHERE id IN ($ids)");
    }
    if ($made['proj']) $pdo->exec("DELETE FROM recruit_projects WHERE id IN (" . implode(',', $made['proj']) . ")");
}

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
