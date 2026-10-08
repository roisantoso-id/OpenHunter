<?php
/**
 * 租户管理（每个租户一个独立库，互不可见）。
 *
 *   php scripts/tenant.php list
 *   php scripts/tenant.php create <slug> "<显示名>" <管理员账号> <管理员密码>   # 注册 + 建库建表 + 建管理员
 *   php scripts/tenant.php disable <slug>      # 停用：不能登录、投递链接失效，数据保留
 *   php scripts/tenant.php enable <slug>
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$cmd = $argv[1] ?? '';
$control = Database::control();
switch ($cmd) {
    case 'list':
        foreach (Tenant::all() as $t) printf("%-4d %-20s %-10s %s\n", $t['id'], $t['slug'], $t['status'], $t['name']);
        break;
    case 'create':
        [$slug, $name, $admin, $pw] = [strtolower($argv[2] ?? ''), $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''];
        if (!Tenant::valid($slug) || $admin === '' || $pw === '') { fwrite(STDERR, "用法: php scripts/tenant.php create <slug> \"<显示名>\" <管理员账号> <管理员密码>\n"); exit(1); }
        $cmdline = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/install.php') . ' --apply'
            . ' --tenant=' . escapeshellarg($slug) . ' --tenant-name=' . escapeshellarg($name)
            . ' --admin=' . escapeshellarg($admin) . ' --password=' . escapeshellarg($pw) . ' --name=' . escapeshellarg($admin);
        passthru($cmdline, $code);
        exit($code);
    case 'disable':
    case 'enable':
        $slug = strtolower($argv[2] ?? '');
        $st = $control->prepare("UPDATE tenants SET status=? WHERE slug=?");
        $st->execute([$cmd === 'disable' ? 'disabled' : 'active', $slug]);
        echo $st->rowCount() ? "ok {$slug} → {$cmd}d\n" : "没有这个租户: {$slug}\n";
        break;
    default:
        fwrite(STDERR, "用法: php scripts/tenant.php list|create|disable|enable\n");
        exit(1);
}
