<?php
/**
 * 招聘 · 向量接口体检（2026-09-24 选方案 A：给现有 Gemini key 开 embedding 权限）
 *
 * 生产上向量化一直「被拒」，人才关联 / 语义搜索 / 相似候选人因此都是空的。这个脚本告诉你到底卡在哪：
 *   ① 当前配置（端点、模型、key 末 4 位）  ② 最近几次向量调用的报错原文
 *   ③ 这把 key 能看到哪些 embedding 模型  ④ 实打一条（一句话，费用可忽略）并给出该去哪改
 *
 *   php scripts/ops/recruit_embed_check.php
 *   php scripts/ops/recruit_embed_check.php --model=text-embedding-004   # 换个模型名试（不改配置）
 *
 * 只读：不改任何配置、不写向量、不记 AI 用量；只发一次列模型 + 一条一句话的向量请求。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }
$root = dirname(__DIR__, 2);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_embed.php';

$tryModel = '';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--model=([\w.\-\/]+)$/', $a, $m)) $tryModel = $m[1];
    else { fwrite(STDERR, "未知参数 $a\n"); exit(1); }
}

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "① 配置\n";
$cfg = openaiOcrConfig($pdo);
if (!$cfg) { echo "  ✗ 没有 AI key，或解密失败（系统设置 · OCR/AI 配置）\n"; exit(1); }
$model = $tryModel !== '' ? $tryModel : (string)recruitSemConfig($pdo)['model'];
$chatEp = (string)$cfg['endpoint'];
$embEp = preg_replace('#/chat/completions/?$#', '/embeddings', $chatEp);
$isGoogle = str_contains($chatEp, 'generativelanguage.googleapis.com');
printf("  端点：%s\n  向量端点：%s\n  解析用模型：%s\n  向量模型：%s%s\n  key：…%s\n",
    $chatEp, $embEp, $cfg['model'], $model, $tryModel !== '' ? '（--model 临时试，未改配置）' : '', substr($cfg['api_key'], -4));
if (!$isGoogle) echo "  ⚠️ 端点不是 Google 官方（generativelanguage.googleapis.com），可能是中转，中转常常不支持 embeddings\n";
if ($embEp === $chatEp) { echo "  ✗ 端点不是 …/chat/completions 结尾，推不出向量地址\n"; exit(1); }

echo "\n② 最近 5 次向量调用\n";
try {
    $rows = $pdo->query("SELECT called_at, status, error, result_summary FROM ai_api_usage WHERE scene='recruit_embed' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "  （没有记录）\n";
    foreach ($rows as $r) printf("  %s %-7s %s %s\n", $r['called_at'], $r['status'], $r['result_summary'], $r['error']);
} catch (Throwable $e) { echo "  读不到 ai_api_usage：{$e->getMessage()}\n"; }

echo "\n③ 这把 key 能看到的 embedding 模型\n";
$modelsEp = preg_replace('#/chat/completions/?$#', '/models', $chatEp);
$ch = curl_init($modelsEp);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['api_key']]]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($resp === false || $code !== 200) {
    printf("  列不出来（HTTP %d %s）——不影响判断，看 ④\n", $code, $resp === false ? curl_error($ch) : mb_substr((string)$resp, 0, 200));
} else {
    $names = array_values(array_filter(array_map(fn($m) => preg_replace('#^models/#', '', (string)($m['id'] ?? '')),
        (array)(json_decode((string)$resp, true)['data'] ?? [])), fn($n) => stripos($n, 'embed') !== false));
    echo $names ? '  ' . implode("\n  ", $names) . "\n" : "  （一个都没有）\n";
    if ($names && !in_array($model, $names, true)) echo "  ⚠️ 配置的 $model 不在列表里，换列表里的名字用 --model= 试\n";
}

echo "\n④ 实打一条\n";
$sem = recruitSemConfig($pdo);
$embed = recruitEmbedHttp($pdo, true);
if ($tryModel !== '') {   // 临时换模型：复用同一个 HTTP 实现，只替换请求里的 model
    $embed = (function () use ($pdo, $tryModel) {
        $cfg = openaiOcrConfig($pdo);
        $ep = preg_replace('#/chat/completions/?$#', '/embeddings', (string)$cfg['endpoint']);
        return function (array $texts) use ($cfg, $ep, $tryModel) {
            $ch = curl_init($ep);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                CURLOPT_POSTFIELDS => json_encode(['model' => $tryModel, 'input' => $texts], JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['api_key']]]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resp === false) return ['ok' => false, 'error' => curl_error($ch)];
            if ($code !== 200) return ['ok' => false, 'error' => "HTTP $code: " . substr((string)$resp, 0, 300)];
            $v = json_decode((string)$resp, true)['data'][0]['embedding'] ?? null;
            return is_array($v) ? ['ok' => true, 'vectors' => [$v]] : ['ok' => false, 'error' => '返回里没有 embedding'];
        };
    })();
}
$r = $embed(['Network engineer, 3 years NOC, medical device sales']);
if (!empty($r['ok'])) {
    printf("  ✓ 通了：返回 %d 维向量\n", count($r['vectors'][0]));
    if ($tryModel !== '' && $tryModel !== $sem['model']) echo "  → 这个模型能用。要切换告诉开发改 recruit.sem.config 的 model（换模型会全量重算向量）\n";
    echo "  → 下一轮定时任务会自动补算全部向量，跑完人才关联 / 语义搜索就有结果了\n";
    exit(0);
}
$err = (string)($r['error'] ?? '');
echo "  ✗ $err\n\n  怎么改：\n";
if (preg_match('/API_KEY_SERVICE_BLOCKED|are blocked|API restrictions/i', $err)) {
    echo "  key 设了「API 限制」，没包含 Generative Language API：\n"
       . "  Google Cloud 控制台 → API 和服务 → 凭据 → 点这把 key → API 限制 → 勾上「Generative Language API」→ 保存（几分钟生效）\n";
} elseif (preg_match('/SERVICE_DISABLED|has not been used|is disabled/i', $err)) {
    echo "  key 所在项目没启用 Generative Language API：\n"
       . "  Google Cloud 控制台（选 key 所在的项目）→ API 和服务 → 库 → 搜「Generative Language API」→ 启用\n";
} elseif (preg_match('/HTTP 429|RESOURCE_EXHAUSTED|quota/i', $err)) {
    echo "  配额用完（免费层 embedding 有每分钟 / 每天上限）：\n"
       . "  Google AI Studio → 这把 key 所在项目 → 开通付费（Tier 1），或 Cloud 控制台 → 配额 里看 embed 相关项\n";
} elseif (preg_match('/HTTP 404|not found|NOT_FOUND/i', $err)) {
    echo "  模型名不对 / 这把 key 看不到该模型：用 ③ 列出来的名字 --model= 再试\n";
} elseif (preg_match('/HTTP 40[13]|PERMISSION_DENIED|API key not valid/i', $err)) {
    echo "  权限不足：Cloud 控制台 → 凭据 → 这把 key → 「应用限制」若是 IP 限制，确认包含生产机出口 IP；\n"
       . "  「API 限制」包含 Generative Language API。都没问题就把上面整段报错发给开发\n";
} else {
    echo "  把上面整段输出发给开发\n";
}
exit(1);
