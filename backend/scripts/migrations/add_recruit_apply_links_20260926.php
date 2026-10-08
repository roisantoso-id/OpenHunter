<?php
/**
 * 职位投递链接（2026-09-26「开放一个候选人的外链」→ 选定「职位投递链接」）
 *
 * 建 2 张表：
 *   recruit_job_links         每个招聘专员在每个职位上一条公开链接（token 32 字节随机，不可枚举）；可撤销；记打开数
 *   recruit_job_applications  每次投递一行：候选人自己填的姓名 / WhatsApp / 邮箱、同意时间、IP（限流用）、对应的简历
 * 投递的简历走原有上传通道（recruit_resumes.origin='apply'），解析后自动挂到该职位。逻辑见 includes/recruit_apply.php。
 *
 *   php scripts/data-fixes/add_recruit_apply_links_20260926.php           # dry-run
 *   php scripts/data-fixes/add_recruit_apply_links_20260926.php --apply
 * 幂等：已存在的表 / 索引跳过；唯一索引（token）建在新表上，建表时即为空，不存在重复（§7.9）。
 */
$root = dirname(__DIR__, 2);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$pdo   = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$my    = dbIsMysql();
$tail  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
$pk    = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$str   = fn(int $n) => $my ? "VARCHAR($n)" : 'TEXT';
$dec   = fn(string $p) => $my ? "DECIMAL($p)" : 'REAL';
$txt   = $my ? 'MEDIUMTEXT' : 'TEXT';

echo "=== 职位投递链接 · " . ($apply ? 'APPLY' : 'dry-run') . " ===\n";
$exists = function (string $t) use ($pdo, $my): bool {
    $st = $pdo->prepare($my ? "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"
                            : "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
};
$hasCol = function (string $t, string $c) use ($pdo, $my): bool {
    if ($my) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    }
    foreach ($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC) as $r) if ($r['name'] === $c) return true;
    return false;
};
$hasIdx = function (string $t, string $name) use ($pdo, $my): bool {
    if ($my) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
        $st->execute([$t, $name]);
        return (int)$st->fetchColumn() > 0;
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name=?");
    $st->execute([$name]);
    return (int)$st->fetchColumn() > 0;
};

$tables = [
    'recruit_job_links' => "CREATE TABLE recruit_job_links (
        id $pk,
        job_id INT NOT NULL DEFAULT 0,
        owner_user_id INT NOT NULL DEFAULT 0,
        token {$str(64)} NOT NULL DEFAULT '',
        revoked INT NOT NULL DEFAULT 0,
        views INT NOT NULL DEFAULT 0,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        revoked_at DATETIME NULL
    )$tail",
    'recruit_job_applications' => "CREATE TABLE recruit_job_applications (
        id $pk,
        link_id INT NOT NULL DEFAULT 0,
        job_id INT NOT NULL DEFAULT 0,
        resume_id INT NOT NULL DEFAULT 0,
        name {$str(191)} NOT NULL DEFAULT '',
        phone {$str(64)} NOT NULL DEFAULT '',
        email {$str(191)} NOT NULL DEFAULT '',
        lang {$str(8)} NOT NULL DEFAULT '',
        ip {$str(64)} NOT NULL DEFAULT '',
        user_agent {$str(255)} NOT NULL DEFAULT '',
        consent_at DATETIME NULL,
        created_at DATETIME NULL
    )$tail",
];
$indexes = [
    ['recruit_job_links', 'uk_rjl_token', 'token', true],
    ['recruit_job_links', 'idx_rjl_job', 'job_id', false],
    ['recruit_job_applications', 'idx_rja_link', 'link_id', false],
    ['recruit_job_applications', 'idx_rja_resume', 'resume_id', false],
    ['recruit_job_applications', 'idx_rja_ip', 'ip', false],
];
if (!$exists('recruit_jobs') || !$exists('recruit_resumes')) { echo "⛔ 招聘表不存在（先跑 create_recruit_tables_20260923.php）\n"; exit(1); }
$built = 0; $idxBuilt = 0;
foreach ($tables as $name => $ddl) {
    if ($exists($name)) { echo "[skip] $name 已存在\n"; continue; }
    echo "[add ] $name\n";
    if ($apply) { $pdo->exec($ddl); $built++; }
}
foreach ($indexes as [$t, $iname, $col, $uniq]) {
    if (!$exists($t) || $hasIdx($t, $iname)) continue;
    if ($uniq) {
        $dup = $pdo->query("SELECT $col, COUNT(*) n FROM $t GROUP BY $col HAVING COUNT(*) > 1 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        if ($dup) { echo "⛔ $t.$col 有重复，建不了唯一索引 $iname：" . json_encode($dup) . "\n"; exit(1); }
    }
    echo "[idx ] $iname\n";
    if ($apply) { $pdo->exec("CREATE " . ($uniq ? 'UNIQUE ' : '') . "INDEX $iname ON $t($col)"); $idxBuilt++; }
}
if ($apply) {
    foreach (array_keys($tables) as $t) if (!$exists($t)) { echo "⛔ $t 没建成\n"; exit(1); }
    foreach ($indexes as [$t, $iname]) if (!$hasIdx($t, $iname)) { echo "⛔ 索引 $iname 没建成\n"; exit(1); }
    echo "\n✅ 建表 $built 张 · 索引 $idxBuilt 个\n";
    exit(0);
}
echo "\n（dry-run 未写入。--apply 后重跑应报「建表 0 张 · 索引 0 个」）\n";
