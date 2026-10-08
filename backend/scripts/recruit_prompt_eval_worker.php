<?php
/**
 * 提示词回归评测 worker（异步）：php scripts/recruit_prompt_eval_worker.php <eval_id>
 * 由 recruitPromptQueueEval 经 workerSpawn 拉起；跑完一条评测退出。逻辑在 includes/recruit_prompts.php（recruitPromptEvalJob）。
 * 现行版本与候选版本在同一批回归用例上各跑一遍，候选版本更好就自动启用并企微通知（2026-09-24 选定自动启用）。
 * ⛔ define('JCT_DOCUMENT_WORKER')：CLI 下 dvChatJson 用长超时（§7.10 的 100 秒上限只管 web 请求）。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }
define('JCT_DOCUMENT_WORKER', true);
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_prompts.php';

$id = (int)($argv[1] ?? 0);
if ($id <= 0) { fwrite(STDERR, "用法：php scripts/recruit_prompt_eval_worker.php <eval_id>\n"); exit(1); }
$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$t0 = microtime(true);
$st = recruitPromptEvalJob($pdo, $id, recruitEvalMulti($pdo));
echo date('c'), " prompt eval #$id → $st (" . round(microtime(true) - $t0, 1) . "s)\n";
exit($st === 'done' ? 0 : 1);
