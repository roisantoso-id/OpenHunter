<?php
/**
 * 招聘 AI 提示词的自我迭代（2026-09-24「每次生成失败的提示词不能每次累计，我们需要有自我迭代的能力」）
 *
 * 以前：提示词写死在代码里，出一次错就往里加一条规则（m3 的「证据必须照抄」就是这么来的），越堆越长；
 * 失败只记一行 200 字的错误，人工纠正（HR 改了 AI 拆的要求、人工分覆盖 AI 分、移除不合适的匹配）全丢了。
 *
 * 现在：**失败变成测试用例，不变成提示词里的规则**。
 *   1. 反馈采集 recruitFeedback()：硬失败（输出坏 / 校验不过）、人工纠正自动落 recruit_ai_feedback。
 *      同一输入同一类问题只存一条、累加 hits（「不能每次累计」），人工纠正的自动标 gold（有标准答案）。
 *   2. 提示词进库带版本（recruit_prompts）：scene 一个 active；代码里的写法是内置版本（builtin），
 *      代码升版本（改了 RECRUIT_*_PROMPT_VER）会作为草稿入库，同样要过回归评测。
 *   3. 回归评测 recruitPromptEvaluate()：同一批用例（gold 优先，最多 RECRUIT_EVAL_MAX_CASES 条）上，
 *      现行版本与候选版本各跑一遍，按场景算指标：
 *        jd_req  失败率 / 与人工最终要求的 F1 / 必须·加分判对率
 *        match   失败率 / 与人工分的平均误差 / 证据编造率（yes/partial 的证据在档案里找不到）
 *        query   失败率 / 空条件率
 *      **自动启用**（2026-09-24 选定）：用例够数、没有任何指标变差、至少一项变好 → 候选版本直接启用，企微通知；
 *      用例不够时：内置版本（代码评审过）直接启用，页面上写的草稿等管理员手动启用。随时可一键回退到任一旧版本。
 *   4. 提示词长度上限 RECRUIT_PROMPT_MAX_CHARS：超了不让存——逼着合并规则，而不是往后追加。
 *
 * 匹配（match）换版本后，已有匹配按 ai_prompt_ver 自动重评（recruitMatchPairs），重评吃每日预算，慢慢跑完。
 * 招聘文案 / 推荐信改写没有客观标准答案，暂不纳入（后续可按「AI 草稿 → 人工终稿」的改动量做指标）。
 */

require_once __DIR__ . '/recruit_match.php';
require_once __DIR__ . '/recruit_query.php';
require_once __DIR__ . '/recruit_company.php';   // func：职位名 → 职能 / 职级（企业库）

/** 场景 → [ai_api_usage 里的 scene 名, 手动版本号前缀] */
const RECRUIT_PROMPT_SCENES = [
    'jd_req' => ['usage' => 'recruit_jd_req'],
    'match'  => ['usage' => 'recruit_match'],
    'query'  => ['usage' => 'recruit_query'],
    'func'   => ['usage' => 'recruit_func'],
    'coclass' => ['usage' => 'recruit_company_class'],
    'segment' => ['usage' => 'recruit_segment'],
];
const RECRUIT_PROMPT_MAX_CHARS = 4000;
const RECRUIT_EVAL_MAX_CASES = ['jd_req' => 40, 'match' => 20, 'query' => 40, 'func' => 60, 'coclass' => 60, 'segment' => 20];   // 匹配一次 ~1 万 token，少取
const RECRUIT_EVAL_MIN_CASES = 8;
const RECRUIT_EVAL_TOL = ['fail_rate' => 0.02, 'f1' => 0.02, 'level_acc' => 0.02, 'mae' => 0.1, 'halluc_rate' => 0.02, 'empty_rate' => 0.02, 'func_acc' => 0.02, 'industry_acc' => 0.02];
/** 指标方向：true = 越大越好 */
const RECRUIT_EVAL_HIGHER = ['fail_rate' => false, 'f1' => true, 'level_acc' => true, 'mae' => false, 'halluc_rate' => false, 'empty_rate' => false, 'func_acc' => true, 'industry_acc' => true];
/* 自动当标准答案的：HR 改过的要求、人工打分。「移除匹配」不自动算——移除可能是候选人不感兴趣这类与档案无关的原因，
   先记成 open，管理员在页面上确认后再标 gold */
const RECRUIT_FEEDBACK_GOLD_KINDS = ['corrected', 'score_corrected'];
const RECRUIT_FEEDBACK_SAMPLE_MAX = 200;

// =====================================================================
// 提示词版本
// =====================================================================

/** 代码里的内置版本 @return array{ver:string, body:string} */
function recruitPromptBuiltin(string $scene): array {
    switch ($scene) {
        case 'jd_req': return ['ver' => RECRUIT_JDREQ_PROMPT_VER, 'body' => recruitJdReqSystemPrompt()];
        case 'match':  return ['ver' => RECRUIT_MATCH_PROMPT_VER, 'body' => recruitMatchSystemPrompt()];
        case 'query':  return ['ver' => RECRUIT_QUERY_PROMPT_VER, 'body' => recruitQuerySystemPrompt()];
        case 'func':   return ['ver' => RECRUIT_FUNC_PROMPT_VER, 'body' => recruitFuncSystemPrompt()];
        case 'coclass': return ['ver' => RECRUIT_COCLASS_PROMPT_VER, 'body' => recruitCoClassBuiltinPrompt()];
        case 'segment': return ['ver' => RECRUIT_SEGMENT_PROMPT_VER, 'body' => recruitSegmentBuiltinPrompt()];
    }
    throw new InvalidArgumentException("unknown prompt scene $scene");
}

/** 三张表在不在（建表脚本没重跑时一切照旧：用内置提示词、不采反馈） */
function recruitPromptTablesOk(PDO $pdo): bool {
    static $ok = [];
    $k = spl_object_id($pdo);
    if (!isset($ok[$k])) {
        try { $pdo->query("SELECT 1 FROM recruit_prompts LIMIT 1"); $pdo->query("SELECT 1 FROM recruit_ai_feedback LIMIT 1"); $ok[$k] = true; }
        catch (PDOException $e) { $ok[$k] = false; }
    }
    return $ok[$k];
}

/** 当前生效的提示词（一次请求内缓存；启用新版本后 recruitPromptCacheReset） @return array{ver:string, body:string, id:int} */
function recruitPrompt(PDO $pdo, string $scene): array {
    $cache = &recruitPromptCache();
    if (isset($cache[$scene])) return $cache[$scene];
    $p = recruitPromptBuiltin($scene) + ['id' => 0];
    if (recruitPromptTablesOk($pdo)) {
        $st = $pdo->prepare("SELECT id, ver, body FROM recruit_prompts WHERE scene=? AND status='active' ORDER BY activated_at DESC, id DESC LIMIT 1");
        $st->execute([$scene]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) $p = ['ver' => (string)$r['ver'], 'body' => (string)$r['body'], 'id' => (int)$r['id']];
    }
    return $cache[$scene] = $p;
}
function &recruitPromptCache(): array { static $c = []; return $c; }
function recruitPromptCacheReset(): void { $c = &recruitPromptCache(); $c = []; }

/**
 * 内置版本入库（幂等）：scene 还没有 active → 内置版本直接 active；代码升了版本 → 作为草稿入库，返回这些草稿 id（调用方排评测）。
 * @return int[] 新入库的内置草稿 id
 */
function recruitPromptSeed(PDO $pdo): array {
    if (!recruitPromptTablesOk($pdo)) return [];
    $drafts = [];
    foreach (array_keys(RECRUIT_PROMPT_SCENES) as $scene) {
        $b = recruitPromptBuiltin($scene);
        $st = $pdo->prepare("SELECT id FROM recruit_prompts WHERE scene=? AND ver=?");
        $st->execute([$scene, $b['ver']]);
        if ($st->fetchColumn()) continue;
        $st = $pdo->prepare("SELECT COUNT(*) FROM recruit_prompts WHERE scene=? AND status='active'");
        $st->execute([$scene]);
        $hasActive = (int)$st->fetchColumn() > 0;
        $pdo->prepare(dbInsertIgnore() . " recruit_prompts (scene, ver, body, status, source, note, created_at" . ($hasActive ? '' : ', activated_at') . ")
                       VALUES (?, ?, ?, ?, 'builtin', '', (" . dbNow() . ")" . ($hasActive ? '' : ", (" . dbNow() . ")") . ")")
            ->execute([$scene, $b['ver'], $b['body'], $hasActive ? 'draft' : 'active']);
        if ($hasActive && ($id = (int)$pdo->lastInsertId())) $drafts[] = $id;
    }
    recruitPromptCacheReset();
    return $drafts;
}

/** 页面上写的新版本号：以现行版本号为底加 .n（m3.1、m3.2…），与代码里的 m4 这类内置版本号永不撞 */
function recruitPromptNextVer(PDO $pdo, string $scene): string {
    $base = explode('.', recruitPrompt($pdo, $scene)['ver'])[0];
    $st = $pdo->prepare("SELECT ver FROM recruit_prompts WHERE scene=? AND ver LIKE ?");
    $st->execute([$scene, $base . '.%']);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $n = max($n, (int)substr((string)$v, strlen($base) + 1));
    return $base . '.' . ($n + 1);
}

/** 启用某个版本（自动启用、手动启用、回退都走这里）：同场景其它 active → retired */
function recruitPromptActivate(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM recruit_prompts WHERE id=?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return ['ok' => false, 'error' => 'notFound'];
    $from = recruitPrompt($pdo, $p['scene'])['ver'];
    $pdo->prepare("UPDATE recruit_prompts SET status='retired' WHERE scene=? AND status='active' AND id<>?")->execute([$p['scene'], $id]);
    $pdo->prepare("UPDATE recruit_prompts SET status='active', activated_at=(" . dbNow() . ") WHERE id=?")->execute([$id]);
    recruitPromptCacheReset();
    return ['ok' => true, 'scene' => $p['scene'], 'from' => $from, 'to' => (string)$p['ver']];
}

// =====================================================================
// 反馈采集
// =====================================================================

/**
 * 记一条反馈。$input = ['text' => 发给模型的用户消息原文, 'ctx' => 校验需要的上下文]，评测时原样重放。
 * 同场景 + 同类 + 同对象 + 同输入 → 只累加 hits（同一个坑反复踩只记一条）。人工纠正类自动标 gold。
 * 表不在 / 任何异常都吞掉——采反馈绝不能影响业务。
 */
function recruitFeedback(PDO $pdo, string $scene, string $kind, array $input, $ai, $human, string $detail = '',
                         string $refType = '', int $refId = 0, int $uid = 0): void {
    try {
        // 测试用假模型跑业务流程时不写（否则假数据会混进回归用例）；recruit_prompts_test 自己测反馈，不设这个
        if (getenv('RECRUIT_NO_FEEDBACK') || !recruitPromptTablesOk($pdo)) return;
        $inJson = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // 输入里的数据标签每次随机（recruitWrapUntrusted），去重前换成固定名
        $key = sha1("$scene|$kind|$refType|$refId|" . preg_replace('/<(\/?)([a-z]+)-[0-9a-f]{8}>/', '<$1$2>', (string)$inJson));
        $st = $pdo->prepare("SELECT id FROM recruit_ai_feedback WHERE dedupe_key=?");
        $st->execute([$key]);
        $aiJson = $ai === null ? null : json_encode($ai, JSON_UNESCAPED_UNICODE);
        $huJson = $human === null ? null : json_encode($human, JSON_UNESCAPED_UNICODE);
        if ($id = (int)$st->fetchColumn()) {
            $pdo->prepare("UPDATE recruit_ai_feedback SET hits=hits+1, ai_json=COALESCE(?, ai_json), human_json=COALESCE(?, human_json),
                                  detail=?, last_at=(" . dbNow() . ") WHERE id=?")
                ->execute([$aiJson, $huJson, mb_substr($detail, 0, 1000), $id]);
            return;
        }
        if ($kind === 'sample') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM recruit_ai_feedback WHERE scene=? AND kind='sample'");
            $st->execute([$scene]);
            if ((int)$st->fetchColumn() >= RECRUIT_FEEDBACK_SAMPLE_MAX) return;
        }
        $status = in_array($kind, RECRUIT_FEEDBACK_GOLD_KINDS, true) ? 'gold' : ($kind === 'sample' ? 'sample' : 'open');
        $pdo->prepare(dbInsertIgnore() . " recruit_ai_feedback (scene, kind, status, prompt_ver, ref_type, ref_id, input_json, ai_json, human_json,
                              detail, dedupe_key, hits, created_by, created_at, last_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,(" . dbNow() . "),(" . dbNow() . "))")
            ->execute([$scene, $kind, $status, recruitPrompt($pdo, $scene)['ver'], $refType, $refId, $inJson, $aiJson, $huJson,
                       mb_substr($detail, 0, 1000), $key, $uid]);
    } catch (Throwable $e) { /* 采反馈失败不影响业务 */ }
}

/** 判断一次模型调用的失败要不要记：超时 / 网络 / 被拒 / 没配置不是提示词的问题 */
function recruitFeedbackWorthy(array $r): bool {
    return !empty($r['ok']) || in_array($r['error_kind'] ?? '', ['bad_output'], true);
}

/** 两份要求清单是否实质相同（HR 只是没动 / 改了空格不算纠正） */
function recruitReqsSame(array $a, array $b): bool {
    $n = fn(array $l) => array_map(fn($r) => (($r['level'] ?? '') === 'core' ? 'core' : 'nice') . '|' . recruitEvidenceNorm((string)($r['text'] ?? '')), array_values($l));
    return $n($a) === $n($b);
}

// =====================================================================
// 回归评测
// =====================================================================

/** 评测用例：没被标「忽略」的，gold 优先、再按最近。@return array[] */
function recruitEvalCases(PDO $pdo, string $scene): array {
    $st = $pdo->prepare("SELECT id, kind, status, input_json, human_json FROM recruit_ai_feedback
                         WHERE scene=? AND status IN ('gold','open','sample')
                         ORDER BY status='gold' DESC, status='open' DESC, last_at DESC, id DESC LIMIT " . (int)RECRUIT_EVAL_MAX_CASES[$scene]);
    $st->execute([$scene]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $in = json_decode((string)$r['input_json'], true);
        if (!is_array($in) || trim((string)($in['text'] ?? '')) === '') continue;
        $out[] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'gold' => $r['status'] === 'gold',
                  'input' => $in, 'human' => json_decode((string)$r['human_json'], true)];
    }
    return $out;
}

/** 文本相似度（要求逐条对齐用）：拉丁词 + 中文二元组的 Jaccard */
function recruitTextSim(string $a, string $b): float {
    $tok = function (string $s): array {
        $s = recruitEvidenceNorm($s);
        $t = [];
        foreach (preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (preg_match('/\p{Han}/u', $w)) { $cs = mb_str_split($w); for ($i = 0; $i < count($cs); $i++) $t[$cs[$i] . ($cs[$i + 1] ?? '')] = 1; }
            else $t[$w] = 1;
        }
        return $t;
    };
    $x = $tok($a); $y = $tok($b);
    if (!$x || !$y) return 0.0;
    return count(array_intersect_key($x, $y)) / count($x + $y);
}

/** 模型拆的要求 vs 人工最终要求：F1（相似度 ≥0.5 算对上，贪心一对一）+ 对上的那些里 必须/加分 判对的比例 */
function recruitReqF1(array $pred, array $gold): array {
    if (!$gold) return ['f1' => $pred ? 0.0 : 1.0, 'level_hits' => 0, 'matched' => 0];
    $pairs = [];
    foreach ($pred as $i => $p) foreach ($gold as $j => $g) {
        $s = recruitTextSim((string)$p['text'], (string)$g['text']);
        if ($s >= 0.5) $pairs[] = [$s, $i, $j];
    }
    usort($pairs, fn($a, $b) => $b[0] <=> $a[0]);
    $usedP = $usedG = []; $m = 0; $lv = 0;
    foreach ($pairs as [$s, $i, $j]) {
        if (isset($usedP[$i]) || isset($usedG[$j])) continue;
        $usedP[$i] = $usedG[$j] = true; $m++;
        if (($pred[$i]['level'] ?? '') === (($gold[$j]['level'] ?? '') === 'core' ? 'core' : 'nice')) $lv++;
    }
    $prec = $pred ? $m / count($pred) : 0; $rec = $m / count($gold);
    return ['f1' => $prec + $rec > 0 ? 2 * $prec * $rec / ($prec + $rec) : 0.0, 'level_hits' => $lv, 'matched' => $m];
}

/** yes/partial 的证据有几条在档案里找不到（模型编的）@return array{0:int,1:int} [编造数, 声称满足数] */
function recruitEvidenceHalluc($data, string $profileJson): array {
    $norm = recruitEvidenceNorm($profileJson);
    $bad = $all = 0;
    foreach ((array)($data['matches'] ?? []) as $m) foreach ((array)($m['requirements'] ?? []) as $r) {
        if (!in_array($r['met'] ?? '', ['yes', 'partial'], true)) continue;
        $all++;
        if (!recruitEvidenceFound((string)($r['evidence'] ?? ''), $norm)) $bad++;
    }
    return [$bad, $all];
}

/**
 * 用某个提示词把一批用例跑一遍，算指标。$multi = fn(array $requests): array（dvChatJsonMulti 形状，测试注入假的）
 * @return array{n:int, gold:int, metrics:array, fails:int[]}
 */
function recruitEvalRun(string $scene, array $cases, string $body, callable $multi): array {
    $req = [];
    foreach ($cases as $c) $req[$c['id']] = ['system' => $body, 'content' => [['type' => 'text', 'text' => (string)$c['input']['text']]]];
    $res = $req ? $multi($req) : [];
    $n = count($cases); $fail = 0; $failIds = []; $gold = 0;
    $f1 = []; $lvHit = 0; $lvAll = 0; $err = []; $hBad = 0; $hAll = 0; $empty = 0; $fnHit = 0; $fnAll = 0;
    foreach ($cases as $c) {
        $r = $res[$c['id']] ?? ['ok' => false, 'error_kind' => 'network'];
        $ctx = $c['input']['ctx'] ?? [];
        if ($scene === 'jd_req') $v = !empty($r['ok']) ? recruitValidateJdReq($r['data'] ?? null) : ['ok' => false];
        elseif ($scene === 'match') $v = !empty($r['ok']) ? recruitValidateMatch($r['data'] ?? null, $ctx['spec'] ?? [], (string)($ctx['profile'] ?? '')) : ['ok' => false];
        elseif ($scene === 'func') $v = !empty($r['ok']) ? recruitValidateFunc($r['data'] ?? null, [(string)($ctx['k'] ?? 't1')]) : ['ok' => false];
        elseif ($scene === 'segment') {   // 丢掉了任何一个赛道（编造 id / 没名字）都算这条用例失败，新旧版本对比才看得出退化
            $sv = !empty($r['ok']) ? recruitValidateSegment($r['data'] ?? null, (array)($ctx['keys'] ?? []), (array)($ctx['segments'] ?? [])) : null;
            $v = $sv !== null && $sv['dropped'] === 0 ? ['ok' => true, 'data' => []] : ['ok' => false];
        }
        elseif ($scene === 'coclass') $v = !empty($r['ok']) ? recruitValidateCoClass($r['data'] ?? null, [(string)($ctx['k'] ?? 'c1')]) : ['ok' => false];
        else $v = !empty($r['ok']) ? recruitValidateQuery($r['data'] ?? null) : ['ok' => false];
        if (!$v['ok']) { $fail++; $failIds[] = $c['id']; continue; }
        if ($scene === 'jd_req' && $c['gold'] && is_array($c['human']['requirements'] ?? null)) {
            $gold++;
            $x = recruitReqF1($v['data'], $c['human']['requirements']);
            $f1[] = $x['f1']; $lvHit += $x['level_hits']; $lvAll += $x['matched'];
        } elseif ($scene === 'match') {
            [$b, $a] = recruitEvidenceHalluc($r['data'] ?? [], (string)($ctx['profile'] ?? ''));
            $hBad += $b; $hAll += $a;
            if ($c['gold'] && is_array($c['human'])) {
                $pred = $v['data'][(string)($ctx['job_key'] ?? '')]['score'] ?? null;
                if ($pred !== null) {
                    $gold++;
                    $err[] = isset($c['human']['score']) ? abs((float)$pred - (float)$c['human']['score'])
                                                          : max(0.0, (float)$pred - (float)($c['human']['max'] ?? 5));
                }
            }
        } elseif ($scene === 'func') {
            if ($c['gold'] && is_array($c['human'])) {
                $gold++; $fnAll++;
                $p = $v['data'][(string)($ctx['k'] ?? 't1')] ?? [];
                if (($p['function'] ?? '') === ($c['human']['function'] ?? '') && ($p['seniority'] ?? '') === ($c['human']['seniority'] ?? '')) $fnHit++;
            }
        } elseif ($scene === 'coclass') {
            if ($c['gold'] && is_array($c['human'])) {
                $gold++; $fnAll++;
                if (($v['data'][(string)($ctx['k'] ?? 'c1')]['industry'] ?? '') === ($c['human']['industry'] ?? '')) $fnHit++;
            }
        } elseif ($scene === 'query' && !$v['data']['conditions']) {
            $empty++;
        }
    }
    $m = ['fail_rate' => $n ? round($fail / $n, 4) : 0.0];
    if ($f1) $m['f1'] = round(array_sum($f1) / count($f1), 4);
    if ($lvAll) $m['level_acc'] = round($lvHit / $lvAll, 4);
    if ($err) $m['mae'] = round(array_sum($err) / count($err), 4);
    if ($scene === 'match' && $hAll) $m['halluc_rate'] = round($hBad / $hAll, 4);
    if ($scene === 'func' && $fnAll) $m['func_acc'] = round($fnHit / $fnAll, 4);
    if ($scene === 'coclass' && $fnAll) $m['industry_acc'] = round($fnHit / $fnAll, 4);
    if ($scene === 'query') $m['empty_rate'] = $n - $fail > 0 ? round($empty / ($n - $fail), 4) : 0.0;
    return ['n' => $n, 'gold' => $gold, 'metrics' => $m, 'fails' => $failIds];
}

/**
 * 比较两组指标。@return array{better:string[], worse:string[]}（两边都有的指标才比；差距在容差内算持平）
 */
function recruitEvalCompare(array $a, array $b): array {
    $better = $worse = [];
    foreach ($b as $k => $vb) {
        if (!isset($a[$k], RECRUIT_EVAL_HIGHER[$k])) continue;
        $d = RECRUIT_EVAL_HIGHER[$k] ? $vb - $a[$k] : $a[$k] - $vb;   // >0 = 候选更好
        $tol = RECRUIT_EVAL_TOL[$k] ?? 0.02;
        if ($d > $tol) $better[] = $k; elseif ($d < -$tol) $worse[] = $k;
    }
    return ['better' => $better, 'worse' => $worse];
}

/**
 * 评测一个候选版本，按规则自动启用。$multi 同 recruitEvalRun；$notify = fn(array $params): void（启用后通知，默认企微）
 * @return array{decision:string, reason:string, active:array, candidate:array, compare:array, promoted:bool}
 *   decision：promoted 已自动启用 / kept 保持现行 / insufficient 用例不够、等手动 / same 候选就是现行版本
 */
function recruitPromptEvaluate(PDO $pdo, int $promptId, callable $multi, ?callable $notify = null): array {
    $st = $pdo->prepare("SELECT * FROM recruit_prompts WHERE id=?");
    $st->execute([$promptId]);
    $cand = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cand) throw new RuntimeException('prompt not found');
    $scene = (string)$cand['scene'];
    $act = recruitPrompt($pdo, $scene);
    if ((int)$act['id'] === $promptId) return ['decision' => 'same', 'reason' => 'same', 'promoted' => false];
    $cases = recruitEvalCases($pdo, $scene);
    $A = recruitEvalRun($scene, $cases, $act['body'], $multi);
    $B = recruitEvalRun($scene, $cases, (string)$cand['body'], $multi);
    $cmp = recruitEvalCompare($A['metrics'], $B['metrics']);
    if (count($cases) < RECRUIT_EVAL_MIN_CASES) {
        $decision = $cand['source'] === 'builtin' && !$cmp['worse'] ? 'promoted' : 'insufficient';
        $reason = $decision === 'promoted' ? 'builtin_few_cases' : 'few_cases';
    } elseif ($cmp['worse']) { $decision = 'kept'; $reason = 'worse:' . implode(',', $cmp['worse']); }
    elseif ($cmp['better']) { $decision = 'promoted'; $reason = 'better:' . implode(',', $cmp['better']); }
    else { $decision = 'kept'; $reason = 'no_gain'; }

    $out = ['decision' => $decision, 'reason' => $reason, 'cases' => count($cases),
            'active' => ['ver' => $act['ver']] + $A, 'candidate' => ['ver' => (string)$cand['ver']] + $B, 'compare' => $cmp, 'promoted' => false];
    $pdo->prepare("UPDATE recruit_prompts SET metrics_json=?, eval_at=(" . dbNow() . "), status=CASE WHEN status='draft' AND ?='kept' THEN 'rejected' ELSE status END WHERE id=?")
        ->execute([json_encode($B['metrics'] + ['n' => $B['n'], 'gold' => $B['gold']]), $decision, $promptId]);
    if ($act['id'] > 0) {
        $pdo->prepare("UPDATE recruit_prompts SET metrics_json=?, eval_at=(" . dbNow() . ") WHERE id=?")
            ->execute([json_encode($A['metrics'] + ['n' => $A['n'], 'gold' => $A['gold']]), (int)$act['id']]);
    }
    if ($decision === 'promoted') {
        recruitPromptActivate($pdo, $promptId);
        $out['promoted'] = true;
        $notify ??= function (array $p) use ($pdo) {
            if (!function_exists('createNotification')) return;
            require_once __DIR__ . '/recruit_alert.php';
            // 固定接收人 + 招聘抄送人（固定接收人被清空时也不至于没人知道提示词换了）
            foreach (array_unique(array_merge(recruitAlertConfig($pdo)['user_ids'], recruitNotifyCcIds($pdo))) as $uid) {
                createNotification($pdo, (int)$uid, 'recruit_prompt_promoted', '', '', '/settings?tab=recruit', 'recruit_prompt', $promptId, 'recruit_prompt_promoted', $p);
            }
        };
        $notify(['scene' => $scene, 'from' => $act['ver'], 'to' => (string)$cand['ver'], 'cases' => count($cases),
                 'summary' => recruitEvalSummary($A['metrics'], $B['metrics'])]);
    }
    return $out;
}

/** 指标变化一句话（通知里用）：fail_rate 0.25→0.05, mae 0.9→0.5 */
function recruitEvalSummary(array $a, array $b): string {
    $s = [];
    foreach ($b as $k => $v) if (isset($a[$k])) $s[] = "$k {$a[$k]}→$v";
    return implode(', ', $s);
}

/**
 * 跑一条评测记录（worker 调）：queued → running → done / failed。@return string 最终状态
 */
function recruitPromptEvalJob(PDO $pdo, int $evalId, callable $multi, ?callable $notify = null): string {
    $st = $pdo->prepare("SELECT * FROM recruit_prompt_evals WHERE id=?");
    $st->execute([$evalId]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e || !in_array($e['status'], ['queued', 'running'], true)) return (string)($e['status'] ?? 'missing');
    $pdo->prepare("UPDATE recruit_prompt_evals SET status='running' WHERE id=?")->execute([$evalId]);
    try {
        $r = recruitPromptEvaluate($pdo, (int)$e['prompt_id'], $multi, $notify);
        $pdo->prepare("UPDATE recruit_prompt_evals SET status='done', decision=?, result_json=?, finished_at=(" . dbNow() . ") WHERE id=?")
            ->execute([$r['decision'], json_encode($r, JSON_UNESCAPED_UNICODE), $evalId]);
        return 'done';
    } catch (Throwable $x) {
        $pdo->prepare("UPDATE recruit_prompt_evals SET status='failed', error=?, finished_at=(" . dbNow() . ") WHERE id=?")
            ->execute([mb_substr($x->getMessage(), 0, 500), $evalId]);
        return 'failed';
    }
}

/** 排一次评测并拉 worker。同一版本已有排队 / 进行中的就复用。@return array{ok:bool, id?:int, error?:string} */
function recruitPromptQueueEval(PDO $pdo, int $promptId, int $uid = 0): array {
    $st = $pdo->prepare("SELECT id FROM recruit_prompt_evals WHERE prompt_id=? AND status IN ('queued','running')");
    $st->execute([$promptId]);
    if ($id = (int)$st->fetchColumn()) return ['ok' => true, 'id' => $id];
    $st = $pdo->prepare("SELECT scene FROM recruit_prompts WHERE id=?");
    $st->execute([$promptId]);
    $scene = $st->fetchColumn();
    if (!$scene) return ['ok' => false, 'error' => 'notFound'];
    $pdo->prepare("INSERT INTO recruit_prompt_evals (scene, prompt_id, status, created_by, created_at) VALUES (?, ?, 'queued', ?, (" . dbNow() . "))")
        ->execute([$scene, $promptId, $uid]);
    $id = (int)$pdo->lastInsertId();
    require_once __DIR__ . '/worker_spawn.php';
    $sp = workerSpawn($pdo, dirname(__DIR__) . '/scripts/recruit_prompt_eval_worker.php', [$id], 'recruit_prompt_eval');
    if (!$sp['ok']) {
        $pdo->prepare("UPDATE recruit_prompt_evals SET status='failed', error=? WHERE id=?")->execute([mb_substr((string)$sp['error'], 0, 500), $id]);
        return ['ok' => false, 'error' => (string)$sp['error']];
    }
    return ['ok' => true, 'id' => $id];
}

/** 评测用的并发调用：dvChatJsonMulti + 记 ai_api_usage（recruit_prompt_eval，计入每日预算） */
function recruitEvalMulti(PDO $pdo): callable {
    return function (array $req) use ($pdo) {
        $res = dvChatJsonMulti($pdo, $req);
        foreach ($res as $r) {
            if (function_exists('logAiUsage') && ($r['error_kind'] ?? '') !== 'not_configured') {
                logAiUsage($pdo, 'recruit_prompt_eval', ['model' => '', 'usage' => $r['usage'] ?? [], 'elapsed' => $r['elapsed'] ?? 0],
                    !empty($r['ok']) ? 'success' : 'failed', 0, (string)($r['error'] ?? ''), '', '', 'recruit_prompt', 0);
            }
        }
        return $res;
    };
}
