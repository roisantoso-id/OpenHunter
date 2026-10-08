<?php
/**
 * OpenHunter · 企业库接口（逻辑在 includes/recruit_company.php）
 * ⛔ 看：只认「企业库查看名单」（recruit.company.viewer_ids），admin 角色不自动放行（2026-09-25「不是每个管理员都能看到」）；
 *    改企业 / 赛道 / 名次 / 别名 / 合并 / 忽略 / 立即检索 / 名单：名单里 + recruit_admin；改职位名的职能：名单里即可。
 * 只看自己名下的招聘专员（recruitScopeOwner）：人数与点开的名单都只算自己名下（§6.7.1 同口径）。
 */

/** 鉴权 + 加载：不在名单 → 403；$edit 还要 recruit_admin。@return array{0:int,1:string} */
function recruitCompanyAuth(PDO $pdo, bool $edit = false): array {
    [$uid, $uname] = recruitAuth($pdo);
    require_once __DIR__ . '/../recruit_company.php';
    if (!recruitCompanyReady($pdo)) recruitErr('companyPending', 'company tables not created');
    if (!($edit ? recruitCompanyCanEdit($pdo, $uid) : recruitCompanyCanView($pdo, $uid))) recruitErr('forbidden', 'forbidden', 403);
    return [$uid, $uname];
}

/** 一行企业 → 页面用的形状（JSON 列解开、赛道三语名带上） */
function recruitCompanyRow(array $r): array {
    foreach (['summary_json' => 'summary', 'org_json' => 'org', 'sources_json' => 'sources', 'id_entities' => 'id_entities',
              'enrich_meta' => 'enrich_meta', 'locked_fields' => 'locked'] as $col => $k) {
        if (!array_key_exists($col, $r)) continue;
        $v = json_decode((string)$r[$col], true);
        unset($r[$col]);
        $r[$k] = $v ?: ($k === 'summary' || $k === 'org' || $k === 'enrich_meta' || $k === 'locked' ? new stdClass() : []);
    }
    $r['segment'] = !empty($r['segment_id']) && isset($r['seg_zh']) ? ['zh' => $r['seg_zh'], 'en' => $r['seg_en'], 'id' => $r['seg_id']] : null;
    unset($r['seg_zh'], $r['seg_en'], $r['seg_id']);
    return $r;
}

function handleRecruitCompanies(PDO $pdo): void {
    [$uid] = recruitCompanyAuth($pdo);
    $scope = recruitScopeOwner($pdo, $uid);
    $w = ['co.merged_into=0', 'co.active=1']; $a = [];
    if (in_array($_GET['region'] ?? '', RECRUIT_REGIONS, true)) { $w[] = 'co.region=?'; $a[] = $_GET['region']; }
    if (preg_match('/^[A-Z]{2}$/', (string)($_GET['country'] ?? ''))) { $w[] = 'co.country=?'; $a[] = $_GET['country']; }
    if (in_array($_GET['tier'] ?? '', RECRUIT_TIERS, true)) { $w[] = 'co.tier=?'; $a[] = $_GET['tier']; }
    if (!empty($_GET['bench'])) $w[] = 'co.rank_in_segment IS NOT NULL';
    if (in_array($_GET['enrich'] ?? '', ['none', 'queued', 'running', 'done', 'failed'], true)) { $w[] = 'co.enrich_status=?'; $a[] = $_GET['enrich']; }
    $kw = trim((string)($_GET['keyword'] ?? ''));
    if ($kw !== '') {
        $w[] = '(co.name LIKE ? OR EXISTS (SELECT 1 FROM recruit_company_aliases al WHERE al.company_id=co.id AND al.alias LIKE ?))';
        $a[] = "%$kw%"; $a[] = "%$kw%";
    }
    // 人数（排序 / 过滤用，与 recruitCompanyCounts 的「待过」同定义；看不到全部的只算自己名下）：先按企业聚合一次再 JOIN，不逐行子查询
    $pc = "(SELECT x.company_id, COUNT(DISTINCT x.candidate_id) people, COUNT(DISTINCT CASE WHEN x.is_current=1 THEN x.candidate_id END) cur
            FROM recruit_candidate_companies x JOIN recruit_candidates c ON c.id=x.candidate_id"
        . ($scope !== null ? ' WHERE ' . recruitVisibleSql($scope) : '') . " GROUP BY x.company_id)";
    $pplSql = 'COALESCE(pc.people,0)';
    if (($_GET['has_people'] ?? '1') === '1') $w[] = "pc.people > 0";
    // 左侧行业树的计数：用除行业 / 赛道以外的全部条件算，点哪个节点，列表条数就是节点上的数字（§6.7.1）
    $baseWhere = implode(' AND ', $w); $baseArgs = $a;
    $fc = $pdo->prepare("SELECT co.industry, co.segment_id, COUNT(*) n FROM recruit_companies co LEFT JOIN $pc pc ON pc.company_id=co.id
                         WHERE $baseWhere GROUP BY co.industry, co.segment_id");
    $fc->execute($baseArgs);
    $facets = ['all' => 0, 'industries' => [], 'segments' => [], 'unclassified' => 0, 'by_industry' => []];
    foreach ($fc->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $n = (int)$x['n'];
        $facets['all'] += $n;
        if ($x['industry'] === '' ) $facets['unclassified'] += $n;
        else $facets['industries'][$x['industry']] = ($facets['industries'][$x['industry']] ?? 0) + $n;
        if ((int)$x['segment_id'] > 0) $facets['segments'][(int)$x['segment_id']] = ($facets['segments'][(int)$x['segment_id']] ?? 0) + $n;
        // 关系网络页按「行业 → 赛道」下钻：请求带 industry + segment_id，所以计数也按 (行业, 赛道) 分，0 = 这个行业里还没细分的
        if ($x['industry'] !== '') $facets['by_industry'][$x['industry']][(int)$x['segment_id']] = ($facets['by_industry'][$x['industry']][(int)$x['segment_id']] ?? 0) + $n;
    }
    $facets['by_industry'] = (object)array_map(fn($m) => (object)$m, $facets['by_industry'] ?? []);
    arsort($facets['industries']);
    $facets['segments'] = (object)$facets['segments'];
    if (($_GET['industry'] ?? '') === 'none') $w[] = "co.industry=''";
    elseif (in_array($_GET['industry'] ?? '', RECRUIT_INDUSTRIES, true)) { $w[] = 'co.industry=?'; $a[] = $_GET['industry']; }
    if ((int)($_GET['segment_id'] ?? 0) > 0) { $w[] = 'co.segment_id=?'; $a[] = (int)$_GET['segment_id']; }
    if (($_GET['segment_id'] ?? '') === 'none') $w[] = 'co.segment_id=0';
    $where = implode(' AND ', $w);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM recruit_companies co LEFT JOIN $pc pc ON pc.company_id=co.id WHERE $where");
    $cnt->execute($a);
    $total = (int)$cnt->fetchColumn();
    $size = max(10, min(100, (int)($_GET['pageSize'] ?? 30)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    // 热度排序：与卡片火苗同一公式（待过 + 在职×2 + 流程里×3，下面 $r['heat']）；看不到全部的只算自己名下
    $heatSql = "$pplSql + 2 * COALESCE(pc.cur,0) + 3 * (SELECT COUNT(DISTINCT xx.candidate_id) FROM recruit_candidate_companies xx JOIN recruit_candidates c2 ON c2.id=xx.candidate_id
                JOIN recruit_candidate_jobs cj ON cj.candidate_id=xx.candidate_id AND cj.stage IN ('shortlisted','submitted','interviewing','offered','hired')
                WHERE xx.company_id=co.id" . ($scope !== null ? ' AND ' . recruitVisibleSql($scope, 'c2') : '') . ")";
    $order = ($_GET['sort'] ?? '') === 'heat' ? "$heatSql DESC, co.id" : (($_GET['sort'] ?? '') === 'rank'
        ? "co.segment_id=0, co.segment_id, co.rank_in_segment IS NULL, co.rank_in_segment, people DESC"
        : (($_GET['sort'] ?? '') === 'name' ? 'co.name' : "co.rank_in_segment IS NULL, people DESC, co.id"));
    $st = $pdo->prepare("SELECT co.id, co.name, co.region, co.country, co.hq_city, co.website, co.linkedin_url, co.industry, co.segment_id,
                                co.rank_in_segment, co.tier, co.employee_range, co.enrich_status, co.enrich_error, co.enriched_at, co.enrich_meta, co.class_source, co.class_conf, co.suggest_segment,
                                co.source, co.summary_json, co.org_json, s.name_zh seg_zh, s.name_en seg_en, s.name_id seg_id, $pplSql people,
                                (SELECT COUNT(*) FROM recruit_company_aliases al WHERE al.company_id=co.id) alias_count, co.parent_group
                         FROM recruit_companies co LEFT JOIN recruit_segments s ON s.id=co.segment_id LEFT JOIN $pc pc ON pc.company_id=co.id
                         WHERE $where ORDER BY $order LIMIT $size OFFSET " . (($page - 1) * $size));
    $st->execute($a);
    $rows = array_map('recruitCompanyRow', $st->fetchAll(PDO::FETCH_ASSOC));
    $counts = recruitCompanyCounts($pdo, array_column($rows, 'id'), $scope);
    // 热度：待过的人 + 在职的人×2 + 正在我们招聘流程里的人×3（挂在这家、在任一职位阶段为 已推荐 ~ 已录用）；看不到全部的只算自己名下
    $pipe = [];
    if ($rows) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        foreach ($pdo->query("SELECT x.company_id, COUNT(DISTINCT x.candidate_id) n FROM recruit_candidate_companies x JOIN recruit_candidates c ON c.id=x.candidate_id
                              JOIN recruit_candidate_jobs cj ON cj.candidate_id=x.candidate_id AND cj.stage IN ('shortlisted','submitted','interviewing','offered','hired')
                              WHERE x.company_id IN ($ids)" . ($scope !== null ? ' AND ' . recruitVisibleSql($scope) : '') . " GROUP BY x.company_id")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $pipe[(int)$x['company_id']] = (int)$x['n'];
        }
    }
    foreach ($rows as &$r) {
        $r['counts'] = $counts[(int)$r['id']] ?? ['all' => 0, 'current' => 0, 'former' => 0, 'long' => 0, 'funcs' => []];
        $r['pipeline'] = $pipe[(int)$r['id']] ?? 0;
        $r['heat'] = $r['counts']['all'] + 2 * $r['counts']['current'] + 3 * $r['pipeline'];
    }
    unset($r);
    // 赛道下拉：每个赛道的企业数 = 默认列表（只看有候选人的、按数据范围）选中该赛道时的条数
    $segs = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM recruit_companies co JOIN $pc pc ON pc.company_id=co.id
                                      WHERE co.segment_id=s.id AND co.merged_into=0 AND co.active=1 AND pc.people>0) companies
                         FROM recruit_segments s ORDER BY s.active DESC, s.sort, s.id")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['success' => true, 'data' => ['rows' => $rows, 'total' => $total, 'segments' => $segs,
        'long_months' => recruitCompanyLongMonths($pdo), 'enrich_enabled' => recruitCompanySetting($pdo, 'recruit.company.enrich_enabled', '0') === '1',
        'can_admin' => recruitCompanyCanEdit($pdo, $uid), 'enums' => recruitCompanyEnums(), 'facets' => $facets, 'status' => recruitCompanyStatus($pdo)]]);
}

function handleRecruitCompanyDetail(PDO $pdo): void {
    [$uid] = recruitCompanyAuth($pdo);
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT co.*, s.name_zh seg_zh, s.name_en seg_en, s.name_id seg_id FROM recruit_companies co
                         LEFT JOIN recruit_segments s ON s.id=co.segment_id WHERE co.id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) recruitErr('notFound', 'not found');
    if ((int)$r['merged_into'] > 0) jsonResponse(['success' => true, 'data' => ['merged_into' => (int)$r['merged_into']]]);
    $co = recruitCompanyRow($r);
    $scope = recruitScopeOwner($pdo, $uid);
    $al = $pdo->prepare("SELECT id, alias FROM recruit_company_aliases WHERE company_id=? ORDER BY id");
    $al->execute([$id]);
    $co['aliases'] = $al->fetchAll(PDO::FETCH_ASSOC);
    $co['counts'] = recruitCompanyCounts($pdo, [$id], $scope)[$id];
    $co['talent_map'] = recruitCompanyTalentMap($pdo, $id, $scope);
    $co['can_admin'] = recruitCompanyCanEdit($pdo, $uid);
    jsonResponse(['success' => true, 'data' => $co]);
}

/** 下钻：某个桶 / 职能 / 职级的候选人（与列表计数同一 SQL：recruitCompanyBucketSql） */
function handleRecruitCompanyPeople(PDO $pdo): void {
    [$uid] = recruitCompanyAuth($pdo);
    $id = (int)($_GET['id'] ?? 0);
    [$w, $a] = recruitCompanyBucketSql($pdo, $id, (string)($_GET['bucket'] ?? 'all'), (string)($_GET['func'] ?? ''), (string)($_GET['sen'] ?? ''),
        recruitScopeOwner($pdo, $uid));
    $st = $pdo->prepare("SELECT c.id, c.name, c.latest_title, c.latest_company, c.years_exp, c.status, c.owner_user_name, c.city
                         FROM recruit_candidates c WHERE $w ORDER BY c.id DESC LIMIT 5000");
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {   // 每人在这家的各段：职位、起止、在职、职能职级
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $sp = $pdo->prepare("SELECT x.candidate_id, x.title, x.start_ym, x.end_ym, x.is_current, x.months,
                                    COALESCE(NULLIF(tf.job_function,''),'') job_function, COALESCE(NULLIF(tf.seniority,''),'') seniority
                             FROM recruit_candidate_companies x LEFT JOIN recruit_title_functions tf ON tf.title_key=x.title_key
                             WHERE x.company_id=? AND x.candidate_id IN ($ids) ORDER BY x.is_current DESC, x.start_ym DESC");
        $sp->execute([$id]);
        $by = [];
        foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $s) $by[(int)$s['candidate_id']][] = $s;
        $fav = recruitFavSet($pdo, $uid, array_map(fn($r) => (int)$r['id'], $rows));   // 关系网络人卡上的收藏星
        foreach ($rows as &$r) { $r['code'] = recruitCandCode((int)$r['id']); $r['stints'] = $by[(int)$r['id']] ?? []; $r['favorited'] = isset($fav[(int)$r['id']]); }
        unset($r);
    }
    jsonResponse(['success' => true, 'data' => $rows]);
}

function recruitCompanyErr(RecruitLcError $e): void { recruitErr($e->key, $e->key, 200, $e->extra); }

/**
 * 新建 / 修改企业（recruit_admin）。改了 AI 会填的字段 → 记进 locked_fields，之后补全不覆盖。
 * aliases：整组传（含正式名），与现有对比增删；某个别名归一后已属于别家 → aliasTaken（带那家的名字）
 */
function handleRecruitSaveCompany(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitCompanyAuth($pdo, true);
    $id = (int)($in['id'] ?? 0);
    $cur = [];
    if ($id > 0) {
        $st = $pdo->prepare("SELECT * FROM recruit_companies WHERE id=? AND merged_into=0");
        $st->execute([$id]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) recruitErr('notFound', 'not found');
    }
    $name = mb_substr(trim((string)($in['name'] ?? $cur['name'] ?? '')), 0, 191);
    if ($name === '') recruitErr('nameRequired', 'name required');
    $vals = ['name' => $name];
    $s = fn($k, $n = 191) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);
    if (array_key_exists('region', $in)) $vals['region'] = in_array($in['region'], RECRUIT_REGIONS, true) ? $in['region'] : '';
    if (array_key_exists('country', $in)) $vals['country'] = preg_match('/^[A-Z]{2}$/', strtoupper($s('country', 2))) ? strtoupper($s('country', 2)) : '';
    foreach (['hq_city' => 100, 'employee_range' => 40, 'parent_group' => 191] as $k => $n) if (array_key_exists($k, $in)) $vals[$k] = $s($k, $n);
    foreach (['website', 'linkedin_url'] as $k) if (array_key_exists($k, $in)) {
        $v = $s($k, 255);
        if ($v !== '' && !preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
        $vals[$k] = $v;
    }
    if (array_key_exists('industry', $in)) $vals['industry'] = in_array($in['industry'], RECRUIT_INDUSTRIES, true) ? $in['industry'] : '';
    if (array_key_exists('segment_id', $in)) $vals['segment_id'] = max(0, (int)$in['segment_id']);
    if (array_key_exists('founded_year', $in)) { $fy = (int)$in['founded_year']; $vals['founded_year'] = $fy >= 1800 && $fy <= (int)date('Y') ? $fy : null; }
    if (array_key_exists('summary', $in) && is_array($in['summary'])) {
        $vals['summary_json'] = json_encode(array_map(fn($v) => mb_substr(trim((string)$v), 0, 500), array_intersect_key($in['summary'], ['zh' => 1, 'en' => 1, 'id' => 1])), JSON_UNESCAPED_UNICODE);
    }
    // 名次 / 梯队：只有人定
    if (array_key_exists('rank_in_segment', $in)) $vals['rank_in_segment'] = $in['rank_in_segment'] === '' || $in['rank_in_segment'] === null ? null : max(1, min(999, (int)$in['rank_in_segment']));
    if (array_key_exists('tier', $in)) $vals['tier'] = in_array($in['tier'], RECRUIT_TIERS, true) ? $in['tier'] : '';
    if (array_key_exists('notes', $in)) $vals['notes'] = mb_substr(trim((string)$in['notes']), 0, 2000);
    if (!empty($vals['segment_id'])) {
        $sg = $pdo->prepare("SELECT 1 FROM recruit_segments WHERE id=?");
        $sg->execute([(int)$vals['segment_id']]);
        if (!$sg->fetchColumn()) recruitErr('notFound', 'segment not found');
    }
    $aliases = null;
    if (is_array($in['aliases'] ?? null)) {
        $aliases = [];
        // 改名时旧名自动留作别名：简历里写的还是旧名，丢了就会被重新自动建档成另一家、人全挪走
        $keep = $cur && (string)$cur['name'] !== $name ? [(string)$cur['name']] : [];
        foreach (array_merge([$name], $keep, $in['aliases']) as $al) {
            $al = mb_substr(trim((string)$al), 0, 191);
            $k = recruitOrgKey($al);
            if ($k !== '' && !isset($aliases[$k])) $aliases[$k] = $al;
        }
    }
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            // 人工改了行业 / 赛道：归类来源记 manual（之后 AI / 联网都不覆盖）；原来是 AI 归的 → 记回归用例
            if ((array_key_exists('industry', $vals) && (string)$vals['industry'] !== (string)$cur['industry'])
                || (array_key_exists('segment_id', $vals) && (int)$vals['segment_id'] !== (int)$cur['segment_id'])) {
                recruitCoClassFeedback($pdo, $cur, $vals, $uid);
                $vals['class_source'] = 'manual'; $vals['class_conf'] = 'high';
            }
            $locked = json_decode((string)$cur['locked_fields'], true) ?: [];
            foreach ($vals as $k => $v) if (in_array($k, RECRUIT_COMPANY_EDITABLE, true) && (string)$v !== (string)$cur[$k]) $locked[$k] = 1;
            $vals['locked_fields'] = json_encode($locked);
            $pdo->prepare("UPDATE recruit_companies SET " . implode(', ', array_map(fn($k) => "$k=?", array_keys($vals))) . ", updated_at=(" . dbNow() . ") WHERE id=?")
                ->execute(array_merge(array_values($vals), [$id]));
        } else {
            $vals += ['org_key' => recruitOrgKey($name), 'source' => 'manual', 'enrich_status' => 'queued', 'created_by' => $uid];
            $pdo->prepare("INSERT INTO recruit_companies (" . implode(', ', array_keys($vals)) . ", created_at, updated_at)
                           VALUES (" . implode(', ', array_fill(0, count($vals), '?')) . ", (" . dbNow() . "), (" . dbNow() . "))")->execute(array_values($vals));
            $id = (int)$pdo->lastInsertId();
            $aliases ??= [recruitOrgKey($name) => $name];
        }
        $bump = false;
        if ($aliases !== null) {
            $st = $pdo->prepare("SELECT alias_key FROM recruit_company_aliases WHERE company_id=?");
            $st->execute([$id]);
            $have = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
            foreach ($aliases as $k => $al) {
                if (isset($have[$k])) continue;
                $o = $pdo->prepare("SELECT co.name FROM recruit_company_aliases a JOIN recruit_companies co ON co.id=a.company_id WHERE a.alias_key=? AND a.company_id<>?");
                $o->execute([$k, $id]);
                if (($other = $o->fetchColumn()) !== false) throw new RecruitLcError('aliasTaken', ['alias' => $al, 'company' => $other]);
                $pdo->prepare("INSERT INTO recruit_company_aliases (company_id, alias, alias_key, created_at) VALUES (?, ?, ?, (" . dbNow() . "))")->execute([$id, $al, $k]);
                $bump = true;
            }
            foreach (array_keys($have) as $k) if (!isset($aliases[$k])) {
                $pdo->prepare("DELETE FROM recruit_company_aliases WHERE company_id=? AND alias_key=?")->execute([$id, $k]);
                $bump = true;
            }
        }
        $pdo->commit();
    } catch (RecruitLcError $e) { if ($pdo->inTransaction()) $pdo->rollBack(); recruitCompanyErr($e); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); recruitErr('saveFailed', $e->getMessage(), 500); }
    if ($bump) recruitCompanyBumpVer($pdo);
    logOperation($pdo, $uid, $uname, 'recruit_company_save', 'recruit_company', $id, json_encode(array_keys($vals)));
    jsonResponse(['success' => true, 'data' => ['id' => $id]]);
}

/** 合并：from 的别名全挪到 into，from 标 merged_into（recruit_admin） */
function handleRecruitMergeCompany(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitCompanyAuth($pdo, true);
    $from = (int)($in['from_id'] ?? 0); $into = (int)($in['into_id'] ?? 0);
    if ($from <= 0 || $into <= 0 || $from === $into) recruitErr('badRequest', 'bad ids');
    $st = $pdo->prepare("SELECT COUNT(*) FROM recruit_companies WHERE id IN (?, ?) AND merged_into=0");
    $st->execute([$from, $into]);
    if ((int)$st->fetchColumn() !== 2) recruitErr('notFound', 'not found');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE recruit_company_aliases SET company_id=? WHERE company_id=?")->execute([$into, $from]);
        $pdo->prepare("UPDATE recruit_companies SET merged_into=?, active=0, updated_at=(" . dbNow() . ") WHERE id=?")->execute([$into, $from]);
        $pdo->prepare("UPDATE recruit_candidate_companies SET company_id=? WHERE company_id=?")->execute([$into, $from]);   // 立即生效，不等重挂
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); recruitErr('saveFailed', $e->getMessage(), 500); }
    recruitCompanyBumpVer($pdo);
    logOperation($pdo, $uid, $uname, 'recruit_company_merge', 'recruit_company', $into, "from #$from");
    jsonResponse(['success' => true]);
}

/** 这家不建档（自由职业 / 学校 / 乱写的）：它的所有别名进忽略名单，企业停用（recruit_admin） */
function handleRecruitIgnoreCompany(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitCompanyAuth($pdo, true);
    $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare("SELECT alias, alias_key FROM recruit_company_aliases WHERE company_id=?");
    $st->execute([$id]);
    $als = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$als) recruitErr('notFound', 'not found');
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(dbInsertIgnore() . " recruit_company_ignored (org_key, sample, created_by, created_at) VALUES (?, ?, ?, (" . dbNow() . "))");
        foreach ($als as $a) $ins->execute([$a['alias_key'], $a['alias'], $uid]);
        $pdo->prepare("DELETE FROM recruit_company_aliases WHERE company_id=?")->execute([$id]);
        $pdo->prepare("UPDATE recruit_companies SET active=0, enrich_priority=0, updated_at=(" . dbNow() . ") WHERE id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM recruit_candidate_companies WHERE company_id=?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); recruitErr('saveFailed', $e->getMessage(), 500); }
    recruitCompanyBumpVer($pdo);
    logOperation($pdo, $uid, $uname, 'recruit_company_ignore', 'recruit_company', $id, implode(' / ', array_column($als, 'alias')));
    jsonResponse(['success' => true]);
}

function handleRecruitCompanyIgnored(PDO $pdo): void {
    recruitCompanyAuth($pdo, true);
    jsonResponse(['success' => true, 'data' => $pdo->query("SELECT id, org_key, sample, created_at FROM recruit_company_ignored ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC)]);
}

/** 从忽略名单恢复：下一轮挂靠会重新自动建档 */
function handleRecruitUnignoreCompany(PDO $pdo, array $in): void {
    recruitCompanyAuth($pdo, true);
    $pdo->prepare("DELETE FROM recruit_company_ignored WHERE id=?")->execute([(int)($in['id'] ?? 0)]);
    recruitCompanyBumpVer($pdo);
    jsonResponse(['success' => true]);
}

/** 立即检索一家：排进优先队列，马上拉起一轮补全（不看自动补全开关与每日上限） */
function handleRecruitCompanyEnrich(PDO $pdo, array $in): void {
    [$uid] = recruitCompanyAuth($pdo, true);
    $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare("UPDATE recruit_companies SET enrich_priority=1, enrich_status='queued', enrich_attempts=0, enrich_error='' WHERE id=? AND merged_into=0");
    $st->execute([$id]);
    if ($st->rowCount() === 0) recruitErr('notFound', 'not found');
    require_once __DIR__ . '/../worker_spawn.php';
    $sp = workerSpawn($pdo, dirname(__DIR__, 2) . '/scripts/cron_recruit_company.php', ["--id=$id"], 'recruit_company');
    jsonResponse(['success' => true, 'data' => ['id' => $id, 'spawned' => (bool)$sp['ok']]]);   // 拉不起后台进程：留在优先队列，定时任务 5 分钟内做
}

function handleRecruitSegments(PDO $pdo): void {
    [$uid] = recruitCompanyAuth($pdo);
    jsonResponse(['success' => true, 'data' => $pdo->query("SELECT * FROM recruit_segments ORDER BY active DESC, sort, id")->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleRecruitSaveSegment(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitCompanyAuth($pdo, true);
    $id = (int)($in['id'] ?? 0);
    $n = fn($k) => mb_substr(trim((string)($in[$k] ?? '')), 0, 64);
    if ($n('name_zh') === '' && $n('name_en') === '' && $n('name_id') === '') recruitErr('nameRequired', 'name required');
    $vals = [$n('name_zh'), $n('name_en'), $n('name_id'), in_array($in['industry'] ?? '', RECRUIT_INDUSTRIES, true) ? $in['industry'] : '',
             (int)($in['sort'] ?? 0), empty($in['inactive']) ? 1 : 0];
    if ($id > 0) $pdo->prepare("UPDATE recruit_segments SET name_zh=?, name_en=?, name_id=?, industry=?, sort=?, active=? WHERE id=?")->execute(array_merge($vals, [$id]));
    else { $pdo->prepare("INSERT INTO recruit_segments (name_zh, name_en, name_id, industry, sort, active, created_at) VALUES (?, ?, ?, ?, ?, ?, (" . dbNow() . "))")->execute($vals); $id = (int)$pdo->lastInsertId(); }
    logOperation($pdo, $uid, $uname, 'recruit_segment_save', 'recruit_segment', $id, $vals[0]);
    jsonResponse(['success' => true, 'data' => ['id' => $id]]);
}

/** 职位归类页：职位名 + 出现次数 + 职能职级（AI / 人工） */
function handleRecruitTitleFunctions(PDO $pdo): void {
    [$uid] = recruitCompanyAuth($pdo);
    $w = ["x.title_key<>''"]; $a = [];
    if (in_array($_GET['func'] ?? '', RECRUIT_JOB_FUNCTIONS, true)) { $w[] = "COALESCE(NULLIF(tf.job_function,''),'other')=?"; $a[] = $_GET['func']; }
    if (($_GET['source'] ?? '') === 'manual') $w[] = "tf.source='manual'";
    if (($_GET['source'] ?? '') === 'ai') $w[] = "tf.source='ai'";
    if (($_GET['source'] ?? '') === 'pending') $w[] = "(tf.id IS NULL OR tf.source IN ('','fallback'))";
    $kw = trim((string)($_GET['keyword'] ?? ''));
    if ($kw !== '') { $w[] = 'x.title LIKE ?'; $a[] = "%$kw%"; }
    $size = max(20, min(200, (int)($_GET['pageSize'] ?? 50)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $where = implode(' AND ', $w);
    $scope = recruitScopeOwner($pdo, $uid);
    if ($scope !== null) $where .= ' AND ' . recruitVisibleSql($scope);
    $base = "FROM recruit_candidate_companies x JOIN recruit_candidates c ON c.id=x.candidate_id
             LEFT JOIN recruit_title_functions tf ON tf.title_key=x.title_key WHERE $where";
    $c = $pdo->prepare("SELECT COUNT(DISTINCT x.title_key) $base");
    $c->execute($a);
    $st = $pdo->prepare("SELECT x.title_key, MIN(x.title) title, COUNT(DISTINCT x.candidate_id) people, MAX(tf.job_function) job_function,
                                MAX(tf.seniority) seniority, MAX(tf.source) source
                         $base GROUP BY x.title_key ORDER BY people DESC, x.title_key LIMIT $size OFFSET " . (($page - 1) * $size));
    $st->execute($a);
    jsonResponse(['success' => true, 'data' => ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$c->fetchColumn()]]);
}

function handleRecruitSetTitleFunction(PDO $pdo, array $in): void {
    [$uid] = recruitCompanyAuth($pdo);
    $k = recruitTitleKey((string)($in['title_key'] ?? ''));
    if ($k === '') recruitErr('badRequest', 'title required');
    try { recruitSetTitleFunction($pdo, $k, (string)($in['job_function'] ?? ''), (string)($in['seniority'] ?? ''), $uid); }
    catch (InvalidArgumentException $e) { recruitErr('badRequest', $e->getMessage()); }
    jsonResponse(['success' => true]);
}

/** 立即重挂一批（企业库刚改完、不想等定时任务） */
function handleRecruitCompanyRelink(PDO $pdo, array $in): void {
    recruitCompanyAuth($pdo, true);
    // 与定时任务同一把锁：两边同时自动建档会撞别名唯一键
    $lock = fopen(sys_get_temp_dir() . '/openhunter_recruit_company.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) recruitErr('companyBusy', 'company job running');
    try { $r = recruitCompanyLinkBatch($pdo, 200); } finally { flock($lock, LOCK_UN); }
    jsonResponse(['success' => true, 'data' => $r]);
}

/** 企业库查看名单：当前名单 + 可选的人（在职用户）。只有能改的人（名单里 + 招聘管理员）能看这个 */
function handleRecruitCompanyViewers(PDO $pdo): void {
    recruitCompanyAuth($pdo, true);
    $ids = recruitCompanyViewerIds($pdo);
    $users = $pdo->query("SELECT id, name, username, role FROM users WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['success' => true, 'data' => ['ids' => $ids, 'users' => $users]]);
}

function handleRecruitSaveCompanyViewers(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitCompanyAuth($pdo, true);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])))));
    if (!in_array($uid, $ids, true)) recruitErr('viewerSelf', 'cannot remove yourself');   // 防止把自己踢出去后没人能改
    setSystemSetting($pdo, 'recruit.company.viewer_ids', json_encode($ids));
    logOperation($pdo, $uid, $uname, 'recruit_company_viewers', 'system_setting', 0, json_encode($ids));
    jsonResponse(['success' => true]);
}

/**
 * 检索一家公司（照 AI 销售助手·客户情报：输入名字 → 搜索 → 抓官网 → 分析 → 入库），名单里的人都能用。
 * 库里有（名字 / 别名归一后命中）就用那家，没有就新建（source=manual）。后台进程跑，页面按 enrich_meta.stage 轮询显示进度；
 * 拉不起后台进程（生产禁 exec 时）→ 留在优先队列，定时任务 5 分钟内做。
 */
function handleRecruitCompanyResearch(PDO $pdo, array $in): void {
    [$uid] = recruitCompanyAuth($pdo);
    $id = (int)($in['id'] ?? 0);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 191);
    // 联网检索按次花钱（搜索 + 抓取 + 模型，不在 token 预算里）：每天手动检索总数 ≤ enrich_daily；超了连空档案都不建
    $day = json_decode((string)recruitCompanySetting($pdo, 'recruit.company.research_day', ''), true) ?: [];
    $used = ($day['date'] ?? '') === date('Y-m-d') ? (int)($day['n'] ?? 0) : 0;
    if ($used >= max(1, (int)recruitCompanySetting($pdo, 'recruit.company.enrich_daily', '30'))) recruitErr('researchLimit', 'daily research limit reached');
    if ($id <= 0) {
        if ($name === '' || recruitOrgKey($name) === '') recruitErr('nameRequired', 'name required');
        $id = recruitCompanyByKey($pdo, recruitOrgKey($name));
        if ($id <= 0) {
            $pdo->beginTransaction();
            try { $id = recruitCompanyAutoCreate($pdo, $name, recruitOrgKey($name)); $pdo->commit(); }
            catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); recruitErr('saveFailed', $e->getMessage(), 500); }
            $pdo->prepare("UPDATE recruit_companies SET source='manual', created_by=? WHERE id=?")->execute([$uid, $id]);
        }
    }
    // 30 天内检索过的只有管理员能重跑，其他人直接看结果
    $admin = recruitCompanyCanEdit($pdo, $uid);
    $cur = $pdo->prepare("SELECT enrich_status, enriched_at FROM recruit_companies WHERE id=?");
    $cur->execute([$id]);
    $c = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$admin && ($c['enrich_status'] ?? '') === 'done' && (string)($c['enriched_at'] ?? '') > date('Y-m-d H:i:s', time() - 30 * 86400)) {
        jsonResponse(['success' => true, 'data' => ['id' => $id, 'spawned' => false, 'recent' => true]]);
    }
    $st = $pdo->prepare("UPDATE recruit_companies SET enrich_priority=1, enrich_status='queued', enrich_attempts=0, enrich_error='', enrich_meta=?
                         WHERE id=? AND merged_into=0 AND enrich_status<>'running'");
    $st->execute([json_encode(['stage' => 'queued']), $id]);
    if ($st->rowCount() > 0 && function_exists('setSystemSetting')) setSystemSetting($pdo, 'recruit.company.research_day', json_encode(['date' => date('Y-m-d'), 'n' => $used + 1]));
    require_once __DIR__ . '/../worker_spawn.php';
    $sp = $st->rowCount() > 0 ? workerSpawn($pdo, dirname(__DIR__, 2) . '/scripts/cron_recruit_company.php', ["--id=$id"], 'recruit_company') : ['ok' => true];
    jsonResponse(['success' => true, 'data' => ['id' => $id, 'spawned' => (bool)$sp['ok']]]);
}

/** 检索进度（页面每 2 秒问一次）：状态、阶段、出错原因 */
function handleRecruitCompanyResearchStatus(PDO $pdo): void {
    recruitCompanyAuth($pdo);
    $st = $pdo->prepare("SELECT id, name, enrich_status, enrich_error, enrich_meta, enriched_at FROM recruit_companies WHERE id=?");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) recruitErr('notFound', 'not found');
    $r['enrich_meta'] = json_decode((string)$r['enrich_meta'], true) ?: new stdClass();
    jsonResponse(['success' => true, 'data' => $r]);
}

/** 最近检索过的公司（检索页签下方列表） */
function handleRecruitCompanyRecent(PDO $pdo): void {
    recruitCompanyAuth($pdo);
    $rows = $pdo->query("SELECT id, name, region, country, industry, enrich_status, enrich_error, enriched_at, enrich_meta, summary_json
                         FROM recruit_companies WHERE merged_into=0 AND enriched_at IS NOT NULL ORDER BY enriched_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['success' => true, 'data' => array_map('recruitCompanyRow', $rows)]);
}

/** 立即归类一轮（管理员）：先挂靠一批（企业库刚建、定时任务还没跑时库是空的）+ 简历推断全部没归过的 + AI 快速归类一批（30 家，不联网） */
function handleRecruitCompanyClassifyNow(PDO $pdo, array $in): void {
    recruitCompanyAuth($pdo, true);
    // 与定时任务同一把锁：两边同时自动建档会撞别名唯一键
    $lock = fopen(sys_get_temp_dir() . '/openhunter_recruit_company.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) recruitErr('companyBusy', 'company job running');
    try { $link = recruitCompanyLinkBatch($pdo, 200); } finally { flock($lock, LOCK_UN); }
    $inf = recruitCompanyInferFromResumes($pdo, 2000, $link['touched']);
    $seed = recruitSegmentSeed($pdo);
    require_once __DIR__ . '/../recruit_cost.php';
    $ai = recruitTokensToday($pdo) < recruitDailyBudget($pdo)
        ? recruitCompanyAiClassify($pdo, fn(array $req) => dvChatJsonMulti($pdo, $req), 1) : ['companies' => 0, 'ok' => 0, 'calls' => 0, 'abort' => 'budget'];
    jsonResponse(['success' => true, 'data' => ['linked' => $link['candidates'], 'created' => $link['created'], 'inferred' => $inf, 'seeded' => $seed['assigned'], 'ai' => $ai]]);
}

/** 自动补全开关（管理员，页面状态条上的开关）：只写 0 / 1，改前改后记操作日志，秒级可回退 */
function handleRecruitSetCompanyEnrich(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitCompanyAuth($pdo, true);
    $old = recruitCompanySetting($pdo, 'recruit.company.enrich_enabled', '0');
    $new = !empty($in['enabled']) ? '1' : '0';
    setSystemSetting($pdo, 'recruit.company.enrich_enabled', $new);
    logOperation($pdo, $uid, $uname, 'recruit_company_enrich_toggle', 'system_setting', 0, json_encode(['from' => $old, 'to' => $new]));
    jsonResponse(['success' => true, 'data' => ['enabled' => $new === '1']]);
}

/** 关系网络：按焦点（行业 / 赛道 / 企业 / 候选人 / 学校 / 我们的项目）拼子图，includes/recruit_network.php。与企业库同一查看名单 */
function handleRecruitNetwork(PDO $pdo): void {
    [$uid] = recruitCompanyAuth($pdo);
    require_once __DIR__ . '/../recruit_network.php';
    if (($_GET['type'] ?? '') === 'threads') {   // 丝线图：一人一行（学校 / 上一个行业 / 现在的行业）
        $d = recruitNetworkThreads($pdo, recruitScopeOwner($pdo, $uid), array_filter(explode(',', (string)($_GET['ref'] ?? ''))));
        $fav = recruitFavSet($pdo, $uid, array_column($d['rows'], 'id'));
        foreach ($d['rows'] as &$r) $r['favorited'] = isset($fav[(int)$r['id']]);
        unset($r);
        jsonResponse(['success' => true, 'data' => $d]);
    }
    try {
        $g = recruitNetwork($pdo, (string)($_GET['type'] ?? ''), (string)($_GET['ref'] ?? ''), array_filter(explode(',', (string)($_GET['with'] ?? ''))),
            recruitScopeOwner($pdo, $uid), (int)($_GET['max'] ?? 300));
    } catch (InvalidArgumentException $e) { recruitErr('notFound', $e->getMessage()); }
    // 人节点带上「我收藏了没有」：关系图头部的收藏星
    $fav = recruitFavSet($pdo, $uid, array_map(fn($n) => (int)$n['ref'], array_filter($g['nodes'], fn($n) => $n['type'] === 'candidate')));
    foreach ($g['nodes'] as &$n) if ($n['type'] === 'candidate') $n['favorited'] = isset($fav[(int)$n['ref']]);
    unset($n);
    jsonResponse(['success' => true, 'data' => $g]);
}

function handleRecruitNetworkSearch(PDO $pdo): void {
    [$uid] = recruitCompanyAuth($pdo);
    require_once __DIR__ . '/../recruit_network.php';
    jsonResponse(['success' => true, 'data' => recruitNetworkSearch($pdo, (string)($_GET['q'] ?? ''), recruitScopeOwner($pdo, $uid))]);
}

/**
 * 对话生成赛道（管理员）：「把制造业拆细」「新能源按锂电 / 镍矿 / 光伏分」→ AI 出方案（不写库），页面预览后点「应用」才生效。
 * 同步调模型（一次，最多 RECRUIT_SEGMENT_CHAT_COMPANIES 家公司，web 请求超时有上限），超时 / 没配 key → aiUnavailable。
 */
function handleRecruitSegmentChat(PDO $pdo, array $in): void {
    recruitCompanyAuth($pdo, true);
    require_once __DIR__ . '/../recruit_cost.php';
    if (recruitTokensToday($pdo) >= recruitDailyBudget($pdo)) recruitErr('aiBudget', 'daily AI budget used up');
    try {
        $p = recruitSegmentPropose($pdo, fn(array $req) => dvChatJsonMulti($pdo, $req), mb_substr(trim((string)($in['message'] ?? '')), 0, 1000),
            (string)($in['industry'] ?? ''), is_array($in['history'] ?? null) ? $in['history'] : [], RECRUIT_SEGMENT_CHAT_COMPANIES);
    } catch (RecruitEnrichUnavailable $e) { recruitErr('aiUnavailable', $e->getMessage()); }
    catch (RuntimeException $e) { recruitErr('aiBadOutput', $e->getMessage()); }
    jsonResponse(['success' => true, 'data' => $p]);
}

/** 应用对话里 AI 给的方案（管理员；只收白名单字段，成员逐个校验，人工定过赛道的公司不动） */
function handleRecruitSegmentApply(PDO $pdo, array $in): void {
    [$uid] = recruitCompanyAuth($pdo, true);
    $segs = [];
    foreach ((array)($in['segments'] ?? []) as $sg) {
        if (!is_array($sg)) continue;
        $segs[] = ['existing_id' => (int)($sg['existing_id'] ?? 0), 'name_zh' => (string)($sg['name_zh'] ?? ''), 'name_en' => (string)($sg['name_en'] ?? ''),
                   'name_id' => (string)($sg['name_id'] ?? ''), 'industry' => (string)($sg['industry'] ?? ''), 'description' => (string)($sg['description'] ?? ''),
                   'members' => array_values(array_filter(array_map('intval', (array)($sg['members'] ?? []))))];
    }
    try { $r = recruitSegmentApply($pdo, ['segments' => $segs], 'ai', $uid); }
    catch (Throwable $e) { recruitErr('saveFailed', $e->getMessage(), 500); }
    jsonResponse(['success' => true, 'data' => $r]);
}
