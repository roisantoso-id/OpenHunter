<?php
require_once __DIR__ . '/recruit_scope.php';   // recruitVisibleSql
/**
 * OpenHunter · 人才关联（2026-09-24「他投的是 HR 岗，但经历里有医疗器械，可以推荐给别的客户；
 * 即使不招他，也能通过他去找其他合适的人」）。候选人抽屉「关联」页签的三块：
 *
 *   ① 适合的其他在招职位：他的 4 维向量 × 全部在招职位（职位招聘中 + 项目进行中）的向量，现算不读缓存。
 *      不管他投的是哪个岗——一键「加入该职位」后下一轮 AI 打分（handleRecruitAddMatch）
 *   ② 像他这样的人：recruitSimilarCandidates，可只按某一维度找（只按「行业」= 找同行业的人）
 *   ③ 前同事 / 校友：纯 SQL + PHP，不靠向量——向量没通也能用。同一家公司（同期标出来）、同一所学校，找内推/人脉
 *
 * ①② 依赖向量（生产向量接口不通时为空，页面提示；体检见 scripts/ops/recruit_embed_check.php）。
 */

require_once __DIR__ . '/recruit_embed.php';

/** 公司/学校名归一：小写、去标点、去法律后缀（PT/CV/Tbk/Ltd/有限公司…）。太短或太泛（自由职业等）返回 ''，不参与关联 */
function recruitOrgKey(string $name): string {
    $s = mb_strtolower(trim($name));
    // 去重音：Nestlé = Nestle、L'Oréal = L'Oreal（MySQL unicode_ci 认为二者相同，PHP 不去的话同一家会被当成两家）
    if (function_exists('normalizer_normalize')) $s = preg_replace('/\p{Mn}+/u', '', (string)normalizer_normalize($s, Normalizer::FORM_D));
    $s = preg_replace('/[（(][^）)]*[）)]/u', ' ', $s);                 // 括号里的（Persero）/（中国）
    $s = preg_replace('/(有限责任公司|股份有限公司|有限公司|集团|公司)$/u', '', trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $stop = ['pt', 'cv', 'tbk', 'persero', 'ltd', 'limited', 'co', 'inc', 'corp', 'corporation', 'company', 'llc', 'plc', 'gmbh', 'sdn', 'bhd', 'pte', 'the'];
    $w = array_values(array_filter(explode(' ', $s), fn($x) => $x !== '' && !in_array($x, $stop, true)));
    $key = implode(' ', $w);
    $generic = ['self employed', 'freelance', 'freelancer', 'wiraswasta', 'wirausaha', 'na', 'none', 'private', 'confidential', '自由职业', '个体'];
    // 下限 2 个字：BCA / BRI / BNI / XL / IBM 这种缩写本身就是大雇主（原来 <4 全丢）；纯数字不算
    if (in_array($key, $generic, true) || mb_strlen($key) < 2 || preg_match('/^\d+$/', $key)) return '';
    return $key;
}

/** 两段 [start, end] 是否有重叠月份（缺日期就不算同期） */
function recruitPeriodsOverlap(array $a, array $b, string $asOf): bool {
    $s1 = recruitMonthIndex((string)($a['start'] ?? ''), $asOf); $e1 = recruitMonthIndex((string)($a['end'] ?? '') ?: (!empty($a['is_current']) ? 'present' : ''), $asOf);
    $s2 = recruitMonthIndex((string)($b['start'] ?? ''), $asOf); $e2 = recruitMonthIndex((string)($b['end'] ?? '') ?: (!empty($b['is_current']) ? 'present' : ''), $asOf);
    if ($s1 === null || $s2 === null) return false;
    return $s1 <= ($e2 ?? $s2) && $s2 <= ($e1 ?? $s1);
}

/** 从档案抽出 key => [name, spans[]]（公司取经历，学校取教育） */
function recruitProfileOrgs(array $profile): array {
    $out = ['company' => [], 'school' => []];
    foreach ((array)($profile['experience'] ?? []) as $x) {
        $k = recruitOrgKey((string)($x['company'] ?? ''));
        if ($k === '') continue;
        $out['company'][$k]['name'] ??= (string)$x['company'];
        $out['company'][$k]['spans'][] = $x;
    }
    foreach ((array)($profile['education'] ?? []) as $e) {
        $k = recruitOrgKey((string)($e['school'] ?? ''));
        if ($k === '') continue;
        $out['school'][$k]['name'] ??= (string)$e['school'];
        $out['school'][$k]['spans'][] = $e;
    }
    return $out;
}

/**
 * ① 适合的其他在招职位。@return array{has_vector: bool, rows: array}
 * 每行：职位、项目、群名、语义分 + 各维度、与他的关联状态（已挂的给阶段和分数，页面按钮置灰）
 */
function recruitRelatedJobs(PDO $pdo, int $cid, int $limit = 10): array {
    $store = recruitVectorStore($pdo);
    $me = $store->load('cand', [$cid])[$cid] ?? [];
    if (!$me) return ['has_vector' => false, 'rows' => []];
    $jobs = array_column($pdo->query("SELECT j.id, j.title, j.location, p.id project_id, p.name project_name, p.kind, COALESCE(cu.name,'') group_name
        FROM recruit_jobs j JOIN recruit_projects p ON p.id=j.project_id AND p.status='open'
        LEFT JOIN recruit_clients cu ON cu.id=p.customer_id
        WHERE j.status='open' AND j.vec_rev>0")->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    if (!$jobs) return ['has_vector' => true, 'rows' => []];
    $w = (array)recruitSemConfig($pdo)['weights'];
    $scored = [];
    $store->each('job', array_keys($jobs), false, function (int $jid, array $jv) use ($me, $w, &$scored) {
        $scored[$jid] = recruitSemScore($me, $jv, $w);
    });
    uasort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $st = $pdo->prepare("SELECT job_id, stage, COALESCE(human_score, ai_score) score FROM recruit_candidate_jobs WHERE candidate_id=?");
    $st->execute([$cid]);
    $links = array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'job_id');
    $rows = [];
    foreach (array_slice($scored, 0, $limit, true) as $jid => $s) {
        $l = $links[$jid] ?? null;
        $rows[] = $jobs[$jid] + ['score' => $s['score'], 'facets' => $s['facets'],
            'linked_stage' => $l && $l['stage'] !== 'removed' ? $l['stage'] : '', 'linked_score' => $l ? $l['score'] : null];
    }
    return ['has_vector' => true, 'rows' => $rows];
}

/**
 * ③ 前同事 / 校友。$scope = 只看某招聘专员名下（recruit 权限），null = 全部。
 * 召回：search_text（含全部经历公司、最高学历学校）/ latest_school 一次扫描；再解档案逐条核对归一后的名字，
 * 标出同期（在职时间有重叠）。已知限制：对方非最高学历的学校不在 search_text 里，会漏。
 */
function recruitRelatedPeople(PDO $pdo, int $cid, ?int $scope, int $limit = 30): array {
    $st = $pdo->prepare("SELECT profile_json FROM recruit_candidates WHERE id=?");
    $st->execute([$cid]);
    $mine = recruitProfileOrgs(json_decode((string)$st->fetchColumn(), true) ?: []);
    $keys = array_merge(array_slice(array_keys($mine['company']), 0, 10), array_slice(array_keys($mine['school']), 0, 4));
    if (!$keys) return [];
    $or = []; $args = [$cid];
    foreach ($keys as $k) {   // 词之间放 %：key「xl axiata」也能命中原文「pt. xl-axiata」
        $pat = '%' . str_replace(' ', '%', addcslashes($k, '%_\\')) . '%';
        $or[] = 'c.search_text LIKE ? OR c.latest_school LIKE ?'; $args[] = $pat; $args[] = $pat;
    }
    $sql = "SELECT c.id, c.name, c.latest_title, c.latest_company, c.years_exp, c.status, c.owner_user_name, c.profile_json
            FROM recruit_candidates c WHERE c.id<>? AND c.status<>'blacklisted' AND c.profile_rev>0 AND (" . implode(' OR ', $or) . ")";
    if ($scope !== null) $sql .= ' AND ' . recruitVisibleSql($scope);
    $q = $pdo->prepare($sql . ' ORDER BY c.id DESC LIMIT 300');
    $q->execute($args);
    $asOf = date('Y-m-d');
    $rows = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $theirs = recruitProfileOrgs(json_decode((string)$c['profile_json'], true) ?: []);
        $rel = []; $rank = 0;
        foreach (['company', 'school'] as $kind) {
            foreach ($mine[$kind] as $k => $m) {
                if (!isset($theirs[$kind][$k])) continue;
                $overlap = false;
                foreach ($m['spans'] as $a) foreach ($theirs[$kind][$k]['spans'] as $b) $overlap = $overlap || recruitPeriodsOverlap($a, $b, $asOf);
                $t = $theirs[$kind][$k]['spans'][0];
                $rel[] = ['kind' => $kind, 'org' => $m['name'], 'overlap' => $overlap,
                          'title' => (string)($t['title'] ?? $t['major'] ?? ''),
                          'period' => trim(($t['start'] ?? '') . ' ~ ' . (!empty($t['is_current']) ? 'present' : ($t['end'] ?? '')), ' ~')];
                $rank = max($rank, $kind === 'company' ? ($overlap ? 3 : 2) : ($overlap ? 1.5 : 1));
            }
        }
        if (!$rel) continue;   // search_text 子串命中但归一后对不上（如 key 恰好是别家公司名的一部分）
        unset($c['profile_json']);
        $rows[] = $c + ['relations' => $rel, '_rank' => $rank];
    }
    usort($rows, fn($a, $b) => [$b['_rank'], count($b['relations'])] <=> [$a['_rank'], count($a['relations'])]);
    return array_map(function ($r) { unset($r['_rank']); return $r; }, array_slice($rows, 0, $limit));
}
