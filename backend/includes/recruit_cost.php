<?php
/**
 * OpenHunter · AI 成本（2026-09-24「解析一定要关注成本，每份简历多少 token 要记清楚」）
 *
 * 记账：每次真正发出去的调用都写 ai_api_usage（scene 以 recruit_ 开头，prompt/completion/total tokens 为接口实报）。
 * 价格：system_settings `recruit.ai.price`（JSON，美元/百万 token），默认值是占位，**上线前按 Google 官方价目核对**：
 *   {"chat_in":0.10,"chat_out":0.40,"embed_in":0.15}
 * 预算：system_settings `recruit.ai.daily_token_budget`（整数，默认 2,000,000）——cron 每轮开跑前查当天 recruit_* 已用
 *       token，超了就整轮停，第二天自动恢复。补拉历史邮件时防止一次性烧掉一大笔。
 */

const RECRUIT_PRICE_DEFAULT = ['chat_in' => 0.10, 'chat_out' => 0.40, 'embed_in' => 0.15];
const RECRUIT_DAILY_TOKEN_BUDGET = 2000000;

function recruitPrice(PDO $pdo): array {
    $p = RECRUIT_PRICE_DEFAULT;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='recruit.ai.price'");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
        if (is_array($j)) foreach ($p as $k => $_) if (isset($j[$k]) && is_numeric($j[$k])) $p[$k] = (float)$j[$k];
    } catch (Throwable $e) { /* 默认价 */ }
    return $p;
}

/** 一次调用的美元成本。embedding 只有输入计费 */
function recruitCostUsd(string $scene, int $promptTokens, int $completionTokens, array $price): float {
    if ($scene === 'recruit_embed') return $promptTokens * $price['embed_in'] / 1e6;
    return ($promptTokens * $price['chat_in'] + $completionTokens * $price['chat_out']) / 1e6;
}

/**
 * 区间内的实际用量（按 scene 分组）+ 每份简历平均。$from/$to 为 'Y-m-d H:i:s'。
 * 每份简历 = 解析 token ÷ 解析成功份数（向量、匹配按人算，另列）。
 */
function recruitAiUsage(PDO $pdo, string $from, string $to): array {
    $price = recruitPrice($pdo);
    $st = $pdo->prepare("SELECT scene, status, COUNT(*) n, COALESCE(SUM(prompt_tokens),0) pin, COALESCE(SUM(completion_tokens),0) pout,
                                COALESCE(SUM(total_tokens),0) tot
                         FROM ai_api_usage WHERE scene LIKE 'recruit%' AND called_at>=? AND called_at<=? GROUP BY scene, status");
    $st->execute([$from, $to]);
    $by = []; $sum = ['calls' => 0, 'failed' => 0, 'tokens' => 0, 'usd' => 0.0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s = &$by[$r['scene']];
        $s ??= ['scene' => $r['scene'], 'calls' => 0, 'failed' => 0, 'prompt' => 0, 'completion' => 0, 'tokens' => 0, 'usd' => 0.0];
        $usd = recruitCostUsd($r['scene'], (int)$r['pin'], (int)$r['pout'], $price);
        $s['calls'] += (int)$r['n'];
        if ($r['status'] !== 'success') $s['failed'] += (int)$r['n'];
        $s['prompt'] += (int)$r['pin']; $s['completion'] += (int)$r['pout']; $s['tokens'] += (int)$r['tot']; $s['usd'] += $usd;
        $sum['calls'] += (int)$r['n']; $sum['tokens'] += (int)$r['tot']; $sum['usd'] += $usd;
        if ($r['status'] !== 'success') $sum['failed'] += (int)$r['n'];
        unset($s);
    }
    $parseScenes = ['recruit_parse', 'recruit_parse_vision'];
    $parseOk = 0; $parseTok = 0; $parseUsd = 0.0;
    foreach ($parseScenes as $sc) if (isset($by[$sc])) { $parseTok += $by[$sc]['tokens']; $parseUsd += $by[$sc]['usd']; $parseOk += $by[$sc]['calls'] - $by[$sc]['failed']; }
    $sum['usd'] = round($sum['usd'], 4);
    foreach ($by as &$s) $s['usd'] = round($s['usd'], 4);
    unset($s);
    return ['by_scene' => array_values($by), 'total' => $sum, 'price' => $price,
            'per_resume' => ['parsed' => $parseOk, 'tokens' => $parseOk ? (int)round($parseTok / $parseOk) : null,
                             'usd' => $parseOk ? round($parseUsd / $parseOk, 5) : null]];
}

/** 今天（雅加达日期）recruit_* 已用 token；与预算比较决定 cron 是否继续 */
function recruitTokensToday(PDO $pdo): int {
    $st = $pdo->prepare("SELECT COALESCE(SUM(total_tokens),0) FROM ai_api_usage WHERE scene LIKE 'recruit%' AND called_at>=?");
    $st->execute([date('Y-m-d 00:00:00')]);
    return (int)$st->fetchColumn();
}

function recruitDailyBudget(PDO $pdo): int {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='recruit.ai.daily_token_budget'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v !== false && is_numeric($v) && (int)$v > 0) return (int)$v;
    } catch (Throwable $e) {}
    return RECRUIT_DAILY_TOKEN_BUDGET;
}

/**
 * 粗估一段文字的 token：ASCII 约 4 字符 1 token，中文等非 ASCII 约 1 字 1 token。
 * 只用于「还没解析的简历大概要花多少」的预估；实际以接口回报的 usage 为准。
 */
function recruitEstimateTokens(string $text): int {
    $ascii = preg_match_all('/[\x00-\x7F]/', $text);
    $other = mb_strlen($text) - $ascii;
    return (int)ceil($ascii / 4 + $other);
}
