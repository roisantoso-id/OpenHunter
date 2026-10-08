<?php
/**
 * 推荐材料生成 worker（异步）：php scripts/recruit_reco_worker.php <recommendation_id>
 * 由 recruitRecoGenerate 经 workerSpawn 拉起；一次只做一份，做完退出。逻辑在 includes/recruit_reco.php。
 * ⛔ define('JCT_DOCUMENT_WORKER')：CLI 下 dvChatJson 用 [120,60] 秒尝试超时（§7.10 的 100 秒上限只管 web 请求）。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }
define('JCT_DOCUMENT_WORKER', true);
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_reco.php';

$id = (int)($argv[1] ?? 0);
if ($id <= 0) { fwrite(STDERR, "用法：php scripts/recruit_reco_worker.php <id>\n"); exit(1); }
$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$t0 = microtime(true);
$st = recruitRecoRun($pdo, $id, fn(string $sys, array $content) => dvChatJson($pdo, $sys, $content));
echo date('c'), " recommendation #$id → $st (" . round(microtime(true) - $t0, 1) . "s)\n";
exit($st === 'ready' ? 0 : 1);
