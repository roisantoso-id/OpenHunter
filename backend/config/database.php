<?php
/**
 * 数据库连接（SQLite 默认，MySQL 8 可选）与环境变量加载。
 *
 * 环境变量（可写在 backend/.env，已存在的环境变量优先，不被 .env 覆盖）：
 *   OPENHUNTER_DB_DRIVER   sqlite（默认）| mysql
 *   OPENHUNTER_DB_PATH     SQLite 文件路径，默认 backend/data/openhunter.db
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

class Database {
    private static $instance = null;
    private $pdo;
    private $driver = 'sqlite';

    private function __construct() {
        $this->driver = strtolower(trim(ohEnv('OPENHUNTER_DB_DRIVER'))) === 'mysql' ? 'mysql' : 'sqlite';
        if ($this->driver === 'mysql') {
            $this->connectMysql();
            return;
        }
        $path = ohEnv('OPENHUNTER_DB_PATH', __DIR__ . '/../data/openhunter.db');
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo->exec('PRAGMA busy_timeout = 10000');
    }

    private function connectMysql(): void {
        $host = ohEnv('OPENHUNTER_DB_HOST');
        $port = (int)ohEnv('OPENHUNTER_DB_PORT', '3306');
        $name = ohEnv('OPENHUNTER_DB_NAME');
        $user = ohEnv('OPENHUNTER_DB_USER');
        $pass = ohEnv('OPENHUNTER_DB_PASS');
        $passFile = ohEnv('OPENHUNTER_DB_PASS_FILE');
        if ($passFile !== '' && is_readable($passFile)) $pass = trim((string)file_get_contents($passFile));
        foreach (['HOST' => $host, 'NAME' => $name, 'USER' => $user] as $k => $v) {
            if ($v === '') throw new RuntimeException("OPENHUNTER_DB_DRIVER=mysql 但 OPENHUNTER_DB_{$k} 没配");
        }
        $this->pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $off = ohUtcOffsetHours();
        $tz = sprintf('%s%02d:00', $off < 0 ? '-' : '+', abs($off));
        // 时区与 SQLite 侧对齐；STRICT 防止超长字符串被静默截断
        $this->pdo->exec("SET time_zone = '{$tz}', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    }

    public function getDriver(): string { return $this->driver; }

    public static function getInstance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function getConnection() { return $this->pdo; }

    /** 兼容旧调用：建表由 scripts/install.php 负责，这里不做任何 DDL */
    public function initialize() {}
}

require_once __DIR__ . '/../includes/db_dialect.php';
