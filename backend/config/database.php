<?php
/**
 * 数据库连接（SQLite 默认，MySQL 8 可选）与环境变量加载。
 *
 * 环境变量（可写在 backend/.env，已存在的环境变量优先，不被 .env 覆盖）：
 *   OPENHUNTER_DB_DRIVER   sqlite（默认）| mysql
 *   OPENHUNTER_DB_PATH     SQLite 基础路径，默认 backend/data/openhunter.db（每个租户一个 openhunter-<租户>.db）
 *   OPENHUNTER_TENANT      CLI 脚本操作哪个租户，默认 default（Web 请求由登录 token 决定）
 *   OPENHUNTER_DB_HOST / _PORT / _NAME / _USER / _PASS（或 _PASS_FILE 指向只含密码的文件）
 *   OPENHUNTER_TIMEZONE    业务时区，默认 Asia/Jakarta
 *   OPENHUNTER_UTC_OFFSET  业务时区相对 UTC 的小时数，默认 7（SQLite 的 datetime('now', '+N hours') 用它）
 */

(function () {
    $envFile = __DIR__ . '/../.env';
    if (!is_readable($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        if ($k === '' || getenv($k) !== false) continue;
        $v = trim(substr($line, $eq + 1));
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        if (function_exists('putenv')) @putenv("$k=$v");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
})();

/** 读环境变量：getenv → $_ENV → $_SERVER（putenv 被禁用的主机上 .env 只进得了后两者） */
function ohEnv(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v !== false && $v !== '') return (string)$v;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    return $default;
}

/** 业务时区相对 UTC 的小时数（整数，可为负） */
function ohUtcOffsetHours(): int {
    return (int)ohEnv('OPENHUNTER_UTC_OFFSET', '7');
}

@date_default_timezone_set(ohEnv('OPENHUNTER_TIMEZONE', 'Asia/Jakarta'));

/**
 * 多租户：每个租户一个独立数据库（SQLite 一个文件 / MySQL 一个 schema），业务代码里的 SQL 完全不用带 tenant_id，
 * 隔离靠「连的是谁的库」。租户注册表（tenants）和平台账号放在控制库里。
 *
 *   SQLite：OPENHUNTER_DB_PATH=/x/openhunter.db → 租户库 /x/openhunter-<slug>.db，控制库 /x/openhunter-control.db
 *   MySQL ：OPENHUNTER_DB_NAME=openhunter       → 租户库 openhunter_<slug>，控制表建在 openhunter 里
 *
 * 当前租户：Web 请求由登录 token（tn）决定；CLI 由环境变量 OPENHUNTER_TENANT 决定（默认 default）。
 */
class Tenant {
    private static $slug = null;

    public static function valid(string $slug): bool {
        return $slug !== 'control' && (bool)preg_match('/^[a-z0-9][a-z0-9_]{1,30}$/', $slug);
    }

    public static function set(string $slug): void {
        if (!self::valid($slug)) throw new RuntimeException('租户标识不合法（小写字母 / 数字 / 下划线，2-31 位）');
        self::$slug = $slug;
        if (function_exists('putenv')) @putenv('OPENHUNTER_TENANT=' . $slug); // 子进程（worker）继承
    }

    public static function slug(): string {
        if (self::$slug === null) {
            $s = strtolower(trim(ohEnv('OPENHUNTER_TENANT', 'default')));
            if (!self::valid($s)) throw new RuntimeException('OPENHUNTER_TENANT 不合法: ' . $s);
            self::$slug = $s;
        }
        return self::$slug;
    }

    /** 注册表里的活跃租户；没有返回 null */
    public static function find(string $slug): ?array {
        if (!self::valid($slug)) return null;
        $st = Database::control()->prepare("SELECT id, slug, name, status FROM tenants WHERE slug = ? AND status = 'active'");
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    }

    public static function all(): array {
        return Database::control()->query("SELECT id, slug, name, status, created_at FROM tenants ORDER BY id")->fetchAll();
    }

    /** 只有一个活跃租户时登录可以省略租户字段 */
    public static function onlyOne(): ?string {
        $r = Database::control()->query("SELECT slug FROM tenants WHERE status='active' LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        return count($r) === 1 ? (string)$r[0] : null;
    }

    /** 注册租户（幂等），库本身首次连接时自动创建；建表由 install.php 负责 */
    public static function register(string $slug, string $name): void {
        if (!self::valid($slug)) throw new RuntimeException('租户标识不合法（小写字母 / 数字 / 下划线，2-31 位）');
        $c = Database::control();
        $st = $c->prepare("SELECT id FROM tenants WHERE slug = ?");
        $st->execute([$slug]);
        if ($st->fetchColumn()) return;
        $c->prepare("INSERT INTO tenants (slug, name, status) VALUES (?, ?, 'active')")->execute([$slug, $name !== '' ? $name : $slug]);
    }
}

class Database {
    private static $instances = [];
    private static $control = null;
    private $pdo;
    private $driver = 'sqlite';

    private function __construct(string $slug) {
        $this->driver = self::driverName();
        $this->pdo = $this->driver === 'mysql' ? self::connectMysql(self::mysqlTenantDb($slug), $slug !== '') : self::connectSqlite(self::sqlitePath($slug));
    }

    private static function driverName(): string {
        return strtolower(trim(ohEnv('OPENHUNTER_DB_DRIVER'))) === 'mysql' ? 'mysql' : 'sqlite';
    }

    private static function sqlitePath(string $slug): string {
        $base = ohEnv('OPENHUNTER_DB_PATH', __DIR__ . '/../data/openhunter.db');
        $dir = dirname($base);
        $stem = preg_replace('/\.(db|sqlite3?)$/i', '', basename($base));
        return $dir . '/' . $stem . '-' . $slug . '.db';
    }

    private static function mysqlTenantDb(string $slug): string {
        return ohEnv('OPENHUNTER_DB_NAME') . '_' . $slug;
    }

    private static function connectSqlite(string $path): PDO {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        return $pdo;
    }

    private static function connectMysql(string $dbName, bool $create = false): PDO {
        $host = ohEnv('OPENHUNTER_DB_HOST');
        $port = (int)ohEnv('OPENHUNTER_DB_PORT', '3306');
        $user = ohEnv('OPENHUNTER_DB_USER');
        $pass = ohEnv('OPENHUNTER_DB_PASS');
        $passFile = ohEnv('OPENHUNTER_DB_PASS_FILE');
        if ($passFile !== '' && is_readable($passFile)) $pass = trim((string)file_get_contents($passFile));
        foreach (['HOST' => $host, 'NAME' => ohEnv('OPENHUNTER_DB_NAME'), 'USER' => $user] as $k => $v) {
            if ($v === '') throw new RuntimeException("OPENHUNTER_DB_DRIVER=mysql 但 OPENHUNTER_DB_{$k} 没配");
        }
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($create && ohEnv('OPENHUNTER_DB_AUTOCREATE', '1') === '1') {
            // 开新租户时库还不存在：先不指定库连上去建（需要 CREATE 权限；不想给就设 OPENHUNTER_DB_AUTOCREATE=0 自己建）
            $root = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $opts);
            if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) throw new RuntimeException('库名不合法');
            $root->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, $opts);
        $off = ohUtcOffsetHours();
        $tz = sprintf('%s%02d:00', $off < 0 ? '-' : '+', abs($off));
        // 时区与 SQLite 侧对齐；STRICT 防止超长字符串被静默截断
        $pdo->exec("SET time_zone = '{$tz}', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    public function getDriver(): string { return $this->driver; }

    /** 当前租户的库 */
    public static function getInstance() {
        $slug = Tenant::slug();
        if (!isset(self::$instances[$slug])) {
            if ($slug !== 'default' || !self::isFreshInstall()) {
                if (!Tenant::find($slug)) throw new RuntimeException("租户 {$slug} 不存在或已停用");
            }
            self::$instances[$slug] = new self($slug);
        }
        return self::$instances[$slug];
    }

    /** 全新安装时 tenants 表还没有 default：放行，由 install.php 注册 */
    private static function isFreshInstall(): bool {
        return (int)self::control()->query("SELECT COUNT(*) FROM tenants")->fetchColumn() === 0;
    }

    /** 控制库：tenants 表（租户注册）。表缺失时自动建（幂等） */
    public static function control(): PDO {
        if (self::$control === null) {
            $my = self::driverName() === 'mysql';
            self::$control = $my ? self::connectMysql(ohEnv('OPENHUNTER_DB_NAME')) : self::connectSqlite(self::sqlitePath('control'));
            $pk = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
            $str = fn(int $n) => $my ? "VARCHAR($n)" : 'TEXT';
            self::$control->exec("CREATE TABLE IF NOT EXISTS tenants (
                id $pk,
                slug {$str(32)} NOT NULL UNIQUE,
                name {$str(191)} NOT NULL DEFAULT '',
                status {$str(16)} NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )" . ($my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
        }
        return self::$control;
    }

    public function getConnection() { return $this->pdo; }

    /** 兼容旧调用：建表由 scripts/install.php 负责，这里不做任何 DDL */
    public function initialize() {}
}

require_once __DIR__ . '/../includes/db_dialect.php';
