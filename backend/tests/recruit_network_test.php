<?php
/**
 * 关系网络 + 公司归类 + 赛道自动生成（测试库集成，__ 前缀造数，finally 清理；假模型不花钱）：php tests/recruit_network_test.php
 * 要守住的：
 *   简历推断：按简历写的行业投票、地点认国家（印尼城市 → 本地，新加坡 → 海外）；低级别不覆盖高级别（人工 / 联网）
 *   AI 快速归类：逐条收下合法的；不认识的公司不写简介；没配 key 本轮停不写库
 *   赛道：方案校验（成员只能是给过的公司、每家只进一个赛道、新赛道至少 2 家）；应用时新建赛道、挂公司，人工定过的不动
 *   关系网络：行业 / 企业 / 候选人 / 学校 焦点都能拼出子图；同事只连时间重叠的；看不到全部的只看自己名下；节点上限生效
 */
$root = dirname(__DIR__);
putenv('RECRUIT_NO_FEEDBACK=1');
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_network.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = dbNow();
$uid = (int)$pdo->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
$uid2 = (int)$pdo->query("SELECT id FROM users WHERE status='active' AND id<>$uid ORDER BY id LIMIT 1")->fetchColumn();
$made = ['cand' => [], 'seg' => []];
$cand = function (string $name, array $exp, array $edu, int $owner) use ($pdo, $now, &$made) {
    $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, status, owner_user_id, owner_user_name, last_received_at, created_at, updated_at)
                   VALUES (?, ?, 1, 'new', ?, 'T', '2026-09-01 00:00:00', $now, $now)")->execute([$name, json_encode(['experience' => $exp, 'education' => $edu], JSON_UNESCAPED_UNICODE), $owner]);
    $id = $made['cand'][] = (int)$pdo->lastInsertId();
    recruitLinkCandidateCompanies($pdo, $id);
    return $id;
};
$co = fn(string $n) => recruitCompanyByKey($pdo, recruitOrgKey($n));
$row = fn(int $id) => $pdo->query("SELECT * FROM recruit_companies WHERE id=$id")->fetch(PDO::FETCH_ASSOC);

try {
    echo "一、简历推断归类\n";
    $a = $cand('__NA', [['company' => '__Nnickel One', 'title' => 'Mine Engineer', 'company_industry' => 'mining_energy', 'location' => 'Morowali, Sulawesi', 'start' => '2020-01', 'end' => 'present', 'is_current' => true],
                        ['company' => '__Nsg Trading', 'title' => 'Sales', 'company_industry' => 'retail_fmcg', 'location' => 'Singapore', 'start' => '2016-01', 'end' => '2019-12']],
                 [['school' => '__Nuniv Tambang', 'level' => 's1', 'major' => 'Mining']], $uid);
    $b = $cand('__NB', [['company' => '__Nnickel One', 'title' => 'Geologist', 'company_industry' => 'mining_energy', 'location' => 'Jakarta', 'start' => '2021-06', 'end' => '2023-01']],
                 [['school' => '__Nuniv Tambang', 'level' => 's1']], $uid2);
    $c = $cand('__NC', [['company' => '__Nnickel One', 'title' => 'HR', 'company_industry' => 'mining_energy', 'start' => '2010-01', 'end' => '2012-01']], [], $uid);
    $n1 = $co('__Nnickel One'); $sg = $co('__Nsg Trading');
    recruitCompanyInferFromResumes($pdo, 2000, [$n1, $sg]);
    $r1 = $row($n1); $r2 = $row($sg);
    ok($r1['industry'] === 'mining_energy' && $r1['country'] === 'ID' && $r1['region'] === 'local' && $r1['class_source'] === 'resume', '简历写 mining ×3、地点在印尼 → 采矿能源 · 印尼本地');
    ok($r1['class_conf'] === 'medium', '≥2 份简历一致 → 置信度 medium');
    ok($r2['country'] === 'SG' && $r2['region'] === 'overseas', '地点 Singapore → 海外 SG');
    $pdo->exec("UPDATE recruit_companies SET industry='manufacturing', class_source='manual' WHERE id=$sg");
    recruitCompanyInferFromResumes($pdo, 2000, [$sg]);
    ok($row($sg)['industry'] === 'manufacturing', '人工定过的，简历推断不覆盖');

    echo "\n二、AI 快速归类（假模型）\n";
    $n2 = $co('__Nnickel One');
    $unconf = fn(array $req) => array_map(fn() => ['ok' => false, 'error_kind' => 'not_configured'], $req);
    $r = recruitCompanyAiClassify($pdo, $unconf, 1, [$n2]);
    ok($r['abort'] === 'not_configured' && $row($n2)['class_source'] === 'resume', '没配 key：本轮停，不写库');
    $fake = function (array $req) {
        $out = [];
        foreach ($req as $i => $q) {
            preg_match_all('/"k":"(c\d+)","name":"([^"]*)"/', $q['content'][0]['text'], $m, PREG_SET_ORDER);
            $out[$i] = ['ok' => true, 'usage' => [], 'data' => ['items' => array_map(fn($x) => ['k' => $x[1], 'is_company' => true, 'industry' => 'mining_energy',
                'segment_id' => 0, 'new_segment' => '镍矿', 'country' => 'ID', 'summary' => ['zh' => '一家镍矿公司'], 'confidence' => stripos($x[2], 'nickel') !== false ? 'medium' : 'low'], $m)]];
        }
        return $out;
    };
    $r = recruitCompanyAiClassify($pdo, $fake, 1, [$n2]);
    $x = $row($n2);
    ok($r['ok'] === 1 && $x['class_source'] === 'ai' && $x['suggest_segment'] === '镍矿' && strpos((string)$x['summary_json'], '镍矿') !== false, 'AI 归类：来源 ai、建议赛道、medium 置信度写简介');
    ok(recruitValidateCoClass(['items' => [['k' => 'c1', 'industry' => 'astrology', 'confidence' => 'high']]], ['c1'])['data'] === [], '编造的行业码不收');
    // 模型答了但漏了这家：标 fail，7 天内不再送（否则每 5 分钟重发同一批头部公司）
    $cand('__NE', [['company' => '__Nfail Co', 'title' => 'Clerk', 'start' => '2020-01', 'end' => '2021-01']], [], $uid);
    $nf = $co('__Nfail Co');
    $empty = fn(array $req) => array_map(fn() => ['ok' => true, 'usage' => [], 'data' => ['items' => []]], $req);
    recruitCompanyAiClassify($pdo, $empty, 1, [$nf]);
    ok($row($nf)['class_conf'] === 'fail', '漏项的公司标 fail');
    ok(recruitCompanyAiClassify($pdo, $empty, 1, [$nf])['companies'] === 0, '标 fail 的 7 天内不再送模型');
    recruitCompanyInferFromResumes($pdo, 2000, [$nf]);
    ok($row($nf)['class_conf'] === 'fail', '挂靠变动但推断结果没变：fail 保留，不会被重新送去 AI');

    echo "\n三、赛道方案（假模型）\n";
    $c2 = $cand('__ND', [['company' => '__Nnickel Two', 'title' => 'Smelter Operator', 'company_industry' => 'mining_energy', 'start' => '2019-01', 'end' => 'present', 'is_current' => true]], [], $uid);
    $n3 = $co('__Nnickel Two');
    recruitCompanyInferFromResumes($pdo, 2000, [$n3]);
    $propose = function (array $req) {
        preg_match_all('/"k":"(c\d+)","name":"(__N[^"]*)"/', $req[0]['content'][0]['text'], $m, PREG_SET_ORDER);
        $ks = array_column($m, 1);
        return [0 => ['ok' => true, 'usage' => [], 'data' => ['reply' => '按镍矿分了一组', 'segments' => [
            ['ref' => 'new', 'name_zh' => '__N镍矿冶炼', 'name_en' => 'Nickel', 'name_id' => 'Nikel', 'industry' => 'mining_energy', 'description' => 'x', 'members' => array_merge($ks, $ks, ['c999'])],
            ['ref' => 'new', 'name_zh' => '__N孤单', 'industry' => 'mining_energy', 'members' => []],
        ]]]];
    };
    $p = recruitSegmentPropose($pdo, $propose, '把采矿分细', 'mining_energy');
    $seg = $p['segments'][0] ?? null;
    ok($seg && count($p['segments']) === 1 && in_array($n2, $seg['members'], true) && in_array($n3, $seg['members'], true)
       && count($seg['members']) === count(array_unique($seg['members'])), '方案：重复 / 编造的成员丢掉、不足 2 家的新赛道丢掉，成员换成公司 id');
    $pdo->exec("UPDATE recruit_companies SET class_source='manual' WHERE id=$n3");
    $ap = recruitSegmentApply($pdo, ['segments' => [$seg]], 'ai');
    $sid = (int)$pdo->query("SELECT id FROM recruit_segments WHERE name_zh='__N镍矿冶炼'")->fetchColumn();
    if ($sid) $made['seg'][] = $sid;
    ok($ap['created'] === 1 && $sid > 0 && (int)$row($n2)['segment_id'] === $sid, '应用：建出新赛道（source=ai）并挂上公司');
    ok($ap['skipped'] >= 1 && (int)$row($n3)['segment_id'] === 0, '人工定过的公司不被挂走');
    $v = recruitValidateSegment(['segments' => [['ref' => 'existing:99999999', 'members' => ['c1']], ['ref' => 'new', 'name_zh' => '__Nx', 'industry' => 'astrology', 'members' => ['c1', 'c2']]]], ['c1', 'c2'], []);
    ok($v !== null && count($v['segments']) === 1 && $v['segments'][0]['industry'] === '', '单个赛道不合法只丢这一个（编造的已有 id），行业码不合法清空，不整份作废');
    ok($v['dropped'] === 1, '丢掉几个赛道要报出来（提示词评测据此判失败）');
    $allBad = fn(array $req) => [0 => ['ok' => true, 'usage' => [], 'data' => ['reply' => 'x', 'segments' => [['ref' => 'existing:99999999', 'members' => ['c1']]]]]];
    $threw = '';
    try { recruitSegmentPropose($pdo, $allBad, '把采矿分细', 'mining_energy'); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
    ok($threw === 'bad_output', '赛道全部不合法 = 输出坏了，不当成空方案');
    $ap2 = recruitSegmentApply($pdo, ['segments' => [$seg]], 'ai');
    ok($ap2['created'] === 0 && $ap2['assigned'] === 0, '重复应用不重复建、不重复挂（幂等）');

    echo "\n四、关系网络\n";
    $g = recruitNetwork($pdo, 'company', (string)$n1, ['school', 'flow'], null);
    $ids = array_column($g['nodes'], 'id');
    ok($g['focus'] === "co:$n1" && in_array("cand:$a", $ids, true) && in_array("cand:$b", $ids, true) && in_array("cand:$c", $ids, true), '企业焦点：在这家待过的人都在');
    ok(in_array('co:' . $sg, $ids, true), '人才流向：A 之前待过的 __Nsg Trading 也出现');
    ok(in_array('sch:' . md5(recruitOrgKey('__Nuniv Tambang')), $ids, true), '叠加学校：校友节点出现');
    $rels = array_count_values(array_column($g['edges'], 'rel'));
    ok(($rels['worked'] ?? 0) >= 4 && ($rels['studied'] ?? 0) === 2, '任职 / 就读边都在');
    $cur = array_values(array_filter($g['edges'], fn($e) => $e['rel'] === 'worked' && $e['source'] === "cand:$a" && $e['target'] === "co:$n1"))[0] ?? [];
    ok(!empty($cur['current']) && $cur['months'] > 60, 'A 在 __Nnickel One：在职、累计 >60 个月');

    $g = recruitNetwork($pdo, 'candidate', (string)$a, [], null);
    $col = array_values(array_filter($g['edges'], fn($e) => $e['rel'] === 'colleague'));
    $colIds = array_map(fn($e) => $e['target'], $col);
    ok(in_array("cand:$b", $colIds, true) && !in_array("cand:$c", $colIds, true), '同事只连时间重叠的（B 2021–2023 与 A 重叠；C 2010–2012 不重叠）');
    ok(in_array("cand:$b", array_column($g['nodes'], 'id'), true), '校友也在（A、B 同校）');

    $g = recruitNetwork($pdo, 'industry', 'mining_energy', [], $uid);
    $cands = array_values(array_filter($g['nodes'], fn($n) => $n['type'] === 'candidate' && str_starts_with((string)$n['label'], '__N')));
    ok($cands && !in_array("cand:$b", array_column($g['nodes'], 'id'), true), '只看自己名下：别人名下的 B 不出现');
    $g = recruitNetwork($pdo, 'school', recruitOrgKey('__Nuniv Tambang'), [], null);
    ok(count(array_filter($g['nodes'], fn($n) => $n['type'] === 'candidate')) === 2, '学校焦点：两位校友');
    $g = recruitNetwork($pdo, 'industry', 'mining_energy', ['school'], null, 50);
    ok(count($g['nodes']) <= 50, '节点上限生效（' . count($g['nodes']) . ' ≤ 50）');
    // 丝线图：一人一行 学校 / 上一个行业 / 现在的行业
    $th = array_column(recruitNetworkThreads($pdo, null, ['mining_energy'])['rows'], null, 'id');
    ok(isset($th[$a]) && $th[$a]['cur'] === 'mining_energy' && $th[$a]['prev'] === 'manufacturing' && $th[$a]['current'], '丝线图：A 现在采矿能源、上一个制造（__Nsg 被人工改成制造）');
    ok($th[$a]['school_key'] === recruitOrgKey('__Nuniv Tambang'), '丝线图：学校取最近一所');
    ok(isset($th[$c]) && $th[$c]['prev'] === '', '只有一段经历：上一个行业为空（页面显示「第一份工作」）');
    ok(!isset(array_column(recruitNetworkThreads($pdo, $uid, ['mining_energy'])['rows'], null, 'id')[$b]), '丝线图只看自己名下：别人名下的 B 不出现');
    ok(!array_filter(recruitNetworkThreads($pdo, null, ['banking_finance'])['rows'], fn($r) => $r['id'] === $a), '按行业筛：上一个 / 现在都不在该行业的人不出现');
    $threw = false;
    try { recruitNetwork($pdo, 'industry', 'astrology', [], null); } catch (InvalidArgumentException $e) { $threw = true; }
    ok($threw, '非法行业码拒绝');
    $s = recruitNetworkSearch($pdo, '__Nnickel', null);
    ok(count(array_filter($s, fn($x) => $x['type'] === 'company')) >= 2, '搜索能搜到企业');
    $sch = array_values(array_filter(recruitNetworkSearch($pdo, '__Nuniv', $uid), fn($x) => $x['type'] === 'school'))[0] ?? null;
    ok($sch && $sch['n'] === 1, '只看自己名下：学校搜索的人数不含别人名下的校友');
    $g = recruitNetwork($pdo, 'company', (string)$n1, [], $uid);
    $heat = array_values(array_filter($g['nodes'], fn($n) => $n['id'] === "co:$n1"))[0]['heat'] ?? -1;
    ok($heat === 2, "只看自己名下：公司人数只数自己的（A、C = 2，实际 {$heat}）");
} finally {
    foreach ($made['cand'] as $x) {
        $pdo->exec("DELETE FROM recruit_candidate_companies WHERE candidate_id=$x");
        $pdo->exec("DELETE FROM recruit_candidate_schools WHERE candidate_id=$x");
        $pdo->exec("DELETE FROM recruit_candidates WHERE id=$x");
    }
    foreach ($pdo->query("SELECT id FROM recruit_companies WHERE name LIKE '\_\_N%'")->fetchAll(PDO::FETCH_COLUMN) as $x) {
        $pdo->exec("DELETE FROM recruit_company_aliases WHERE company_id=" . (int)$x);
        $pdo->exec("DELETE FROM recruit_companies WHERE id=" . (int)$x);
    }
    $pdo->exec("DELETE FROM recruit_segments WHERE name_zh LIKE '\_\_N%'");
}
echo $fails === 0 ? "\n全部通过\n" : "\n失败 $fails 项\n";
exit($fails === 0 ? 0 : 1);
