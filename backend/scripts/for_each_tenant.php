<?php
/**
 * 对每个活跃租户各跑一遍某个 cron 脚本（每个租户一个独立进程，OPENHUNTER_TENANT 指向它）。
 *
 *   * /5 * * * * php /path/to/backend/scripts/for_each_tenant.php cron_recruit_run.php
 *
 * 一个租户失败不影响其它租户；任何一个失败则整体退出码为 1。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$script = $argv[1] ?? '';
$path = __DIR__ . '/' . basename($script);
if ($script === '' || !is_file($path)) { fwrite(STDERR, "用法: php scripts/for_each_tenant.php <scripts/ 下的脚本名> [参数…]\n"); exit(1); }
$extra = implode(' ', array_map('escapeshellarg', array_slice($argv, 2)));
$fail = 0;
foreach (Tenant::all() as $t) {
    if ($t['status'] !== 'active') continue;
    echo "[tenant {$t['slug']}] {$script}\n";
    passthru('OPENHUNTER_TENANT=' . escapeshellarg($t['slug']) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' ' . $extra, $code);
    if ($code !== 0) { echo "[tenant {$t['slug']}] 退出码 {$code}\n"; $fail = 1; }
}
exit($fail);
