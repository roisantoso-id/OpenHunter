<?php
/**
 * OpenHunter 安装 / 升级：建基础表 → 依次跑 scripts/migrations/ 下的招聘迁移 → 建第一个管理员。
 * 幂等：重复执行只补缺的表和列，不动已有数据。
 *
 *   php scripts/install.php                                   # dry-run，只打印将做什么
 *   php scripts/install.php --apply                           # 执行
 *   php scripts/install.php --apply --admin=admin --password=xxx --name="Admin"   # 同时建管理员（已有同名账号则跳过）
 *
 * 多租户：每个租户一个独立库。默认装 `default` 租户；开新租户见 scripts/tenant.php（内部就是调本脚本）：
 *   php scripts/install.php --apply --tenant=acme --tenant-name="Acme Recruiting" --admin=boss --password=xxx
 */

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_-]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$apply = !empty($args['apply']);
$tenantSlug = strtolower((string)($args['tenant'] ?? ohEnv('OPENHUNTER_TENANT', 'default')));
if (!Tenant::valid($tenantSlug)) { echo "❌ --tenant 不合法（小写字母 / 数字 / 下划线，2-31 位，不能叫 control）\n"; exit(1); }
if ($apply) Tenant::register($tenantSlug, (string)($args['tenant-name'] ?? $tenantSlug));
Tenant::set($tenantSlug);
$pdo = Database::getInstance()->getConnection();
$my = dbIsMysql();
$pk = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$str = fn(int $n) => $my ? "VARCHAR($n)" : 'TEXT';
$tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
$now = $my ? 'CURRENT_TIMESTAMP' : 'CURRENT_TIMESTAMP';

echo "=== OpenHunter install " . ($apply ? '[APPLY]' : '[DRY-RUN]') . " (" . ($my ? 'mysql' : 'sqlite') . ", tenant={$tenantSlug}) ===\n\n";

$tables = [
    'users' => "CREATE TABLE users (
        id $pk,
        username {$str(100)} NOT NULL,
        name {$str(191)} NOT NULL DEFAULT '',
        email {$str(191)} NOT NULL DEFAULT '',
        phone {$str(64)} NOT NULL DEFAULT '',
        role {$str(32)} NOT NULL DEFAULT 'recruiter',
        status {$str(16)} NOT NULL DEFAULT 'active',
        password_hash {$str(255)} NOT NULL DEFAULT '',
        extra_modules TEXT,
        lang {$str(8)} NOT NULL DEFAULT 'zh-CN',
        created_at DATETIME DEFAULT $now
    )$tail",
    'role_permissions' => "CREATE TABLE role_permissions (
        id $pk,
        role {$str(32)} NOT NULL,
        modules TEXT,
        updated_at DATETIME DEFAULT $now
    )$tail",
    'system_settings' => "CREATE TABLE system_settings (
        id $pk,
        setting_key {$str(191)} NOT NULL,
        setting_value TEXT,
        description TEXT,
        updated_at DATETIME DEFAULT $now
    )$tail",
    'notifications' => "CREATE TABLE notifications (
        id $pk,
        user_id INT NOT NULL,
        type {$str(64)} NOT NULL,
        title TEXT NOT NULL,
        content TEXT,
        link_url TEXT,
        ref_type {$str(64)} NOT NULL DEFAULT '',
        ref_id INT NOT NULL DEFAULT 0,
        is_read INT NOT NULL DEFAULT 0,
        read_at DATETIME NULL,
        template_key {$str(64)} NOT NULL DEFAULT '',
        template_params TEXT,
        created_at DATETIME DEFAULT $now
    )$tail",
    'operation_logs' => "CREATE TABLE operation_logs (
        id $pk,
        user_id INT NOT NULL DEFAULT 0,
        username {$str(191)} NOT NULL DEFAULT '',
        action {$str(64)} NOT NULL,
        target_type {$str(64)} NOT NULL DEFAULT '',
        target_id INT NOT NULL DEFAULT 0,
        detail TEXT,
        ip {$str(64)} NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT $now
    )$tail",
    'ai_api_usage' => "CREATE TABLE ai_api_usage (
        id $pk,
        scene {$str(64)} NOT NULL,
        model {$str(128)} NOT NULL DEFAULT '',
        status {$str(16)} NOT NULL DEFAULT 'success',
        error TEXT,
        prompt_tokens INT NOT NULL DEFAULT 0,
        completion_tokens INT NOT NULL DEFAULT 0,
        total_tokens INT NOT NULL DEFAULT 0,
        elapsed " . ($my ? 'DECIMAL(10,2)' : 'REAL') . " NOT NULL DEFAULT 0,
        file_name {$str(191)} NOT NULL DEFAULT '',
        result_summary TEXT,
        biz_type {$str(64)} NOT NULL DEFAULT '',
        biz_id INT NOT NULL DEFAULT 0,
        called_by INT NOT NULL DEFAULT 0,
        called_by_name {$str(191)} NOT NULL DEFAULT '',
        called_at DATETIME DEFAULT $now
    )$tail",
    // 公休日（提醒在公休日不发）。没数据 = 当工作日；需要的话自己导入
    'holiday_cache' => "CREATE TABLE holiday_cache (
        id $pk,
        year INT NOT NULL,
        country {$str(8)} NOT NULL,
        holiday_date {$str(10)} NOT NULL,
        name {$str(191)} NOT NULL,
        type {$str(16)} NOT NULL DEFAULT 'holiday',
        fetched_at DATETIME DEFAULT $now
    )$tail",
    // 招聘客户（includes/host.php）：name = 显示名，legal_name = 法定名称（推荐信抬头 / 签约主体）
    'recruit_clients' => "CREATE TABLE recruit_clients (
        id $pk,
        name {$str(191)} NOT NULL,
        legal_name {$str(191)} NOT NULL DEFAULT '',
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT $now,
        updated_at DATETIME DEFAULT $now
    )$tail",
];
$indexes = [
    'users' => ["CREATE UNIQUE INDEX uk_users_username ON users(username)"],
    'role_permissions' => ["CREATE UNIQUE INDEX uk_role_permissions_role ON role_permissions(role)"],
    'system_settings' => ["CREATE UNIQUE INDEX uk_system_settings_key ON system_settings(setting_key)"],
    'notifications' => ["CREATE INDEX idx_notifications_user ON notifications(user_id, is_read, created_at)"],
    'operation_logs' => ["CREATE INDEX idx_oplog_action ON operation_logs(action, created_at)"],
    'ai_api_usage' => ["CREATE INDEX idx_aiu_scene ON ai_api_usage(scene, called_at)", "CREATE INDEX idx_aiu_biz ON ai_api_usage(biz_type, biz_id)"],
    'holiday_cache' => ["CREATE INDEX idx_holiday_cache ON holiday_cache(country, holiday_date)"],
    'recruit_clients' => ["CREATE INDEX idx_recruit_clients_name ON recruit_clients(name)"],
];

echo "--- 基础表 ---\n";
foreach ($tables as $t => $ddl) {
    if (dbTableExists($pdo, $t)) { echo "[skip] $t\n"; continue; }
    echo "[add ] $t\n";
    if ($apply) {
        $pdo->exec($ddl);
        foreach ($indexes[$t] ?? [] as $ix) $pdo->exec($ix);
    }
}

// 默认角色：recruiter = 招聘顾问（只看自己名下）；recruit_manager = 看全部 + 管理（admin 角色本身拥有全部权限）
if ($apply) {
    $roles = ['recruiter' => ['recruit'], 'recruit_manager' => ['recruit', 'recruit_all', 'recruit_admin']];
    $chk = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role=?");
    $ins = $pdo->prepare("INSERT INTO role_permissions (role, modules) VALUES (?, ?)");
    foreach ($roles as $r => $mods) {
        $chk->execute([$r]);
        if (!$chk->fetchColumn()) { $ins->execute([$r, json_encode($mods)]); echo "[role] $r\n"; }
    }
    // 招聘流水线默认开（解析 / 匹配）；没配 AI key 时流水线会自己跳过
    if ($pdo->query("SELECT COUNT(*) FROM system_settings WHERE setting_key='ai_intake.recruit.enabled'")->fetchColumn() == 0) {
        setSystemSetting($pdo, 'ai_intake.recruit.enabled', '1');
    }
}

echo "\n--- 招聘迁移 ---\n";
if (!$apply && !dbTableExists($pdo, 'users')) {
    echo "（dry-run：基础表还没建，招聘迁移需要 --apply 后才能预演）\n";
} else {
    $order = ['create_recruit_tables_20260923', 'add_recruit_lifecycle_20260924', 'create_recruit_companies_20260925', 'add_recruit_apply_links_20260926'];
    foreach ($order as $name) {
        $mig = $root . "/scripts/migrations/{$name}.php";
        echo "\n>>> " . basename($mig) . "\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mig) . ($apply ? ' --apply' : '') . ' 2>&1';
        passthru($cmd, $code);
        if ($code !== 0) { echo "\n❌ " . basename($mig) . " 退出码 {$code}，中止\n"; exit(1); }
    }
}

if ($apply && !empty($args['admin'])) {
    $u = (string)$args['admin'];
    $pw = (string)($args['password'] ?? '');
    echo "\n--- 管理员 ---\n";
    $st = $pdo->prepare("SELECT id FROM users WHERE username=?");
    $st->execute([$u]);
    if ($st->fetchColumn()) {
        echo "[skip] 用户 $u 已存在\n";
    } elseif (strlen($pw) < 8) {
        echo "❌ --password 至少 8 位\n"; exit(1);
    } else {
        $pdo->prepare("INSERT INTO users (username, name, role, status, password_hash) VALUES (?, ?, 'admin', 'active', ?)")
            ->execute([$u, (string)($args['name'] ?? $u), password_hash($pw, PASSWORD_DEFAULT)]);
        echo "[add ] 管理员 $u\n";
    }
}

echo "\n✅ 完成" . ($apply ? '' : '（dry-run，未写库。加 --apply 执行）') . "\n";
