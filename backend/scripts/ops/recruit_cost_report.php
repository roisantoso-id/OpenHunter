<?php
/**
 * 招聘 AI 成本报表（2026-09-24「每份简历多少 token 要记清楚」）。只读，不调任何接口。
 *
 *   php scripts/ops/recruit_cost_report.php                  # 本月实际用量 + 待解析队列预估
 *   php scripts/ops/recruit_cost_report.php --from=2026-09-01 --to=2026-09-30
 *
 * 实际：ai_api_usage 里接口回报的 token（recruit_* 各场景），按 recruit.ai.price 折美元。
 * 预估：还没解析的简历按「系统提示 + 原文（截到 3 万字）」估输入、按已解析的平均输出估输出；
 *       扫描件/图片走看图识别，按每页约 258 token（Gemini 图片计价口径）估。以实际回报为准。
 */
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__, 2);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_match.php';
require_once $root . '/includes/recruit_cost.php';

$from = date('Y-m-01'); $to = date('Y-m-d');
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(from|to)=(\d{4}-\d{2}-\d{2})$/', $a, $m)) ${$m[1]} = $m[2];
    else { fwrite(STDERR, "未知参数 $a\n"); exit(1); }
}
$pdo = Database::getInstance()->getConnection();
$u = recruitAiUsage($pdo, "$from 00:00:00", "$to 23:59:59");
$p = $u['price'];
printf("=== 实际用量 %s ~ %s（价格 $/百万 token：对话入 %.3f 出 %.3f，向量 %.3f）===\n", $from, $to, $p['chat_in'], $p['chat_out'], $p['embed_in']);
printf("  %-22s %6s %6s %12s %12s %12s %10s\n", '场景', '调用', '失败', '输入token', '输出token', '合计token', '美元');
foreach ($u['by_scene'] as $s) printf("  %-22s %6d %6d %12s %12s %12s %10.4f\n", $s['scene'], $s['calls'], $s['failed'],
    number_format($s['prompt']), number_format($s['completion']), number_format($s['tokens']), $s['usd']);
printf("  %-22s %6d %6d %12s %12s %12s %10.4f\n", '合计', $u['total']['calls'], $u['total']['failed'], '', '', number_format($u['total']['tokens']), $u['total']['usd']);
$pr = $u['per_resume'];
echo $pr['parsed'] ? sprintf("  每份简历解析平均：%s token ≈ $%.5f（%d 份）\n", number_format($pr['tokens']), $pr['usd'], $pr['parsed'])
                   : "  （还没有实际解析记录，下面只能给预估）\n";

// 预估待解析
$sysTok = recruitEstimateTokens(recruitParseSystemPrompt());
$avgOut = (int)$pdo->query("SELECT COALESCE(AVG(completion_tokens),0) FROM ai_api_usage WHERE scene IN ('recruit_parse','recruit_parse_vision') AND status='success' AND completion_tokens>0")->fetchColumn();
if ($avgOut <= 0) $avgOut = 1500;   // 还没有实测时的假设：抽取 JSON 约 1500 token
$rows = $pdo->query("SELECT id, file_ext, text_chars, file_size, raw_text FROM recruit_resumes WHERE parse_status IN ('pending','retry')")->fetchAll(PDO::FETCH_ASSOC);
$in = 0; $vision = 0;
foreach ($rows as $r) {
    if ((int)$r['text_chars'] < RECRUIT_TEXT_MIN_CHARS && in_array($r['file_ext'], ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        $pages = $r['file_ext'] === 'pdf' ? max(1, (int)ceil((int)$r['file_size'] / 150000)) : 1;   // 扫描件约 150KB/页
        $in += $sysTok + 258 * $pages; $vision++;
    } else {
        $in += $sysTok + recruitEstimateTokens(mb_substr((string)$r['raw_text'], 0, RECRUIT_TEXT_MAX_CHARS));
    }
}
$n = count($rows);
$out = $n * $avgOut;
$usd = ($in * $p['chat_in'] + $out * $p['chat_out']) / 1e6;
printf("\n=== 待解析预估 ===\n  %d 份（其中看图识别 %d 份）：输入约 %s + 输出约 %s token ≈ $%.4f（每份约 %s token）\n",
    $n, $vision, number_format($in), number_format($out), $usd, $n ? number_format((int)(($in + $out) / $n)) : 0);
echo "  另：每人向量化约 500 token（≈$" . sprintf('%.5f', 500 * $p['embed_in'] / 1e6) . "）；匹配每人一次约 2,500 入 + 1,000 出（只评语义分前 N 名）\n";
printf("  今天已用 %s / 预算 %s token\n", number_format(recruitTokensToday($pdo)), number_format(recruitDailyBudget($pdo)));
