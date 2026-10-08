<?php
/**
 * 企业库（测试库集成，造 __ 前缀数据，finally 清理；假模型 / 假搜索，不花钱）：php tests/recruit_company_test.php
 * 要守住的：
 *   挂靠：公司名归一后命中别名；没见过的自动建档；忽略名单不建；改别名后版本 +1、重挂到新企业
 *   口径：在职 / 已离职 / 待得久 / 各职能 人数 = 点开的名单行数（同一人多段只算一次）；看不到全部的只算自己名下
 *   职能归类：合格输出落库；不合格记次数、3 次落 other；没配 key 不记次数；人工改过的 AI 不覆盖
 *   资料补全：same → 写字段、国家定本地/海外、没出处的高管不收；人工锁定字段不覆盖；ambiguous → 失败待确认；没配 key 回队列
 *   人才库筛选：按企业 / 赛道 / 职能 能筛到人
 */
$root = dirname(__DIR__);
putenv('RECRUIT_NO_FEEDBACK=1');
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_company.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!recruitCompanyReady($pdo)) { echo "✗ 先跑 create_recruit_companies_20260925.php --apply\n"; exit(1); }
$now = dbNow();
$made = ['cand' => [], 'seg' => []];
$uid = (int)$pdo->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
$uid2 = (int)$pdo->query("SELECT id FROM users WHERE status='active' AND id<>$uid ORDER BY id LIMIT 1")->fetchColumn();
$verBefore = recruitCompanySetting($pdo, 'recruit.company.ver', '');

$cand = function (string $name, array $exp, int $owner) use ($pdo, $now, &$made) {
    $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, status, owner_user_id, owner_user_name, last_received_at, created_at, updated_at)
                   VALUES (?, ?, 1, 'new', ?, 'T', '2026-09-01 00:00:00', $now, $now)")->execute([$name, json_encode(['experience' => $exp], JSON_UNESCAPED_UNICODE), $owner]);
    return $made['cand'][] = (int)$pdo->lastInsertId();
};
$coId = fn(string $n) => recruitCompanyByKey($pdo, recruitOrgKey($n));
$people = function (int $co, string $b, string $f = '', string $s = '', ?int $scope = null) use ($pdo) {
    [$w, $a] = recruitCompanyBucketSql($pdo, $co, $b, $f, $s, $scope);
    $st = $pdo->prepare("SELECT c.id FROM recruit_candidates c WHERE $w");
    $st->execute($a);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};

try {
    echo "一、建档与挂靠\n";
    // 手动建一家标杆：正式名 + 别名
    $pdo->prepare("INSERT INTO recruit_companies (name, org_key, source, enrich_status, created_at, updated_at) VALUES ('__Tglow Beauty', ?, 'manual', 'queued', $now, $now)")
        ->execute([recruitOrgKey('__Tglow Beauty')]);
    $glow = (int)$pdo->lastInsertId();
    foreach (['__Tglow Beauty', '__Tglow Beauty Indonesia'] as $al) {
        $pdo->prepare("INSERT INTO recruit_company_aliases (company_id, alias, alias_key, created_at) VALUES (?, ?, ?, $now)")->execute([$glow, $al, recruitOrgKey($al)]);
    }
    $pdo->prepare(dbInsertIgnore() . " recruit_company_ignored (org_key, sample, created_at) VALUES (?, '__Tfreelance studio', $now)")->execute([recruitOrgKey('__Tfreelance studio')]);
    $a = $cand('__A', [['company' => 'PT. __Tglow Beauty Indonesia Tbk', 'title' => '__tSales Manager', 'start' => '2020-01', 'end' => 'present', 'is_current' => true],
                        ['company' => '__Tacme Food', 'title' => '__tSales Executive', 'start' => '2017-01', 'end' => '2019-12']], $uid);
    $b = $cand('__B', [['company' => '__Tglow Beauty', 'title' => '__tBrand Marketing', 'start' => '2019-03', 'end' => '2021-02'],
                        ['company' => '__Tglow Beauty', 'title' => '__tSales Manager', 'start' => '2016-01', 'end' => '2018-12'],
                        ['company' => '__Tfreelance studio', 'title' => '__tDesigner', 'start' => '2021-03', 'end' => 'present', 'is_current' => true]], $uid2);
    $c = $cand('__C', [['company' => '__Tglow beauty', 'title' => '__tSales Manager', 'start' => '2015-01', 'end' => '2019-12']], $uid);
    foreach ([$a, $b, $c] as $x) recruitLinkCandidateCompanies($pdo, $x);
    ok($coId('PT __Tglow Beauty Indonesia') === $glow, '「PT. …Indonesia Tbk」归一后命中别名');
    $acme = $coId('__Tacme Food');
    ok($acme > 0 && $pdo->query("SELECT source FROM recruit_companies WHERE id=$acme")->fetchColumn() === 'auto', '没见过的公司自动建档（source=auto）');
    ok($coId('__Tfreelance studio') === 0, '忽略名单里的不建档');
    ok((int)$pdo->query("SELECT COUNT(*) FROM recruit_candidate_companies WHERE candidate_id=$b")->fetchColumn() === 2, '忽略的那段不挂，其余两段都挂');

    echo "\n二、口径：人数 = 点开的名单\n";
    // 职能：先人工定（source=manual）
    recruitSetTitleFunction($pdo, recruitTitleKey('__tSales Manager'), 'sales', 'manager', $uid);
    recruitSetTitleFunction($pdo, recruitTitleKey('__tBrand Marketing'), 'marketing', 'staff', $uid);
    $cnt = recruitCompanyCounts($pdo, [$glow])[$glow];
    ok($cnt['all'] === 3 && $cnt['current'] === 1 && $cnt['former'] === 2, "待过 3 / 在职 1 / 已离职 2（B 两段只算一人）：" . json_encode($cnt));
    ok($cnt['long'] === 3, '待得久（累计 ≥36 月）：A 2020-01→2026-09、B 24+36、C 60 个月都算');
    $bad = [];
    foreach (['all', 'current', 'former', 'long'] as $bk) if (count($people($glow, $bk)) !== $cnt[$bk]) $bad[] = $bk;
    foreach ($cnt['funcs'] as $f => $n) if (count($people($glow, 'all', $f)) !== $n) $bad[] = "func:$f";
    ok(!$bad, '每个桶 / 每个职能的人数 = 下钻行数' . ($bad ? '，不一致：' . implode(',', $bad) : ''));
    ok(($cnt['funcs']['sales'] ?? 0) === 3 && ($cnt['funcs']['marketing'] ?? 0) === 1, '职能分布：销售 3 · 市场 1');
    $map = recruitCompanyTalentMap($pdo, $glow);
    ok(($map['sales']['manager'] ?? 0) === 3, '人才地图：销售 × 经理 3 人');
    $sc = recruitCompanyCounts($pdo, [$glow], $uid)[$glow];
    ok($sc['all'] === 2 && count($people($glow, 'all', '', '', $uid)) === 2, '只看自己名下：计数与名单都只剩 2 人');

    echo "\n三、改别名后重挂\n";
    $v0 = recruitCompanyVer($pdo);
    $pdo->prepare("UPDATE recruit_company_aliases SET company_id=? WHERE alias_key=?")->execute([$glow, recruitOrgKey('__Tacme Food')]);
    recruitCompanyBumpVer($pdo);
    ok(recruitCompanyVer($pdo) === $v0 + 1, '改别名 → 企业库版本 +1');
    $st = $pdo->prepare("SELECT COUNT(*) FROM recruit_candidates WHERE id=? AND company_rev<>?");
    $st->execute([$a, recruitCompanyVer($pdo)]);
    ok((int)$st->fetchColumn() === 1, 'A 挂靠落后，会被下一轮重挂');
    recruitLinkCandidateCompanies($pdo, $a);
    ok((int)$pdo->query("SELECT COUNT(DISTINCT company_id) FROM recruit_candidate_companies WHERE candidate_id=$a")->fetchColumn() === 1, '重挂后 A 两段都归到同一家');

    echo "\n四、职能归类（假模型）\n";
    $pdo->prepare("DELETE FROM recruit_title_functions WHERE title_key=?")->execute([recruitTitleKey('__tSales Executive')]);
    $fake = function (callable $answer) {
        return function (array $req) use ($answer) {
            $out = [];
            foreach ($req as $i => $r) {
                preg_match_all('/"k":"(t\d+)","title":"([^"]*)"/', $r['content'][0]['text'], $m, PREG_SET_ORDER);
                $out[$i] = $answer($m);
            }
            return $out;
        };
    };
    $good = $fake(fn($m) => ['ok' => true, 'usage' => [], 'elapsed' => 0.1, 'data' => ['items' => array_map(fn($x) => ['k' => $x[1],
        'function' => stripos($x[2], 'sales') !== false ? 'sales' : 'other', 'seniority' => 'staff'], $m)]]);
    $notConf = $fake(fn($m) => ['ok' => false, 'error_kind' => 'not_configured', 'error' => 'x']);
    $r = recruitFuncBatch($pdo, $notConf, 1, $made['cand']);
    $st = $pdo->prepare("SELECT attempts FROM recruit_title_functions WHERE title_key=?");
    $st->execute([recruitTitleKey('__tSales Executive')]);
    ok($r['abort'] === 'not_configured' && $st->fetchColumn() === false, '没配 key：不记失败次数，本轮停');
    $badOut = $fake(fn($m) => ['ok' => true, 'usage' => [], 'data' => ['items' => [['k' => 't1', 'function' => 'astronaut', 'seniority' => 'staff']]]]);
    recruitFuncBatch($pdo, $badOut, 1, $made['cand']);
    $st->execute([recruitTitleKey('__tSales Executive')]);
    ok((int)$st->fetchColumn() === 1, '输出不合格（漏回 / 枚举外）：记 1 次失败，下轮再试');
    // 同批里一条坏了：其余照收，只有坏的那条记次数
    $d = $cand('__D', [['company' => '__Tzeta Corp', 'title' => '__tHR Officer', 'start' => '2022-01', 'end' => 'present', 'is_current' => true]], $uid);
    recruitLinkCandidateCompanies($pdo, $d);
    $partial = $fake(fn($m) => ['ok' => true, 'usage' => [], 'data' => ['items' => array_values(array_filter(array_map(fn($x) => stripos($x[2], 'Executive') !== false ? null
        : ['k' => $x[1], 'function' => 'sales', 'seniority' => 'staff'], $m)))]]);
    recruitFuncBatch($pdo, $partial, 1, $made['cand']);
    $st->execute([recruitTitleKey('__tSales Executive')]);
    $ex = $pdo->prepare("SELECT COUNT(*) FROM recruit_title_functions WHERE title_sample LIKE '!_!_t%' ESCAPE '!' AND source='ai'");
    $ex->execute();
    ok((int)$st->fetchColumn() === 2 && (int)$ex->fetchColumn() >= 1, '同批一条坏了：其余照收（source=ai），坏的那条记第 2 次');
    recruitFuncBatch($pdo, $good, 1, $made['cand']);
    $row = $pdo->prepare("SELECT job_function, source FROM recruit_title_functions WHERE title_key=?");
    $row->execute([recruitTitleKey('__tSales Executive')]);
    $x = $row->fetch(PDO::FETCH_ASSOC);
    ok($x['job_function'] === 'sales' && $x['source'] === 'ai', '合格输出落库（source=ai）');
    $row->execute([recruitTitleKey('__tBrand Marketing')]);
    ok($row->fetch(PDO::FETCH_ASSOC)['source'] === 'manual', '人工改过的不被 AI 覆盖');
    ok(recruitValidateFunc(['items' => [['k' => 't1', 'function' => 'sales', 'seniority' => 'staff']]], ['t1', 't2'])['ok'] === false, '漏回一个 → 不合格');

    echo "\n五、资料补全（假搜索 / 假抓取 / 假模型）\n";
    $pdo->exec("INSERT INTO recruit_segments (name_zh, name_en, name_id, industry, sort, active, created_at) VALUES ('__美妆', '__Beauty', '__Kecantikan', 'retail_fmcg', 0, 1, $now)");
    $seg = $made['seg'][] = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE recruit_companies SET hq_city='手填城市', locked_fields=? WHERE id=?")->execute([json_encode(['hq_city' => 1]), $glow]);
    $io = fn(array $data) => [
        'search' => fn(string $q) => [['title' => 'Glow', 'url' => 'https://glow.example.com/about-us', 'content' => 'Glow Beauty is a cosmetics company'],
                                       ['title' => 'LinkedIn', 'url' => 'https://www.linkedin.com/company/glow', 'content' => '1001-5000 employees']],
        'scrape' => fn(string $u) => "# About\nGlow Beauty, headquartered in Seoul.",
        'llm' => fn(array $req) => [0 => ['ok' => true, 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 300], 'elapsed' => 1, 'data' => $data]],
    ];
    $r = recruitCompanyEnrichOne($pdo, $glow, $io(['match' => 'same', 'website' => 'https://glow.example.com', 'country' => 'KR', 'hq_city' => 'Seoul',
        'industry' => 'retail_fmcg', 'segment_id' => $seg, 'employee_range' => '1001-5000', 'founded_year' => 1999,
        'summary' => ['zh' => '韩国美妆', 'en' => 'Korean beauty', 'id' => 'Kecantikan Korea'],
        'org' => ['departments' => ['R&D'], 'leaders' => [['name' => 'Kim', 'title' => 'CEO', 'source_url' => 'https://glow.example.com/about-us'], ['name' => 'NoSource', 'title' => 'CFO']]],
        'sources' => [['field' => 'website', 'url' => 'https://glow.example.com', 'evidence' => 'x', 'confidence' => 'high']]]));
    $co = $pdo->query("SELECT * FROM recruit_companies WHERE id=$glow")->fetch(PDO::FETCH_ASSOC);
    $org = json_decode($co['org_json'], true);
    ok($r['status'] === 'done' && $co['website'] === 'https://glow.example.com' && $co['region'] === 'overseas' && $co['country'] === 'KR', '补全：官网、国家 KR → 海外');
    ok((int)$co['segment_id'] === $seg && $co['industry'] === 'retail_fmcg', '自动归到赛道与行业');
    ok($co['hq_city'] === '手填城市', '人工锁定的字段不被覆盖');
    ok(count($org['leaders']) === 1 && $org['leaders'][0]['name'] === 'Kim', '没出处的高管不收');
    $meta = json_decode($co['enrich_meta'], true);
    ok($meta['searches'] === 4 && $meta['pages'] >= 1 && $meta['cost_usd'] > 0, '记了搜索 / 抓取次数与费用：' . json_encode($meta));
    $r = recruitCompanyEnrichOne($pdo, $acme > 0 ? $acme : $glow, $io(['match' => 'ambiguous']));
    ok($r['status'] === 'failed' && $r['error'] === 'ambiguous', '同名多家 → 失败待人工确认，不瞎填');
    $pdo->prepare("UPDATE recruit_companies SET enrich_priority=1, enrich_status='queued' WHERE id=?")->execute([$glow]);
    $noKey = ['search' => function () { throw new RecruitEnrichUnavailable('tavily'); }, 'scrape' => fn() => '', 'llm' => fn() => []];
    $rep = recruitCompanyEnrichBatch($pdo, $noKey, 1);
    ok(strpos($rep['skipped'], 'not_configured') === 0 && $pdo->query("SELECT enrich_status FROM recruit_companies WHERE id=$glow")->fetchColumn() === 'queued',
       '没配搜索 key：回队列，本轮停');
    $pdo->prepare("UPDATE recruit_companies SET rank_in_segment=1, tier='top' WHERE id=?")->execute([$glow]);
    $pdo->prepare("UPDATE recruit_companies SET enrich_priority=0 WHERE id=?")->execute([$glow]);

    echo "\n七、查看名单（admin 不自动放行）\n";
    $viewersBefore = recruitCompanySetting($pdo, 'recruit.company.viewer_ids', '');
    setSystemSetting($pdo, 'recruit.company.viewer_ids', json_encode([$uid]));
    $adminNotInList = (int)$pdo->query("SELECT id FROM users WHERE status='active' AND role='admin' AND id<>$uid ORDER BY id LIMIT 1")->fetchColumn();
    ok(recruitCompanyCanView($pdo, $uid) && ($adminNotInList === 0 || !recruitCompanyCanView($pdo, $adminNotInList)), '名单里的能看；不在名单的 admin 也看不到');
    ok(!recruitCompanyCanEdit($pdo, $adminNotInList ?: -1), '不在名单的 admin 不能改');
    if ($viewersBefore === '') $pdo->exec("DELETE FROM system_settings WHERE setting_key='recruit.company.viewer_ids'"); else setSystemSetting($pdo, 'recruit.company.viewer_ids', $viewersBefore);
    ok(recruitOrgKey('PT Nestlé Indonesia') === recruitOrgKey('Nestle Indonesia') && recruitOrgKey('BCA') === 'bca', '去重音同一家；BCA 这种缩写也建档');

    echo "\n六、人才库筛选与标签\n";
    $ids = function (array $f) use ($pdo) {
        [$w, $a] = recruitCandidateFilterSql($f);
        $st = $pdo->prepare("SELECT c.id FROM recruit_candidates c WHERE $w AND c.name LIKE '!_!_%' ESCAPE '!'");
        $st->execute($a);
        $r = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); sort($r); return $r;
    };
    $want = [$a, $b, $c]; sort($want);
    ok($ids(['company_id' => $glow]) === $want, '按企业筛到 3 人');
    ok($ids(['segment_id' => $seg, 'job_function' => 'marketing']) === [$b], '「美妆赛道做过市场」= B');
    ok($ids(['tier' => 'bench', 'company_state' => 'current']) === [$a], '「现在在标杆企业」= A');
    $bench = recruitCandidateBench($pdo, [$a]);
    $best = recruitBestBench($bench[$a] ?? []);
    ok($best && $best['rank'] === 1 && $best['tier'] === 'top' && $best['segment']['zh'] === '__美妆', '候选人标签：美妆 · 第1 · 头部');
} finally {
    foreach ($made['cand'] as $x) {
        $pdo->exec("DELETE FROM recruit_candidate_companies WHERE candidate_id=$x");
        $pdo->exec("DELETE FROM recruit_candidates WHERE id=$x");
    }
    $cos = $pdo->query("SELECT id FROM recruit_companies WHERE name LIKE '!_!_T%' ESCAPE '!' OR name LIKE 'PT. !_!_T%' ESCAPE '!'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cos as $x) { $pdo->exec("DELETE FROM recruit_company_aliases WHERE company_id=" . (int)$x); $pdo->exec("DELETE FROM recruit_companies WHERE id=" . (int)$x); }
    $pdo->exec("DELETE FROM recruit_company_aliases WHERE alias LIKE '!_!_T%' ESCAPE '!'");
    $pdo->exec("DELETE FROM recruit_company_ignored WHERE sample LIKE '!_!_T%' ESCAPE '!'");
    $pdo->exec("DELETE FROM recruit_title_functions WHERE title_sample LIKE '!_!_t%' ESCAPE '!'");
    foreach ($made['seg'] as $x) $pdo->exec("DELETE FROM recruit_segments WHERE id=" . (int)$x);
    if ($verBefore === '') $pdo->exec("DELETE FROM system_settings WHERE setting_key='recruit.company.ver'");
    else setSystemSetting($pdo, 'recruit.company.ver', $verBefore);
}
echo $fails === 0 ? "\n全部通过\n" : "\n失败 $fails 项\n";
exit($fails === 0 ? 0 : 1);
