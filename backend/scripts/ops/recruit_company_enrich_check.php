<?php
/**
 * 企业库资料补全 · 生产连通性自检（只读：各打 1 次请求，不写库）。
 * 客户情报在生产是转给国内情报服务器跑的（customer_intel.php intelTriggerWorker），生产机能不能直连 Tavily / Firecrawl / 大模型没验证过；
 * 企业库补全在生产定时任务里直连，打开 recruit.company.enrich_enabled 之前先跑这个：
 *
 *   cd /var/www/openhunter/backend && php scripts/ops/recruit_company_enrich_check.php
 *
 * 三项都 ✓ 才打开自动补全；有 ✗ 把输出贴回来（输出里不含任何 key）。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }
$root = dirname(__DIR__, 2);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
$pdo = Database::getInstance()->getConnection();

$t = microtime(true);
try { $r = tavilySearch($pdo, '"Unilever Indonesia" official website', 3); printf("✓ Tavily 搜索：%d 条 · %.1f 秒\n", count($r), microtime(true) - $t); }
catch (Throwable $e) { printf("✗ Tavily 搜索：%s · %.1f 秒\n", $e->getMessage(), microtime(true) - $t); }

$t = microtime(true);
$md = firecrawlScrape($pdo, 'https://www.unilever.co.id/');
printf($md !== '' ? "✓ Firecrawl 抓取：%d 字 · %.1f 秒\n" : "✗ Firecrawl 抓取：没抓到（没配 key / 连不上 / 被拒）· %2\$.1f 秒\n", mb_strlen($md), microtime(true) - $t);

$t = microtime(true);
$r = dvChatJsonMulti($pdo, [0 => ['system' => '只返回 JSON：{"ok":true}', 'content' => [['type' => 'text', 'text' => 'ping']]]])[0] ?? [];
printf(!empty($r['ok']) ? "✓ 大模型：%.1f 秒\n" : "✗ 大模型：%s · %.1f 秒\n", ...(!empty($r['ok']) ? [microtime(true) - $t] : [($r['error_kind'] ?? '') . ' ' . ($r['error'] ?? ''), microtime(true) - $t]));
