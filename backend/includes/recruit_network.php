<?php
/**
 * OpenHunter · 关系网络（2026-09-26「像 Obsidian 那样画出候选人、企业、行业、学校之间的关系，选行业看到这个行业所有相关候选人，
 * 选一个人看到和他相关的关系，逐个击破」）。
 *
 * 不另起图数据库：关系都在 MySQL 里，按「焦点」现拼一张子图返回给前端（G6 力导向图）：
 *   候选人 ─任职(在职/曾任)─ 企业 ─属于─ 赛道 ─属于─ 行业
 *   候选人 ─就读─ 学校            候选人 ─在我们的项目里(阶段)─ 招聘项目（客户）
 *   候选人 ─同事(同一家、时间重叠)─ 候选人（只在看某个人时现算）
 * 数据来源：recruit_candidate_companies / recruit_candidate_schools（企业库定时任务挂靠时写）、recruit_candidate_jobs、recruit_projects。
 * ⛔ 为什么不用 Neo4j：量级（几百～几万节点）MySQL 足够；生产机 2 核 / 3.5G / 无 swap，再跑一个 JVM 图库就是 2026-07-22 那次 OOM 的翻版。
 * 看不到全部的招聘专员（recruitScopeOwner）只看到自己名下的人。
 */

require_once __DIR__ . '/recruit_company.php';

const RECRUIT_NET_FOCUS = ['industry', 'segment', 'company', 'candidate', 'school', 'project'];
const RECRUIT_NET_WITH = ['school', 'project', 'flow', 'colleague'];   // 可选展开：学校 / 我们的项目 / 职业流向（他们还去过哪） / 同事

final class RecruitNet {
    public array $nodes = [];
    public array $edges = [];
    public function __construct(public int $max = 300) {}
    public function full(): bool { return count($this->nodes) >= $this->max; }
    public function node(string $id, string $type, string $label, array $extra = []): bool {
        if (isset($this->nodes[$id])) { $this->nodes[$id] = $extra + $this->nodes[$id]; return true; }
        if ($this->full()) return false;
        $this->nodes[$id] = ['id' => $id, 'type' => $type, 'label' => mb_substr($label, 0, 60)] + $extra;
        return true;
    }
    public function edge(string $a, string $b, string $rel, array $extra = []): void {
        if (!isset($this->nodes[$a], $this->nodes[$b]) || $a === $b) return;
        $k = "$a|$b|$rel";
        $this->edges[$k] = ['id' => $k, 'source' => $a, 'target' => $b, 'rel' => $rel] + $extra + ($this->edges[$k] ?? []);
    }
    public function out(): array {
        $deg = [];
        foreach ($this->edges as $e) { $deg[$e['source']] = ($deg[$e['source']] ?? 0) + 1; $deg[$e['target']] = ($deg[$e['target']] ?? 0) + 1; }
        $nodes = array_map(fn($n) => $n + ['degree' => $deg[$n['id']] ?? 0], array_values($this->nodes));
        $byType = [];
        foreach ($nodes as $n) $byType[$n['type']] = ($byType[$n['type']] ?? 0) + 1;
        return ['nodes' => $nodes, 'edges' => array_values($this->edges), 'counts' => $byType, 'truncated' => $this->full()];
    }
}

function recruitNetScopeSql(?int $scope, string $alias = 'c'): string { return $scope !== null ? ' AND ' . recruitVisibleSql($scope, $alias) : ''; }   // 自己名下 + 自己链接投进来的

/** 候选人节点（批量） */
function recruitNetAddCandidates(PDO $pdo, RecruitNet $g, array $ids, ?int $scope): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $rows = $pdo->query("SELECT c.id, c.name, c.latest_title, c.latest_company, c.status, c.owner_user_name, c.years_exp FROM recruit_candidates c
                         WHERE c.id IN (" . implode(',', $ids) . ")" . recruitNetScopeSql($scope))->fetchAll(PDO::FETCH_ASSOC);
    $ok = [];
    foreach ($rows as $r) {
        if ($g->node("cand:{$r['id']}", 'candidate', $r['name'] ?: recruitCandCode((int)$r['id']),
            ['sub' => trim(($r['latest_title'] ?: '') . ($r['latest_company'] ? ' @ ' . $r['latest_company'] : '')), 'code' => recruitCandCode((int)$r['id']),
             'status' => $r['status'], 'owner' => $r['owner_user_name'], 'years' => $r['years_exp'], 'ref' => (int)$r['id']])) $ok[] = (int)$r['id'];
    }
    return $ok;
}

/** $taxonomy：full = 公司→赛道→行业；industry = 公司直连行业（总图谱，少一层更清楚）；none = 不带 */
function recruitNetAddCompanies(PDO $pdo, RecruitNet $g, array $ids, ?int $scope, string $taxonomy = 'full'): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return;
    $rows = $pdo->query("SELECT co.id, co.name, co.industry, co.segment_id, co.region, co.country, co.tier, co.rank_in_segment,
                                s.name_zh, s.name_en, s.name_id, s.industry seg_ind,
                                (SELECT COUNT(DISTINCT x.candidate_id) FROM recruit_candidate_companies x JOIN recruit_candidates c ON c.id=x.candidate_id
                                 WHERE x.company_id=co.id" . recruitNetScopeSql($scope) . ") people
                         FROM recruit_companies co LEFT JOIN recruit_segments s ON s.id=co.segment_id WHERE co.id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (!$g->node("co:{$r['id']}", 'company', $r['name'], ['industry' => $r['industry'], 'region' => $r['region'], 'country' => $r['country'],
            'tier' => $r['tier'], 'rank' => $r['rank_in_segment'] !== null ? (int)$r['rank_in_segment'] : null, 'heat' => (int)$r['people'], 'ref' => (int)$r['id']])) continue;
        if ($taxonomy === 'none') continue;
        if ($taxonomy === 'full' && (int)$r['segment_id'] > 0) {
            $g->node("seg:{$r['segment_id']}", 'segment', $r['name_zh'] ?: $r['name_en'], ['names' => ['zh' => $r['name_zh'], 'en' => $r['name_en'], 'id' => $r['name_id']],
                'industry' => $r['seg_ind'], 'ref' => (int)$r['segment_id']]);
            $g->edge("co:{$r['id']}", "seg:{$r['segment_id']}", 'in_segment');
            if ($r['seg_ind']) { $g->node("ind:{$r['seg_ind']}", 'industry', $r['seg_ind'], ['industry' => $r['seg_ind'], 'ref' => $r['seg_ind']]); $g->edge("seg:{$r['segment_id']}", "ind:{$r['seg_ind']}", 'in_industry'); }
        } elseif ($r['industry'] !== '') {
            $g->node("ind:{$r['industry']}", 'industry', $r['industry'], ['industry' => $r['industry'], 'ref' => $r['industry']]);
            $g->edge("co:{$r['id']}", "ind:{$r['industry']}", 'in_industry');
        }
    }
}

/** 候选人 → 企业 的任职边（在职 / 曾任、累计月数、职位） */
function recruitNetWorkEdges(PDO $pdo, RecruitNet $g, array $candIds, ?array $onlyCompanies = null): array {
    if (!$candIds) return [];
    $rows = $pdo->query("SELECT candidate_id, company_id, MAX(is_current) cur, SUM(months) m, MAX(title) title FROM recruit_candidate_companies
                         WHERE candidate_id IN (" . implode(',', array_map('intval', $candIds)) . ")"
                         . ($onlyCompanies !== null ? ($onlyCompanies ? ' AND company_id IN (' . implode(',', array_map('intval', $onlyCompanies)) . ')' : ' AND 1=0') : '')
                         . " GROUP BY candidate_id, company_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $g->edge("cand:{$r['candidate_id']}", "co:{$r['company_id']}", 'worked', ['current' => (int)$r['cur'] === 1, 'months' => (int)$r['m'], 'title' => $r['title']]);
    return array_values(array_unique(array_map(fn($r) => (int)$r['company_id'], $rows)));
}

function recruitNetSchools(PDO $pdo, RecruitNet $g, array $candIds, int $limit = 60): void {
    if (!$candIds) return;
    $rows = $pdo->query("SELECT candidate_id, school_key, MAX(school) school, MAX(degree) degree, MAX(major) major FROM recruit_candidate_schools
                         WHERE candidate_id IN (" . implode(',', array_map('intval', $candIds)) . ") GROUP BY candidate_id, school_key LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($rows as $r) {
        $id = 'sch:' . md5($r['school_key']);
        if (!isset($g->nodes[$id]) && $n++ >= $limit) continue;
        $g->node($id, 'school', $r['school'], ['ref' => $r['school_key']]);
        $g->edge("cand:{$r['candidate_id']}", $id, 'studied', ['degree' => $r['degree'], 'major' => $r['major']]);
    }
}

function recruitNetProjects(PDO $pdo, RecruitNet $g, array $candIds): void {
    if (!$candIds) return;
    $rows = $pdo->query("SELECT cj.candidate_id, cj.stage, p.id pid, p.name, p.kind, COALESCE(cu.name,'') grp FROM recruit_candidate_jobs cj
                         JOIN recruit_jobs j ON j.id=cj.job_id JOIN recruit_projects p ON p.id=j.project_id LEFT JOIN recruit_clients cu ON cu.id=p.customer_id
                         WHERE cj.stage NOT IN ('removed','suggested') AND cj.candidate_id IN (" . implode(',', array_map('intval', $candIds)) . ")")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $g->node("proj:{$r['pid']}", 'project', $r['name'], ['sub' => $r['grp'], 'kind' => $r['kind'], 'ref' => (int)$r['pid']]);
        $g->edge("cand:{$r['candidate_id']}", "proj:{$r['pid']}", 'pipeline', ['stage' => $r['stage']]);
    }
}

/**
 * 按焦点拼子图。$with：school / project / flow / colleague。@return array{nodes, edges, counts, truncated, focus}
 */
function recruitNetwork(PDO $pdo, string $type, string $ref, array $with, ?int $scope, int $max = 300): array {
    if (!in_array($type, RECRUIT_NET_FOCUS, true)) throw new InvalidArgumentException('bad focus');
    $with = array_flip(array_intersect($with, RECRUIT_NET_WITH));
    $g = new RecruitNet(max(50, min(600, $max)));
    $focus = '';
    $cands = [];
    $companyIdsFor = function (string $where, array $args, int $limit) use ($pdo, $scope): array {
        $st = $pdo->prepare("SELECT co.id FROM recruit_companies co WHERE co.active=1 AND co.merged_into=0 AND $where
                             ORDER BY co.rank_in_segment IS NULL, co.rank_in_segment,
                                      (SELECT COUNT(DISTINCT x.candidate_id) FROM recruit_candidate_companies x JOIN recruit_candidates c ON c.id=x.candidate_id
                                       WHERE x.company_id=co.id" . recruitNetScopeSql($scope) . ") DESC LIMIT $limit");
        $st->execute($args);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };
    $candsAt = function (array $coIds, int $limit) use ($pdo, $scope): array {
        if (!$coIds) return [];
        return array_map('intval', $pdo->query("SELECT x.candidate_id FROM recruit_candidate_companies x JOIN recruit_candidates c ON c.id=x.candidate_id
                                                WHERE x.company_id IN (" . implode(',', $coIds) . ")" . recruitNetScopeSql($scope) . "
                                                GROUP BY x.candidate_id ORDER BY MAX(x.is_current) DESC, SUM(x.months) DESC LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN));
    };

    if ($type === 'industry' || $type === 'segment') {
        if ($type === 'industry') {
            if (!in_array($ref, RECRUIT_INDUSTRIES, true)) throw new InvalidArgumentException('bad industry');
            $focus = "ind:$ref";
            $g->node($focus, 'industry', $ref, ['industry' => $ref, 'ref' => $ref, 'focus' => true]);
            $coIds = $companyIdsFor('co.industry=?', [$ref], 60);
        } else {
            $st = $pdo->prepare("SELECT id, name_zh, name_en, name_id, industry FROM recruit_segments WHERE id=?");
            $st->execute([(int)$ref]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
            if (!$s) throw new InvalidArgumentException('not found');
            $focus = "seg:{$s['id']}";
            $g->node($focus, 'segment', $s['name_zh'] ?: $s['name_en'], ['names' => ['zh' => $s['name_zh'], 'en' => $s['name_en'], 'id' => $s['name_id']], 'industry' => $s['industry'], 'ref' => (int)$s['id'], 'focus' => true]);
            $coIds = $companyIdsFor('co.segment_id=?', [(int)$s['id']], 60);
        }
        recruitNetAddCompanies($pdo, $g, $coIds, $scope);
        $cands = recruitNetAddCandidates($pdo, $g, $candsAt($coIds, 160), $scope);
        recruitNetWorkEdges($pdo, $g, $cands, $coIds);
    } elseif ($type === 'company') {
        $focus = 'co:' . (int)$ref;
        recruitNetAddCompanies($pdo, $g, [(int)$ref], $scope);
        if (!isset($g->nodes[$focus])) throw new InvalidArgumentException('not found');
        $g->nodes[$focus]['focus'] = true;
        $cands = recruitNetAddCandidates($pdo, $g, $candsAt([(int)$ref], 160), $scope);
        if (isset($with['flow'])) {   // 这些人还去过哪：人才从哪来、往哪去（挖人的来源 / 去向）
            $other = $cands ? array_map('intval', $pdo->query("SELECT company_id FROM recruit_candidate_companies WHERE candidate_id IN (" . implode(',', $cands) . ")
                                                                AND company_id<>" . (int)$ref . " GROUP BY company_id ORDER BY COUNT(DISTINCT candidate_id) DESC LIMIT 60")->fetchAll(PDO::FETCH_COLUMN)) : [];
            recruitNetAddCompanies($pdo, $g, $other, $scope, 'none');
            recruitNetWorkEdges($pdo, $g, $cands, array_merge([(int)$ref], $other));
        } else recruitNetWorkEdges($pdo, $g, $cands, [(int)$ref]);
    } elseif ($type === 'candidate') {
        $cid = (int)$ref;
        $focus = "cand:$cid";
        if (!recruitNetAddCandidates($pdo, $g, [$cid], $scope)) throw new InvalidArgumentException('not found');
        $g->nodes[$focus]['focus'] = true;
        $cands = [$cid];
        $coIds = array_map('intval', $pdo->query("SELECT DISTINCT company_id FROM recruit_candidate_companies WHERE candidate_id=$cid")->fetchAll(PDO::FETCH_COLUMN));
        recruitNetAddCompanies($pdo, $g, $coIds, $scope, 'industry');
        recruitNetWorkEdges($pdo, $g, [$cid]);
        $with['school'] = 0; $with['project'] = 0;   // 看一个人：学校、我们的项目总是带上
        {
            // 同事：同一家公司、任职时间有重叠（缺日期的不算，免得把不同时期的人连在一起）
            $col = $pdo->query("SELECT DISTINCT y.candidate_id, y.company_id FROM recruit_candidate_companies x JOIN recruit_candidate_companies y
                                  ON y.company_id=x.company_id AND y.candidate_id<>x.candidate_id
                                JOIN recruit_candidates c ON c.id=y.candidate_id
                                WHERE x.candidate_id=$cid AND x.start_ym<>'' AND y.start_ym<>''
                                  AND x.start_ym <= COALESCE(NULLIF(y.end_ym,''), '9999-12') AND y.start_ym <= COALESCE(NULLIF(x.end_ym,''), '9999-12')"
                                . recruitNetScopeSql($scope) . " LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
            $ok = array_flip(recruitNetAddCandidates($pdo, $g, array_column($col, 'candidate_id'), $scope));
            foreach ($col as $r) if (isset($ok[(int)$r['candidate_id']])) {
                $g->edge($focus, "cand:{$r['candidate_id']}", 'colleague', ['company_id' => (int)$r['company_id']]);
                $g->edge("cand:{$r['candidate_id']}", "co:{$r['company_id']}", 'worked', []);
            }
            // 校友：同一所学校（最多 30 人）
            $alu = $pdo->query("SELECT DISTINCT y.candidate_id FROM recruit_candidate_schools x JOIN recruit_candidate_schools y ON y.school_key=x.school_key AND y.candidate_id<>x.candidate_id
                                JOIN recruit_candidates c ON c.id=y.candidate_id WHERE x.candidate_id=$cid" . recruitNetScopeSql($scope) . " LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
            $cands = array_merge($cands, recruitNetAddCandidates($pdo, $g, $alu, $scope));
        }
    } elseif ($type === 'school') {
        $focus = 'sch:' . md5($ref);
        $st = $pdo->prepare("SELECT MAX(school) FROM recruit_candidate_schools WHERE school_key=?");
        $st->execute([$ref]);
        $name = $st->fetchColumn();
        if (!$name) throw new InvalidArgumentException('not found');
        $g->node($focus, 'school', $name, ['ref' => $ref, 'focus' => true]);
        $st = $pdo->prepare("SELECT DISTINCT s.candidate_id FROM recruit_candidate_schools s JOIN recruit_candidates c ON c.id=s.candidate_id WHERE s.school_key=?" . recruitNetScopeSql($scope) . " LIMIT 160");
        $st->execute([$ref]);
        $cands = recruitNetAddCandidates($pdo, $g, $st->fetchAll(PDO::FETCH_COLUMN), $scope);
        $with['school'] = 0;
        $cur = $cands ? array_map('intval', $pdo->query("SELECT DISTINCT company_id FROM recruit_candidate_companies WHERE candidate_id IN (" . implode(',', $cands) . ")"
                                                      . (isset($with['flow']) ? '' : ' AND is_current=1') . " LIMIT 120")->fetchAll(PDO::FETCH_COLUMN)) : [];
        recruitNetAddCompanies($pdo, $g, $cur, $scope, 'industry');
        recruitNetWorkEdges($pdo, $g, $cands, $cur);
    } elseif ($type === 'project') {
        $st = $pdo->prepare("SELECT p.id, p.name, p.kind, COALESCE(cu.name,'') grp FROM recruit_projects p LEFT JOIN recruit_clients cu ON cu.id=p.customer_id WHERE p.id=?");
        $st->execute([(int)$ref]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new InvalidArgumentException('not found');
        $focus = "proj:{$p['id']}";
        $g->node($focus, 'project', $p['name'], ['sub' => $p['grp'], 'kind' => $p['kind'], 'ref' => (int)$p['id'], 'focus' => true]);
        $st = $pdo->prepare("SELECT DISTINCT cj.candidate_id FROM recruit_candidate_jobs cj JOIN recruit_jobs j ON j.id=cj.job_id JOIN recruit_candidates c ON c.id=cj.candidate_id
                             WHERE j.project_id=? AND cj.stage NOT IN ('removed','suggested')" . recruitNetScopeSql($scope) . " LIMIT 160");
        $st->execute([(int)$p['id']]);
        $cands = recruitNetAddCandidates($pdo, $g, $st->fetchAll(PDO::FETCH_COLUMN), $scope);
        $with['project'] = 0;
        $cur = $cands ? array_map('intval', $pdo->query("SELECT DISTINCT company_id FROM recruit_candidate_companies WHERE candidate_id IN (" . implode(',', $cands) . ") AND is_current=1 LIMIT 120")->fetchAll(PDO::FETCH_COLUMN)) : [];
        recruitNetAddCompanies($pdo, $g, $cur, $scope, 'industry');
        recruitNetWorkEdges($pdo, $g, $cands, $cur);
    }
    if (isset($with['school'])) recruitNetSchools($pdo, $g, $cands);
    if (isset($with['project'])) recruitNetProjects($pdo, $g, $cands);
    return $g->out() + ['focus' => $focus];
}

/**
 * 丝线图（lieflat B3 Thread Triptych，2026-09-26「用 lieflat 那个 skill」）：一人一根线 ① 学校 → ② 上一个行业 → ③ 现在的行业。
 * 现在 = 在职那段（没有在职取最近一段），上一个 = 紧接着的前一段（可能同行业）；只有一段经历的记 ''（页面显示「第一份工作」）。
 * 学校取最近毕业的那所；没有记 ''。$inds 非空 = 只要上一个或现在在这些行业里的人。@return array{rows:list, truncated:bool}
 */
function recruitNetworkThreads(PDO $pdo, ?int $scope, array $inds = [], int $limit = 800): array {
    $inds = array_values(array_intersect($inds, RECRUIT_INDUSTRIES));
    $rows = $pdo->query("SELECT x.candidate_id, co.industry, x.is_current, x.start_ym, x.end_ym, x.title, co.name co_name
                         FROM recruit_candidate_companies x JOIN recruit_companies co ON co.id=x.company_id AND co.merged_into=0
                         JOIN recruit_candidates c ON c.id=x.candidate_id WHERE co.industry<>''" . recruitNetScopeSql($scope) . "
                         ORDER BY x.candidate_id, x.is_current DESC, CASE WHEN x.end_ym='' THEN '9999-99' ELSE x.end_ym END DESC, x.start_ym DESC")->fetchAll(PDO::FETCH_ASSOC);
    $by = [];
    foreach ($rows as $r) $by[(int)$r['candidate_id']][] = $r;
    $out = [];
    foreach ($by as $cid => $st) {
        $cur = $st[0]; $prev = $st[1] ?? null;
        if ($inds && !in_array($cur['industry'], $inds, true) && !($prev && in_array($prev['industry'], $inds, true))) continue;
        $out[$cid] = ['id' => $cid, 'cur' => $cur['industry'], 'prev' => $prev['industry'] ?? '', 'current' => (int)$cur['is_current'] === 1,
                      'title' => $cur['title'], 'company' => $cur['co_name'], 'prev_company' => $prev['co_name'] ?? ''];
    }
    $truncated = count($out) > $limit;
    $out = array_slice($out, 0, $limit, true);
    if ($out) {
        $ids = implode(',', array_keys($out));
        foreach ($pdo->query("SELECT id, name FROM recruit_candidates WHERE id IN ($ids)")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $name) $out[(int)$id]['name'] = $name ?: recruitCandCode((int)$id);
        $seen = [];
        foreach ($pdo->query("SELECT candidate_id, school_key, school FROM recruit_candidate_schools WHERE candidate_id IN ($ids)
                              ORDER BY candidate_id, CASE WHEN end_ym='' THEN '0000' ELSE end_ym END DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (int)$r['candidate_id'];
            if (isset($seen[$cid])) continue;
            $seen[$cid] = 1;
            $out[$cid]['school_key'] = $r['school_key']; $out[$cid]['school'] = $r['school'];
        }
    }
    foreach ($out as &$o) { $o += ['school_key' => '', 'school' => '', 'name' => recruitCandCode((int)$o['id'])]; $o['code'] = recruitCandCode((int)$o['id']); }
    unset($o);
    return ['rows' => array_values($out), 'truncated' => $truncated];
}

/** 顶部搜索：候选人（姓名 / 编号）、企业（名字 / 别名）、学校、我们的招聘项目 */
function recruitNetworkSearch(PDO $pdo, string $q, ?int $scope): array {
    $q = trim($q);
    if ($q === '') return [];
    $like = '%' . $q . '%';
    $out = [];
    $candId = preg_match('/(\d{1,9})/', $q, $m) && stripos($q, 'bt') !== false ? (int)ltrim($m[1], '0') : 0;
    $st = $pdo->prepare("SELECT c.id, c.name, c.latest_title, c.latest_company FROM recruit_candidates c WHERE (c.name LIKE ?" . ($candId ? ' OR c.id=' . $candId : '') . ")"
                        . recruitNetScopeSql($scope) . " ORDER BY c.id DESC LIMIT 8");
    $st->execute([$like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type' => 'candidate', 'ref' => (string)$r['id'], 'label' => $r['name'] ?: recruitCandCode((int)$r['id']),
        'sub' => recruitCandCode((int)$r['id']) . ' · ' . trim($r['latest_title'] . ($r['latest_company'] ? ' @ ' . $r['latest_company'] : ''))];
    $st = $pdo->prepare("SELECT co.id, co.name, co.industry FROM recruit_companies co WHERE co.merged_into=0 AND co.active=1
                         AND (co.name LIKE ? OR EXISTS (SELECT 1 FROM recruit_company_aliases a WHERE a.company_id=co.id AND a.alias LIKE ?)) ORDER BY co.rank_in_segment IS NULL, co.id LIMIT 8");
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type' => 'company', 'ref' => (string)$r['id'], 'label' => $r['name'], 'industry' => $r['industry']];
    // 只数自己能看的候选人：只看自己名下的招聘专员不该知道别人名下有几个校友
    $st = $pdo->prepare("SELECT sc.school_key, MAX(sc.school) school, COUNT(DISTINCT sc.candidate_id) n FROM recruit_candidate_schools sc JOIN recruit_candidates c ON c.id=sc.candidate_id
                         WHERE sc.school LIKE ?" . recruitNetScopeSql($scope) . " GROUP BY sc.school_key ORDER BY n DESC LIMIT 6");
    $st->execute([$like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type' => 'school', 'ref' => $r['school_key'], 'label' => $r['school'], 'n' => (int)$r['n']];
    $st = $pdo->prepare("SELECT id, name FROM recruit_projects WHERE name LIKE ? ORDER BY status='open' DESC, id DESC LIMIT 5");
    $st->execute([$like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type' => 'project', 'ref' => (string)$r['id'], 'label' => $r['name']];
    return $out;
}
