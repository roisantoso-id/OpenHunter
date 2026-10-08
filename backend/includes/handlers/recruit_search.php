<?php
/**
 * OpenHunter · 人才检索 + 人才池（2026-09-24「先做人才检索页面」）。页面：/recruit/search
 *
 * 检索三种入口（互斥，优先级 样本 > 一句话 > 只筛选）：
 *   seed  以某个候选人为样本找相似的人（从候选人抽屉「关联」页签跳来）
 *   q     一句话描述要找的人（「做过医疗器械销售、会英语」）→ 向量检索；
 *         向量不可用（生产 embedding 未修通 / 没人有向量）自动退回关键字：拆词后按命中词数排序，页面提示
 *         手机号 / 邮箱 / #编号 这类精确查询直接走关键字
 *   无    只按筛选条件列出，最近收到的在前
 *   conds 一句话先经 recruitParseQuery 拆成条件（三语关键字 + 必须 / 加分，includes/recruit_query.php），页面改过后回传：
 *         按条件在完整档案（profile_json，含经历描述、语言、地点）里匹配——必须条件没中的排除，命中越多越靠前；
 *         有向量时再叠加语义相关度（各占一半）。每人返回命中了哪几条，页面逐条打勾
 * facet 只在某一维度上检索（技能 / 经历 / 行业 / 概要）——「只按行业」= 找同行业的人。
 * 筛选：最低学历 / 行业 / 最少年限 / 状态 / 归属（同人才库 recruitCandidateFilterSql）；黑名单永远排除。
 * 数据范围同人才库：只有 recruit 的人只在自己名下检索（recruitScopeOwner）。
 *
 * 人才池（recruit_pools + recruit_pool_members）：保存的检索条件 + 手动挑进来的成员。
 *   打开 = 成员在前（标「池内」），再按保存的条件实时检索——新进来的简历自动出现。
 *   可见：private 只有自己 / all 招聘组都能看。改条件、删池：创建人或 recruit_admin；加减成员：能看到就能加。
 */

const RECRUIT_SEARCH_LIMIT = 100;
const RECRUIT_SEARCH_FILTER_KEYS = ['min_edu', 'industry', 'min_years', 'status', 'owner_id'];

/** 一句话拆成关键字：按空白和中英文标点切，去重，丢 1 个字符的，最多 8 个 */
function recruitSearchTokens(string $q): array {
    $parts = preg_split('/[\s,，、;；\/|。.]+/u', mb_strtolower(trim($q))) ?: [];
    $out = [];
    foreach ($parts as $p) if (mb_strlen($p) >= 2 && !in_array($p, $out, true)) $out[] = $p;
    return array_slice($out, 0, 8);
}

/**
 * 跑一次检索，返回排好序的 [candidate_id => ['score' => ?float, 'facet' => string, 'facets' => array, 'hits' => ?int]]
 * @return array{mode: string, fallback: bool, rank: array, seed_has_vector?: bool, tokens?: array}
 */
function recruitSearchRun(PDO $pdo, array $p, ?int $scope, ?callable $embed = null): array {
    recruitLoadCore();
    require_once __DIR__ . '/../recruit_query.php';
    $facet = in_array($p['facet'] ?? '', RECRUIT_FACETS, true) ? (string)$p['facet'] : '';
    $weights = $facet !== '' ? [$facet => 1.0] : null;
    $f = array_intersect_key($p, array_flip(RECRUIT_SEARCH_FILTER_KEYS)) + ['exclude_blacklist' => 1];
    [$w, $a] = recruitCandidateFilterSql($f, $scope);
    $vecPool = function () use ($pdo, $w, $a) {
        $st = $pdo->prepare("SELECT c.id FROM recruit_candidates c WHERE $w AND c.vec_rev>0 LIMIT 20000");
        $st->execute($a);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };

    $seed = (int)($p['seed'] ?? 0);
    if ($seed > 0) {
        $has = (int)$pdo->query("SELECT vec_rev FROM recruit_candidates WHERE id=$seed")->fetchColumn() > 0;
        $sim = $has ? recruitSimilarCandidates($pdo, $seed, $vecPool(), RECRUIT_SEARCH_LIMIT, $weights) : [];
        $rank = [];
        foreach ($sim as $id => $s) $rank[$id] = ['score' => $s['score'], 'facet' => '', 'facets' => $s['facets'], 'hits' => null];
        return ['mode' => 'seed', 'fallback' => false, 'rank' => $rank, 'seed_has_vector' => $has];
    }

    $conds = recruitNormalizeConds($p['conds'] ?? []);
    if ($conds) return recruitSearchByConds($pdo, $conds, trim((string)($p['semantic_query'] ?? $p['q'] ?? '')), $w, $a, $vecPool, $embed, $weights);

    $q = trim((string)($p['q'] ?? ''));
    $fallback = false;
    if ($q !== '' && !recruitIsExactQuery($q)) {
        $pool = $vecPool();
        $res = $pool ? recruitSemanticSearch($pdo, $q, $pool, $embed, $weights) : ['ok' => false];
        if (!empty($res['ok'])) {
            $min = (float)(recruitSemConfig($pdo)['search_min'] ?? 0.5);
            $sem = array_filter($res['scores'], fn($s) => $s['score'] >= $min);
            uasort($sem, fn($x, $y) => $y['score'] <=> $x['score']);
            $rank = [];
            foreach (array_slice($sem, 0, RECRUIT_SEARCH_LIMIT, true) as $id => $s) $rank[$id] = $s + ['hits' => null];
            return ['mode' => 'semantic', 'fallback' => false, 'rank' => $rank];
        }
        $fallback = true;
    }

    if ($q !== '') {
        // 精确查询（号码 / 邮箱 / #编号）照人才库的写法；其余拆词，任一词命中即召回，按命中词数排
        if (recruitIsExactQuery($q)) {
            [$wk, $ak] = recruitCandidateFilterSql($f + ['keyword' => $q], $scope);
            $st = $pdo->prepare("SELECT c.id FROM recruit_candidates c WHERE $wk ORDER BY c.last_received_at DESC, c.id DESC LIMIT " . RECRUIT_SEARCH_LIMIT);
            $st->execute($ak);
            $rank = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $rank[(int)$id] = ['score' => null, 'facet' => '', 'facets' => [], 'hits' => 1];
            return ['mode' => 'keyword', 'fallback' => $fallback, 'rank' => $rank, 'tokens' => [$q]];
        }
        $tokens = recruitSearchTokens($q) ?: [mb_strtolower($q)];
        $or = []; $args = $a;
        foreach ($tokens as $t) { $or[] = '(c.search_text LIKE ? OR c.name LIKE ?)'; $l = '%' . addcslashes($t, '%_\\') . '%'; $args[] = $l; $args[] = $l; }
        $st = $pdo->prepare("SELECT c.id, LOWER(" . dbConcat("COALESCE(c.search_text,'')", "' '", "COALESCE(c.name,'')") . ") txt FROM recruit_candidates c
                             WHERE $w AND (" . implode(' OR ', $or) . ") ORDER BY c.last_received_at DESC, c.id DESC LIMIT 500");
        $st->execute($args);
        $hits = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = 0;
            foreach ($tokens as $t) if (mb_strpos((string)$r['txt'], $t) !== false) $n++;
            if ($n > 0) $hits[(int)$r['id']] = $n;
        }
        arsort($hits);   // PHP 8 排序稳定：同命中数保持「最近收到在前」
        $rank = [];
        foreach (array_slice($hits, 0, RECRUIT_SEARCH_LIMIT, true) as $id => $n)
            $rank[$id] = ['score' => round($n / count($tokens), 4), 'facet' => '', 'facets' => [], 'hits' => $n];
        return ['mode' => 'keyword', 'fallback' => $fallback, 'rank' => $rank, 'tokens' => $tokens];
    }

    $st = $pdo->prepare("SELECT c.id FROM recruit_candidates c WHERE $w ORDER BY c.last_received_at DESC, c.id DESC LIMIT " . RECRUIT_SEARCH_LIMIT);
    $st->execute($a);
    $rank = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $rank[(int)$id] = ['score' => null, 'facet' => '', 'facets' => [], 'hits' => null];
    return ['mode' => 'filter', 'fallback' => false, 'rank' => $rank];
}

/** 按拆好的条件检索（见文件头 conds）。$w/$a = 筛选 SQL，$vecPool = 有向量的候选人 id（惰性） */
function recruitSearchByConds(PDO $pdo, array $conds, string $sq, string $w, array $a, callable $vecPool, ?callable $embed, ?array $weights): array {
    $kws = [];
    foreach ($conds as $c) foreach ($c['keywords'] as $k) $kws[$k] = true;
    $kws = array_slice(array_keys($kws), 0, 60);
    $or = []; $args = $a;
    foreach ($kws as $k) { $or[] = 'c.profile_json LIKE ? OR c.name LIKE ?'; $l = '%' . addcslashes($k, '%_\\') . '%'; $args[] = $l; $args[] = $l; }
    $st = $pdo->prepare("SELECT c.id, c.name, c.city, c.profile_json FROM recruit_candidates c
                         WHERE $w AND (" . implode(' OR ', $or) . ") ORDER BY c.last_received_at DESC, c.id DESC LIMIT 2000");
    $st->execute($args);
    $kwHit = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s = recruitCondScore(mb_strtolower($r['name'] . ' ' . $r['city'] . ' ' . $r['profile_json']), $conds);
        if ($s !== null && $s['hits']) $kwHit[(int)$r['id']] = $s;
    }
    // 语义：有向量就叠加；没有必须条件时，语义够高但关键字一条没中的人也召回
    $sem = [];
    if ($sq !== '') {
        $pool = $vecPool();
        $res = $pool ? recruitSemanticSearch($pdo, $sq, $pool, $embed, $weights) : ['ok' => false];
        if (!empty($res['ok'])) $sem = $res['scores'];
    }
    $hasMust = (bool)array_filter($conds, fn($c) => !empty($c['must']));
    $min = (float)(recruitSemConfig($pdo)['search_min'] ?? 0.5);
    $rank = [];
    foreach ($kwHit as $id => $s) {
        $sm = $sem[$id] ?? null;
        $rank[$id] = ['score' => $sem ? round(0.5 * $s['score'] + 0.5 * max(0, (float)($sm['score'] ?? 0)), 4) : $s['score'],
                      'facet' => $sm['facet'] ?? '', 'facets' => $sm['facets'] ?? [], 'hits' => count($s['hits']), 'hit_conds' => $s['hits']];
    }
    if (!$hasMust) foreach ($sem as $id => $sm) {
        if (!isset($rank[$id]) && $sm['score'] >= $min)
            $rank[$id] = ['score' => round(0.5 * $sm['score'], 4), 'facet' => $sm['facet'], 'facets' => $sm['facets'] ?? [], 'hits' => 0, 'hit_conds' => []];
    }
    uasort($rank, fn($x, $y) => $y['score'] <=> $x['score']);
    return ['mode' => 'conds', 'fallback' => false, 'semantic' => (bool)$sem, 'rank' => array_slice($rank, 0, RECRUIT_SEARCH_LIMIT, true)];
}

/** 能看到的池：自己建的 + 公开的。看不到 / 不存在 → 404 */
function recruitPoolGet(PDO $pdo, int $uid, int $id): array {
    $st = $pdo->prepare("SELECT * FROM recruit_pools WHERE id=?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p || ((int)$p['created_by'] !== $uid && $p['visibility'] !== 'all')) recruitErr('notFound', 'pool not found', 404);
    return $p;
}

function recruitPoolCanEdit(PDO $pdo, int $uid, array $p): bool {
    return (int)$p['created_by'] === $uid || userHasModule($pdo, $uid, 'recruit_admin');
}

/** 池的对外形态：条件解开给页面回填 */
function recruitPoolOut(PDO $pdo, int $uid, array $p): array {
    $f = json_decode((string)$p['filters_json'], true) ?: [];
    $p['conds'] = $f['_conds'] ?? [];
    $p['semantic_query'] = (string)($f['_sq'] ?? '');
    unset($f['_conds'], $f['_sq'], $p['filters_json']);
    $p['filters'] = $f ?: new stdClass();
    $p['can_edit'] = recruitPoolCanEdit($pdo, $uid, $p);
    return $p;
}

/** 建池脚本没重跑时，池相关接口给明确提示而不是 SQL 原文 */
function recruitPoolTableCheck(PDO $pdo): void {
    try { $pdo->query("SELECT 1 FROM recruit_pools LIMIT 1"); $pdo->query("SELECT 1 FROM recruit_pool_members LIMIT 1"); }
    catch (PDOException $e) { recruitErr('poolTableMissing', 'recruit_pools missing'); }
}

function handleRecruitSearch(PDO $pdo): void {
    [$uid] = recruitAuth($pdo);
    $scope = recruitScopeOwner($pdo, $uid);
    $p = $_GET;
    $p['conds'] = is_string($p['conds'] ?? null) ? (json_decode($p['conds'], true) ?: []) : [];
    $poolId = (int)($p['pool_id'] ?? 0);
    $members = [];
    if ($poolId > 0) {
        recruitPoolTableCheck($pdo);
        recruitPoolGet($pdo, $uid, $poolId);
        $st = $pdo->prepare("SELECT m.candidate_id FROM recruit_pool_members m JOIN recruit_candidates c ON c.id=m.candidate_id
                             WHERE m.pool_id=?" . ($scope !== null ? ' AND ' . recruitVisibleSql($scope) : '') . " ORDER BY m.created_at DESC");
        $st->execute([$poolId]);
        $members = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if ((int)($p['seed'] ?? 0) > 0) recruitGuardCandidate($pdo, $uid, (int)$p['seed']);   // 样本也要在数据范围内
    $r = recruitSearchRun($pdo, $p, $scope);
    // 成员在前（没被检索命中的也列出来），再接检索结果
    $order = [];
    foreach ($members as $id) $order[$id] = $r['rank'][$id] ?? ['score' => null, 'facet' => '', 'facets' => [], 'hits' => null];
    foreach ($r['rank'] as $id => $s) $order[$id] ??= $s;

    $rows = [];
    if ($order) {
        $ids = implode(',', array_keys($order));
        $byId = array_column($pdo->query("SELECT c.id, c.name, c.city, c.latest_title, c.latest_company, c.years_exp, c.highest_edu, c.latest_school,
                c.industries, c.status, c.owner_user_id, c.owner_user_name, c.last_received_at, c.vec_rev
            FROM recruit_candidates c WHERE c.id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $best = [];
        foreach ($pdo->query("SELECT cj.candidate_id, cj.job_id, cj.ai_score, cj.human_score, cj.stage, j.title job_title, p.name project_name
                              FROM recruit_candidate_jobs cj JOIN recruit_jobs j ON j.id=cj.job_id JOIN recruit_projects p ON p.id=j.project_id
                              WHERE cj.candidate_id IN ($ids) AND cj.stage<>'removed'
                              ORDER BY COALESCE(cj.human_score, cj.ai_score) DESC, cj.id")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $best[(int)$l['candidate_id']] ??= $l;
        }
        $memberSet = array_flip($members);
        foreach ($order as $id => $s) {
            if (!isset($byId[$id])) continue;
            $c = $byId[$id];
            $c['industries'] = array_values(array_filter(explode(',', (string)$c['industries'])));
            $rows[] = $c + ['score' => $s['score'], 'hit_facet' => $s['facet'], 'facets' => $s['facets'] ?: new stdClass(), 'hits' => $s['hits'],
                            'hit_conds' => $s['hit_conds'] ?? [], 'in_pool' => isset($memberSet[$id]), 'best_match' => $best[$id] ?? null];
        }
    }
    $seed = null;
    if ((int)($p['seed'] ?? 0) > 0) {
        $sid = (int)$p['seed'];
        $st = $pdo->prepare("SELECT id, name, latest_title, latest_company FROM recruit_candidates WHERE id=?");
        $st->execute([$sid]);
        $seed = ($st->fetch(PDO::FETCH_ASSOC) ?: null);
        if ($seed) $seed['has_vector'] = !empty($r['seed_has_vector']);
    }
    jsonResponse(['success' => true, 'data' => $rows, 'mode' => $r['mode'], 'fallback' => $r['fallback'], 'semantic' => !empty($r['semantic']),
                  'tokens' => $r['tokens'] ?? [], 'seed' => $seed, 'members' => count($members)]);
}

/** 一句话 → 检索条件（页面「AI 拆解」）。AI 不可用自动按规则拆，页面标出来 */
function handleRecruitParseQuery(PDO $pdo, array $in): void {
    recruitAuth($pdo);
    recruitLoadCore();
    require_once __DIR__ . '/../recruit_query.php';
    jsonResponse(['success' => true, 'data' => recruitParseQuery($pdo, (string)($in['q'] ?? ''))]);
}

function handleRecruitPools(PDO $pdo): void {
    [$uid] = recruitAuth($pdo);
    recruitPoolTableCheck($pdo);
    $st = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM recruit_pool_members m WHERE m.pool_id=p.id) member_count,
                                COALESCE(sc.name,'') seed_name
                         FROM recruit_pools p LEFT JOIN recruit_candidates sc ON sc.id=p.seed_id
                         WHERE p.created_by=? OR p.visibility='all' ORDER BY p.updated_at DESC, p.id DESC");
    $st->execute([$uid]);
    jsonResponse(['success' => true, 'data' => array_map(fn($p) => recruitPoolOut($pdo, $uid, $p), $st->fetchAll(PDO::FETCH_ASSOC))]);
}

function handleRecruitSavePool(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo);
    recruitPoolTableCheck($pdo);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 191);
    if ($name === '') recruitErr('nameRequired', 'name required');
    $filters = [];
    foreach (RECRUIT_SEARCH_FILTER_KEYS as $k) {
        $v = $in['filters'][$k] ?? null;
        if ($v !== null && $v !== '' && is_scalar($v)) $filters[$k] = (string)$v;
    }
    recruitLoadCore();
    require_once __DIR__ . '/../recruit_query.php';
    // 拆好的条件（页面改过的版本）随池保存，打开池时原样回填，不再调模型
    if ($c = recruitNormalizeConds($in['conds'] ?? [])) $filters['_conds'] = $c;
    if (($sq = mb_substr(trim((string)($in['semantic_query'] ?? '')), 0, 300)) !== '') $filters['_sq'] = $sq;
    $vals = [
        'name' => $name,
        'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 1000),
        'query_text' => mb_substr(trim((string)($in['query_text'] ?? '')), 0, 500),
        'facet' => in_array($in['facet'] ?? '', RECRUIT_FACETS, true) ? (string)$in['facet'] : '',
        'seed_id' => max(0, (int)($in['seed_id'] ?? 0)),
        'filters_json' => json_encode($filters, JSON_UNESCAPED_UNICODE),
        'visibility' => ($in['visibility'] ?? '') === 'all' ? 'all' : 'private',
    ];
    if ($vals['seed_id'] > 0) recruitGuardCandidate($pdo, $uid, $vals['seed_id']);
    $id = (int)($in['id'] ?? 0);
    if ($id > 0) {
        $p = recruitPoolGet($pdo, $uid, $id);
        if (!recruitPoolCanEdit($pdo, $uid, $p)) recruitErr('forbidden', 'forbidden', 403);
        $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($vals)));
        $pdo->prepare("UPDATE recruit_pools SET $set, updated_at=(" . dbNow() . ") WHERE id=?")->execute(array_merge(array_values($vals), [$id]));
    } else {
        $cols = implode(', ', array_keys($vals));
        $pdo->prepare("INSERT INTO recruit_pools ($cols, created_by, created_by_name, created_at, updated_at)
                       VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ", ?, ?, (" . dbNow() . "), (" . dbNow() . "))")
            ->execute(array_merge(array_values($vals), [$uid, $uname]));
        $id = (int)$pdo->lastInsertId();
    }
    $add = array_values(array_unique(array_filter(array_map('intval', (array)($in['add_members'] ?? [])))));
    if ($add) recruitPoolAddMembers($pdo, $uid, $uname, $id, $add);
    jsonResponse(['success' => true, 'data' => recruitPoolOut($pdo, $uid, recruitPoolGet($pdo, $uid, $id))]);
}

function handleRecruitDeletePool(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo);
    recruitPoolTableCheck($pdo);
    $p = recruitPoolGet($pdo, $uid, (int)($in['id'] ?? 0));
    if (!recruitPoolCanEdit($pdo, $uid, $p)) recruitErr('forbidden', 'forbidden', 403);
    $pdo->prepare("DELETE FROM recruit_pool_members WHERE pool_id=?")->execute([(int)$p['id']]);
    $pdo->prepare("DELETE FROM recruit_pools WHERE id=?")->execute([(int)$p['id']]);
    logOperation($pdo, $uid, $uname, 'recruit_pool_delete', 'recruit_pools', (int)$p['id'], $p['name']);
    jsonResponse(['success' => true]);
}

/** 只加看得到的人（数据范围外的静默跳过）。@return int 新加的人数 */
function recruitPoolAddMembers(PDO $pdo, int $uid, string $uname, int $poolId, array $ids): int {
    $scope = recruitScopeOwner($pdo, $uid);
    $ids = array_slice($ids, 0, 500);
    $ok = array_map('intval', $pdo->query("SELECT id FROM recruit_candidates WHERE id IN (" . implode(',', $ids) . ")"
        . ($scope !== null ? ' AND ' . recruitVisibleSql($scope, 'recruit_candidates') : ''))->fetchAll(PDO::FETCH_COLUMN));
    $ins = $pdo->prepare(dbInsertIgnore() . " recruit_pool_members (pool_id, candidate_id, added_by, added_by_name, created_at) VALUES (?, ?, ?, ?, (" . dbNow() . "))");
    $n = 0;
    foreach ($ok as $cid) { $ins->execute([$poolId, $cid, $uid, $uname]); $n += $ins->rowCount(); }
    return $n;
}

/** 加 / 移成员：能看到这个池就能改成员（大家一起往公开池里攒人） */
function handleRecruitPoolMembers(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo);
    recruitPoolTableCheck($pdo);
    $p = recruitPoolGet($pdo, $uid, (int)($in['pool_id'] ?? 0));
    $add = array_values(array_unique(array_filter(array_map('intval', (array)($in['add'] ?? [])))));
    $rm = array_values(array_unique(array_filter(array_map('intval', (array)($in['remove'] ?? [])))));
    $added = $add ? recruitPoolAddMembers($pdo, $uid, $uname, (int)$p['id'], $add) : 0;
    $removed = 0;
    if ($rm) {
        $st = $pdo->prepare("DELETE FROM recruit_pool_members WHERE pool_id=? AND candidate_id IN (" . implode(',', $rm) . ")");
        $st->execute([(int)$p['id']]);
        $removed = $st->rowCount();
    }
    $pdo->prepare("UPDATE recruit_pools SET updated_at=(" . dbNow() . ") WHERE id=?")->execute([(int)$p['id']]);
    jsonResponse(['success' => true, 'data' => ['added' => $added, 'removed' => $removed]]);
}

/** 批量加入职位（检索结果勾选后）：只挂看得到的人、只挂在招职位；已在该职位的跳过。完了拉一轮 AI 打分 */
function handleRecruitBulkAddMatch(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo);
    $jid = (int)($in['job_id'] ?? 0);
    $st = $pdo->prepare("SELECT COUNT(*) FROM recruit_jobs j JOIN recruit_projects p ON p.id=j.project_id WHERE j.id=? AND j.status='open' AND p.status='open'");
    $st->execute([$jid]);
    if ((int)$st->fetchColumn() === 0) recruitErr('notFound', 'job not open');
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($in['candidate_ids'] ?? []))))), 0, 200);
    if (!$ids) recruitErr('badRequest', 'no candidates');
    $scope = recruitScopeOwner($pdo, $uid);
    $ok = array_map('intval', $pdo->query("SELECT id FROM recruit_candidates WHERE id IN (" . implode(',', $ids) . ")"
        . ($scope !== null ? ' AND ' . recruitVisibleSql($scope, 'recruit_candidates') : ''))->fetchAll(PDO::FETCH_COLUMN));
    $added = 0;
    foreach ($ok as $cid) if (recruitAddMatchOne($pdo, $uid, $uname, $cid, $jid) !== null) $added++;
    if ($added > 0) {
        require_once __DIR__ . '/../recruit_pipeline.php';
        recruitKickPipeline($pdo, '批量加入职位');
    }
    jsonResponse(['success' => true, 'data' => ['added' => $added, 'skipped' => count($ids) - $added]]);
}
