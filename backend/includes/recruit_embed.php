<?php
/**
 * OpenHunter · 向量（语义关联）
 *
 * 2026-09-23：「候选人的维度不是单一的，不能靠字符串的模糊匹配，要使用向量做语义关联」。
 *
 * 做法（参考 TechWolf JobBERT / workrb 的「按维度向量化」思路，代码自写）：
 *   每个候选人 / 职位切成 4 个维度分别向量化——skills 技能 / experience 经历 / industry 行业 / headline 概要，
 *   不是整份简历一个向量（那样各维度会糊在一起，「技能对口但行业不同」这种人就看不出来）。
 *   语义分 = 各维度余弦的加权和，只用来**召回**；最终结论仍由大模型按职位要求逐条判断（recruit_match.php）。
 *
 * 模型：gemini-embedding-001，与解析同一个 Gemini key、同一个 OpenAI 兼容端点（…/v1beta/openai/embeddings）。
 *   取回完整向量后本地截断到 768 维再 L2 归一化（该模型支持截断），不依赖端点是否认 dimensions 参数。
 * 存储：MySQL 表 recruit_vectors，经 RecruitVectorStore 接口读写——超过约 10 万人再换向量数据库，业务代码不动。
 *
 * ⛔ 生产机 2 核无 swap：算分时逐行流式读向量、边读边算，不把上万人的向量一次载入内存。
 */

require_once __DIR__ . '/recruit_parse.php';

const RECRUIT_FACETS = ['skills', 'experience', 'industry', 'headline'];
const RECRUIT_EMBED_DIMS = 768;      // 精排语义分
const RECRUIT_EMBED_DIMS_S = 256;    // 人才库搜索（读得快）
const RECRUIT_EMBED_BATCH = 100;
const RECRUIT_EMBED_TIMEOUT = 55;    // CLI 批量
const RECRUIT_EMBED_QUERY_TIMEOUT = 10;   // web 请求里的单条查询，必须远小于 FPM 100 秒（§7.10）
const RECRUIT_EMBED_CONNECT_TIMEOUT = 5;

/** 默认参数；可在 system_settings `recruit.sem.config`（JSON）里覆盖，调权重/阈值不用改代码 */
const RECRUIT_SEM_DEFAULTS = [
    'model' => 'gemini-embedding-001',
    'weights' => ['skills' => 0.35, 'experience' => 0.35, 'industry' => 0.15, 'headline' => 0.15],
    /* 语义分低于它的组合不送大模型。0.55 → 0.60（2026-09-24）：生产实测 gemini-embedding-001 的相似度挤在 0.54–0.72，
       不相关的也有 0.58；0.55 形同虚设（环境顾问岗 59 人放进 55 人，43 人白评、最高 2.5 分）。
       HR 岗 AI ≥4 分的人语义分全部 ≥0.60，按 0.60 不漏人。职位多了再按分布复核 */
    'threshold' => 0.60,
    'top_per_job' => 30,     // 每个职位最多精排多少人
    'top_per_candidate' => 5,
    'search_min' => 0.5,     // 人才库语义搜索：相关度低于它的不列出（关键字命中的不受限）
];

/** 行业代码 → 英文说明，给向量用（代码本身没语义） */
const RECRUIT_INDUSTRY_EN = [
    'it_software' => 'IT and software', 'datacenter_infra' => 'data center and IT infrastructure operations',
    'telecom' => 'telecommunications', 'manufacturing' => 'manufacturing and factory', 'mining_energy' => 'mining and energy',
    'construction_engineering' => 'construction and civil engineering', 'banking_finance' => 'banking and finance',
    'retail_fmcg' => 'retail and FMCG', 'logistics' => 'logistics and supply chain', 'hospitality_fnb' => 'hospitality and food & beverage',
    'healthcare' => 'healthcare', 'education' => 'education', 'government' => 'government and public sector', 'legal' => 'legal',
    'hr_recruitment' => 'human resources and recruitment', 'media_marketing' => 'media and marketing',
    'agriculture_plantation' => 'agriculture and plantation', 'automotive' => 'automotive', 'real_estate' => 'real estate', 'other' => '',
];
const RECRUIT_EDU_EN = ['sd' => 'primary school', 'smp' => 'junior high school', 'sma' => 'high school', 'd1' => 'D1 diploma',
    'd2' => 'D2 diploma', 'd3' => 'D3 diploma', 'd4' => 'D4 applied bachelor', 's1' => 'bachelor degree', 's2' => 'master degree', 's3' => 'doctorate'];

function recruitSemConfig(PDO $pdo, bool $reload = false): array {
    static $memo = null;
    if ($memo !== null && !$reload) return $memo;
    $cfg = RECRUIT_SEM_DEFAULTS;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='recruit.sem.config'");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
        if (is_array($j)) $cfg = array_replace_recursive($cfg, $j);
    } catch (Throwable $e) { /* 读不到用默认 */ }
    return $memo = $cfg;
}

// =====================================================================
// 向量工具（纯函数）
// =====================================================================

function recruitVecNormalize(array $v): array {
    $n = 0.0;
    foreach ($v as $x) $n += $x * $x;
    if ($n <= 0) return $v;
    $n = sqrt($n);
    return array_map(fn($x) => $x / $n, $v);
}
/** 截断到前 d 维并重新归一化（Matryoshka 截断，gemini-embedding-001 支持） */
function recruitVecTruncate(array $v, int $d): array { return recruitVecNormalize(array_slice(array_values($v), 0, $d)); }
function recruitVecPack(array $v): string { return pack('g*', ...array_map('floatval', $v)); }
function recruitVecUnpack(string $b): array { return $b === '' ? [] : array_values(unpack('g*', $b)); }
function recruitVecDot(array $a, array $b): float {
    $s = 0.0; $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) $s += $a[$i] * $b[$i];
    return $s;
}

/**
 * 语义分：两边都有的维度算余弦，按权重加权；某一维度缺失（简历没写行业）不当 0 分，
 * 而是把权重分给其它维度——否则资料少的人被系统性压低。
 * @param array $cand facet => 归一化向量   @param array $job 同上
 * @return array{score: float, facets: array<string,float>}
 */
function recruitSemScore(array $cand, array $job, array $weights): array {
    $sum = 0.0; $wsum = 0.0; $facets = [];
    foreach ($weights as $f => $w) {
        if (empty($cand[$f]) || empty($job[$f])) continue;
        $sim = recruitVecDot($cand[$f], $job[$f]);
        $facets[$f] = round($sim, 4);
        $sum += $w * $sim; $wsum += $w;
    }
    return ['score' => $wsum > 0 ? round($sum / $wsum, 4) : 0.0, 'facets' => $facets];
}

/**
 * 自由文本搜索的相关度：查询通常只说一个方面（「会 MikroTik」），按加权平均会被其它维度稀释，
 * 所以取 0.6×最相关维度 + 0.4×加权平均。返回相关度与命中的维度。
 */
function recruitQueryScore(array $query, array $cand, array $weights): array {
    $best = -1.0; $bestF = ''; $sum = 0.0; $wsum = 0.0; $facets = [];
    foreach ($weights as $f => $w) {
        if (empty($cand[$f])) continue;
        $sim = recruitVecDot($query, $cand[$f]);
        $facets[$f] = round($sim, 4);
        if ($sim > $best) { $best = $sim; $bestF = $f; }
        $sum += $w * $sim; $wsum += $w;
    }
    if ($wsum <= 0) return ['score' => 0.0, 'facet' => '', 'facets' => []];
    return ['score' => round(0.6 * $best + 0.4 * ($sum / $wsum), 4), 'facet' => $bestF, 'facets' => $facets];
}

// =====================================================================
// 维度文本（纯函数）：档案 / 职位 → 4 段英文为主的描述，喂 embedding
// =====================================================================

function recruitCandidateFacetTexts(array $p, array $flat = []): array {
    $join = fn(array $xs, string $sep = ', ') => implode($sep, array_values(array_filter(array_map('trim', $xs), fn($x) => $x !== '')));
    $skills = !empty($p['skills_en']) ? $p['skills_en'] : ($p['skills'] ?? []);
    $certs = array_column($p['certificates'] ?? [], 'name');
    $langs = array_map(fn($l) => trim(($l['language'] ?? '') . ' ' . ($l['level_raw'] ?? '')), $p['languages'] ?? []);
    $skillsText = $join([
        $skills ? 'Skills: ' . $join($skills) : '',
        $certs ? 'Certifications: ' . $join($certs) : '',
        $langs ? 'Languages: ' . $join($langs) : '',
    ], '. ');

    $expParts = [];
    foreach (array_slice($p['experience'] ?? [], 0, 8) as $x) {
        $line = trim(($x['title'] ?? '') . ((($x['company'] ?? '') !== '') ? ' at ' . $x['company'] : ''));
        if (!empty($x['description'])) $line .= ': ' . $x['description'];
        if ($line !== '') $expParts[] = $line;
    }
    foreach (array_slice($p['projects'] ?? [], 0, 5) as $pr) {
        $t = trim(($pr['name'] ?? '') . ((($pr['description'] ?? '') !== '') ? ': ' . $pr['description'] : ''));
        if ($t !== '') $expParts[] = 'Project ' . $t;
    }
    $expText = mb_substr(implode('. ', $expParts), 0, 4000);

    $inds = array_values(array_unique(array_merge($p['industries'] ?? [], array_filter(array_column($p['experience'] ?? [], 'company_industry')))));
    $indText = $join([
        $inds ? 'Industries: ' . $join(array_map(fn($c) => RECRUIT_INDUSTRY_EN[$c] ?? $c, $inds)) : '',
        ($cos = array_filter(array_column($p['experience'] ?? [], 'company'))) ? 'Companies: ' . $join(array_slice($cos, 0, 8)) : '',
    ], '. ');

    $edu = RECRUIT_EDU_EN[$flat['highest_edu'] ?? ''] ?? '';
    $headText = $join([
        $p['headline'] ?? '',
        ($flat['latest_title'] ?? '') !== '' ? 'Current role: ' . $flat['latest_title'] : '',
        isset($flat['years_exp']) && $flat['years_exp'] !== null && $flat['years_exp'] !== '' ? $flat['years_exp'] . ' years of experience' : '',
        $edu !== '' ? trim($edu . ' ' . ($flat['latest_major'] ?? '')) : '',
        ($loc = $join([$p['location']['city'] ?? '', $p['location']['country'] ?? ''])) !== '' ? 'Location: ' . $loc : '',
    ], '. ');

    return ['skills' => $skillsText, 'experience' => $expText, 'industry' => $indText, 'headline' => $headText];
}

/** $job: recruit_jobs 行 + project_name / customer_group_name；$reqs: 拆好的要求 */
function recruitJobFacetTexts(array $job, array $reqs): array {
    $core = array_column(array_filter($reqs, fn($r) => ($r['level'] ?? '') === 'core'), 'text');
    $nice = array_column(array_filter($reqs, fn($r) => ($r['level'] ?? '') !== 'core'), 'text');
    $jd = trim((string)($job['jd_text'] ?? ''));
    $title = trim((string)($job['title'] ?? ''));
    return [
        'skills' => trim(($core ? 'Required: ' . implode('; ', $core) . '. ' : '') . ($nice ? 'Preferred: ' . implode('; ', $nice) : '')) ?: mb_substr($jd, 0, 1500),
        'experience' => trim($title . ($jd !== '' ? '. ' . mb_substr($jd, 0, 3000) : '')),
        'industry' => trim($title . '. Client: ' . trim(($job['customer_group_name'] ?? '') . ' ' . ($job['project_name'] ?? '')) . '. ' . mb_substr($jd, 0, 600)),
        'headline' => trim($title . (($job['location'] ?? '') !== '' ? ', ' . $job['location'] : '') . ($core ? '. ' . implode('; ', array_slice($core, 0, 3)) : '')),
    ];
}

// =====================================================================
// embedding 接口
// =====================================================================

/**
 * 返回一个 fn(array $texts): array 的调用器。结果：['ok'=>true,'vectors'=>[...], 'usage'=>[...]]
 * 或 ['ok'=>false,'error_kind'=>not_configured|rejected|server_busy|network|bad_output,'error'=>...]
 */
function recruitEmbedHttp(PDO $pdo, bool $forQuery = false): callable {
    require_once __DIR__ . '/openai_vision.php';
    $cfg = openaiOcrConfig($pdo);
    $model = (string)recruitSemConfig($pdo)['model'];
    return function (array $texts) use ($cfg, $model, $forQuery): array {
        if (!$cfg) return ['ok' => false, 'error_kind' => 'not_configured', 'error' => 'AI 未配置'];
        $endpoint = preg_replace('#/chat/completions/?$#', '/embeddings', (string)$cfg['endpoint']);
        if ($endpoint === $cfg['endpoint']) return ['ok' => false, 'error_kind' => 'not_configured', 'error' => '无法从 AI 端点推出 embeddings 地址'];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'input' => array_values($texts)], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['api_key']],
            CURLOPT_CONNECTTIMEOUT => RECRUIT_EMBED_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $forQuery ? RECRUIT_EMBED_QUERY_TIMEOUT : RECRUIT_EMBED_TIMEOUT,
        ]);
        $t0 = microtime(true);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $elapsed = round(microtime(true) - $t0, 2);
        if ($resp === false) return ['ok' => false, 'error_kind' => 'network', 'error' => $err, 'elapsed' => $elapsed];
        if ($code !== 200) {
            $kind = in_array($code, [401, 403, 429], true) ? 'rejected' : (in_array($code, [500, 502, 503, 504], true) ? 'server_busy' : 'network');
            return ['ok' => false, 'error_kind' => $kind, 'error' => "HTTP $code: " . substr((string)$resp, 0, 300), 'elapsed' => $elapsed];
        }
        $j = json_decode((string)$resp, true);
        $vecs = [];
        foreach ((array)($j['data'] ?? []) as $d) {
            if (!is_array($d['embedding'] ?? null)) return ['ok' => false, 'error_kind' => 'bad_output', 'error' => 'embedding 缺失'];
            $vecs[(int)($d['index'] ?? count($vecs))] = $d['embedding'];
        }
        ksort($vecs);
        if (count($vecs) !== count($texts)) return ['ok' => false, 'error_kind' => 'bad_output', 'error' => '返回条数不符'];
        return ['ok' => true, 'vectors' => array_values($vecs), 'usage' => $j['usage'] ?? [], 'elapsed' => $elapsed];
    };
}

// =====================================================================
// 存储层：先实现 MySQL；换向量数据库时新写一个实现，调用方不动
// =====================================================================

interface RecruitVectorStore {
    /** @return array<int, array<string,string>> owner_id => facet => text_hash（只含当前模型的） */
    public function hashes(string $type, array $ids, string $model): array;
    public function upsert(string $type, int $id, string $facet, string $model, array $vec768, string $hash): void;
    public function delete(string $type, int $id, string $facet): void;
    /** 一次取少量 owner 的向量：owner_id => facet => 归一化向量 */
    public function load(string $type, array $ids, bool $small = false): array;
    /** 流式遍历（大批量算分用），每个 owner 回调一次：fn(int $id, array $facetVecs) */
    public function each(string $type, array $ids, bool $small, callable $fn): void;
}

class RecruitMysqlVectorStore implements RecruitVectorStore {
    public function __construct(private PDO $pdo) {}

    public function hashes(string $type, array $ids, string $model): array {
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT owner_id, facet, text_hash FROM recruit_vectors WHERE owner_type=? AND model=? AND owner_id IN ($ph)");
        $st->execute(array_merge([$type, $model], array_map('intval', $ids)));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['owner_id']][$r['facet']] = $r['text_hash'];
        return $out;
    }

    public function upsert(string $type, int $id, string $facet, string $model, array $vec768, string $hash): void {
        $v = recruitVecTruncate($vec768, RECRUIT_EMBED_DIMS);
        $s = recruitVecTruncate($vec768, RECRUIT_EMBED_DIMS_S);
        // REPLACE INTO：MySQL 与 SQLite 通用；本表无外键，删后重插无副作用
        $st = $this->pdo->prepare("REPLACE INTO recruit_vectors (owner_type, owner_id, facet, model, dims, vec, vec_s, text_hash, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, (" . dbNow() . "))");
        $st->bindValue(1, $type); $st->bindValue(2, $id, PDO::PARAM_INT); $st->bindValue(3, $facet); $st->bindValue(4, $model);
        $st->bindValue(5, count($v), PDO::PARAM_INT);
        $st->bindValue(6, recruitVecPack($v), PDO::PARAM_LOB);    // 二进制按 LOB 绑：SQLite 否则存成 TEXT，长度 / 内容都可能被截断
        $st->bindValue(7, recruitVecPack($s), PDO::PARAM_LOB);
        $st->bindValue(8, $hash);
        $st->execute();
    }

    public function delete(string $type, int $id, string $facet): void {
        $this->pdo->prepare("DELETE FROM recruit_vectors WHERE owner_type=? AND owner_id=? AND facet=?")->execute([$type, $id, $facet]);
    }

    public function load(string $type, array $ids, bool $small = false): array {
        $out = [];
        $this->each($type, $ids, $small, function (int $id, array $f) use (&$out) { $out[$id] = $f; });
        return $out;
    }

    public function each(string $type, array $ids, bool $small, callable $fn): void {
        $col = $small ? 'vec_s' : 'vec';
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->pdo->prepare("SELECT owner_id, facet, $col v FROM recruit_vectors WHERE owner_type=? AND owner_id IN ($ph) ORDER BY owner_id");
            $st->execute(array_merge([$type], $chunk));
            $cur = null; $acc = [];
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {       // 逐行读、逐人回调，不把整批向量留在内存里
                $oid = (int)$r['owner_id'];
                if ($cur !== null && $oid !== $cur) { $fn($cur, $acc); $acc = []; }
                $cur = $oid;
                $acc[$r['facet']] = recruitVecUnpack((string)$r['v']);
            }
            if ($cur !== null) $fn($cur, $acc);
            $st->closeCursor();
        }
    }
}

function recruitVectorStore(PDO $pdo): RecruitVectorStore {
    static $s = null;
    return $s ??= new RecruitMysqlVectorStore($pdo);
}

// =====================================================================
// 批处理：向量化 → 算语义分（cron_recruit_parse.php 调用）
// =====================================================================

/**
 * 给档案/JD 变了的候选人与职位重算向量。只算 text_hash 变了的维度（技能没变就不花钱）。
 * @param callable $embed fn(array $texts): array —— 形同 recruitEmbedHttp()
 */
function recruitEmbedBatch(PDO $pdo, callable $embed, array $opt = []): array {
    $cfg = recruitSemConfig($pdo);
    $model = (string)$cfg['model'];
    $store = recruitVectorStore($pdo);
    $rep = ['candidates' => 0, 'jobs' => 0, 'texts' => 0, 'calls' => 0, 'failed' => 0, 'aborted' => false, 'abort_reason' => ''];
    $onlyC = !empty($opt['only_candidate_ids']) ? ' AND id IN (' . implode(',', array_map('intval', $opt['only_candidate_ids'])) . ')' : '';
    $onlyJ = !empty($opt['only_job_ids']) ? ' AND j.id IN (' . implode(',', array_map('intval', $opt['only_job_ids'])) . ')' : '';

    $owners = [];   // [type, id, rev, texts]
    foreach ($pdo->query("SELECT id, profile_rev, profile_json, highest_edu, latest_title, latest_major, years_exp
                          FROM recruit_candidates WHERE profile_rev>0 AND vec_rev<profile_rev $onlyC
                          ORDER BY last_received_at DESC LIMIT " . (int)($opt['limit'] ?? 60))->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $p = json_decode((string)$c['profile_json'], true) ?: [];
        $owners[] = ['cand', (int)$c['id'], (int)$c['profile_rev'], recruitCandidateFacetTexts($p, $c)];
    }
    foreach ($pdo->query("SELECT j.*, p.name project_name, COALESCE(cu.name,'') customer_group_name
                          FROM recruit_jobs j JOIN recruit_projects p ON p.id=j.project_id
                          LEFT JOIN recruit_clients cu ON cu.id=p.customer_id
                          WHERE j.status='open' AND j.vec_rev<j.jd_rev AND " . recruitJobReadySql('j') . " $onlyJ
                          ORDER BY j.id LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $owners[] = ['job', (int)$j['id'], (int)$j['jd_rev'], recruitJobFacetTexts($j, json_decode((string)$j['jd_requirements_json'], true) ?: [])];
    }
    if (!$owners) return $rep;

    // 算出每个 owner 哪些维度要重算
    $need = [];   // list of [ownerIdx, facet, text, hash]
    $existing = ['cand' => [], 'job' => []];
    foreach (['cand', 'job'] as $tp) {
        $ids = array_column(array_filter($owners, fn($o) => $o[0] === $tp), 1);
        $existing[$tp] = $store->hashes($tp, $ids, $model);
    }
    foreach ($owners as $i => [$tp, $id, $rev, $texts]) {
        foreach (RECRUIT_FACETS as $f) {
            $t = trim(recruitSanitizeForPrompt((string)($texts[$f] ?? '')));
            $old = $existing[$tp][$id][$f] ?? null;
            if ($t === '') { if ($old !== null) $store->delete($tp, $id, $f); continue; }
            $h = sha1($model . '|' . $t);
            if ($old === $h) continue;
            $need[] = [$i, $f, $t, $h];
        }
    }

    $failed = [];   // ownerIdx => true：这一轮有维度没算成，不推进 vec_rev，下轮重来
    foreach (array_chunk($need, RECRUIT_EMBED_BATCH) as $chunk) {
        $res = $embed(array_column($chunk, 2));
        $rep['calls']++;
        if (function_exists('logAiUsage') && ($res['error_kind'] ?? '') !== 'not_configured') {
            logAiUsage($pdo, 'recruit_embed', ['model' => $model, 'usage' => $res['usage'] ?? [], 'elapsed' => $res['elapsed'] ?? 0],
                !empty($res['ok']) ? 'success' : 'failed', 0, (string)($res['error'] ?? ''), '', count($chunk) . ' texts', 'recruit_embed', 0);
        }
        if (empty($res['ok'])) {
            foreach ($chunk as $n) $failed[$n[0]] = true;
            if (in_array($res['error_kind'] ?? '', ['not_configured', 'rejected'], true)) {
                $rep['aborted'] = true; $rep['abort_reason'] = (string)$res['error_kind'];
                $rep['abort_error'] = mb_substr((string)($res['error'] ?? ''), 0, 200);   // 如「HTTP 403: …」，运行日志里显示，方便查为什么被拒
                foreach ($need as $n) $failed[$n[0]] = true;
                break;
            }
            continue;
        }
        foreach ($chunk as $k => [$i, $f, $t, $h]) {
            [$tp, $id] = $owners[$i];
            $store->upsert($tp, $id, $f, $model, $res['vectors'][$k], $h);
            $rep['texts']++;
        }
    }
    // 全部维度都成功的 owner：推进 vec_rev（没有要重算的维度也算成功）
    $upC = $pdo->prepare("UPDATE recruit_candidates SET vec_rev=? WHERE id=? AND vec_rev<?");
    $upJ = $pdo->prepare("UPDATE recruit_jobs SET vec_rev=? WHERE id=? AND vec_rev<?");
    $rep['failed'] = count($failed);
    foreach ($owners as $i => [$tp, $id, $rev]) {
        if (isset($failed[$i])) continue;
        ($tp === 'cand' ? $upC : $upJ)->execute([$rev, $id, $rev]);
        $rep[$tp === 'cand' ? 'candidates' : 'jobs']++;
    }
    return $rep;
}

/**
 * 算语义分：找出「两边都有向量、且分数缺失或任一边向量已更新」的 候选人×开放职位，纯 PHP 点积入表。
 * 按职位分组，候选人向量流式读取——新职位要和上万人比时，内存里只放当前这个人的 4 个向量。
 */
function recruitSemScoreBatch(PDO $pdo, array $opt = []): array {
    $cfg = recruitSemConfig($pdo);
    $w = (array)$cfg['weights'];
    $store = recruitVectorStore($pdo);
    $rep = ['jobs' => 0, 'pairs' => 0];
    $onlyC = !empty($opt['only_candidate_ids']) ? ' AND c.id IN (' . implode(',', array_map('intval', $opt['only_candidate_ids'])) . ')' : '';
    $onlyJ = !empty($opt['only_job_ids']) ? ' AND j.id IN (' . implode(',', array_map('intval', $opt['only_job_ids'])) . ')' : '';
    $rows = $pdo->query("SELECT c.id cid, c.vec_rev crev, j.id jid, j.vec_rev jrev
        FROM recruit_candidates c
        JOIN recruit_jobs j ON j.status='open' AND j.vec_rev>0
        JOIN recruit_projects p ON p.id=j.project_id AND p.status='open'
        LEFT JOIN recruit_sem_scores s ON s.candidate_id=c.id AND s.job_id=j.id
        WHERE c.vec_rev>0 AND c.status<>'blacklisted'
          AND (s.candidate_id IS NULL OR s.cand_rev<c.vec_rev OR s.job_rev<j.vec_rev) $onlyC $onlyJ
        LIMIT " . (int)($opt['limit'] ?? 20000))->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return $rep;

    $byJob = [];
    foreach ($rows as $r) $byJob[(int)$r['jid']][(int)$r['cid']] = [(int)$r['crev'], (int)$r['jrev']];
    $jobVecs = $store->load('job', array_keys($byJob));
    $put = $pdo->prepare("REPLACE INTO recruit_sem_scores (candidate_id, job_id, score, facets_json, cand_rev, job_rev, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, (" . dbNow() . "))");
    foreach ($byJob as $jid => $cands) {
        $jv = $jobVecs[$jid] ?? [];
        if (!$jv) continue;
        $rep['jobs']++;
        $store->each('cand', array_keys($cands), false, function (int $cid, array $cv) use ($jv, $w, $jid, $cands, $put, &$rep) {
            $s = recruitSemScore($cv, $jv, $w);
            [$crev, $jrev] = $cands[$cid];
            $put->execute([$cid, $jid, $s['score'], json_encode($s['facets']), $crev, $jrev]);
            $rep['pairs']++;
        });
    }
    return $rep;
}

// =====================================================================
// 在线查询（web 请求）：人才库语义搜索、相似候选人
// =====================================================================

/**
 * 语义搜索：查询向量化（单条短文本，超时 10 秒）→ 对已筛出的候选人流式算相关度。
 * @return array{ok:bool, scores?: array<int,array{score:float,facet:string}>, error_kind?:string}
 */
function recruitSemanticSearch(PDO $pdo, string $query, array $candIds, ?callable $embed = null, ?array $weights = null): array {
    $embed ??= recruitEmbedHttp($pdo, true);
    $r = $embed([mb_substr(recruitSanitizeForPrompt($query), 0, 500)]);
    if (empty($r['ok'])) return ['ok' => false, 'error_kind' => (string)($r['error_kind'] ?? 'network')];
    $q = recruitVecTruncate($r['vectors'][0], RECRUIT_EMBED_DIMS_S);
    $w = $weights ?? (array)recruitSemConfig($pdo)['weights'];   // 只给一个维度 = 只在该维度上检索
    $scores = [];
    recruitVectorStore($pdo)->each('cand', $candIds, true, function (int $cid, array $cv) use ($q, $w, &$scores) {
        $scores[$cid] = recruitQueryScore($q, $cv, $w);
    });
    return ['ok' => true, 'scores' => $scores];
}

/** 相似候选人（找替补）：拿本人各维度向量与其他人比，加权分最高的前 N */
function recruitSimilarCandidates(PDO $pdo, int $cid, array $poolIds, int $limit = 10, ?array $weights = null): array {
    $store = recruitVectorStore($pdo);
    $me = $store->load('cand', [$cid], true)[$cid] ?? [];
    if (!$me) return [];
    // $weights 只给一个维度 = 只按该维度找（如只按「行业」找同行业的人）
    $w = $weights ?? (array)recruitSemConfig($pdo)['weights'];
    $out = [];
    $store->each('cand', array_diff($poolIds, [$cid]), true, function (int $id, array $cv) use ($me, $w, &$out) {
        $s = recruitSemScore($cv, $me, $w);
        $out[$id] = $s;
    });
    uasort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $limit, true);
}
