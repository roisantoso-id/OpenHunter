<?php
/**
 * 企业库 / 标杆企业（2026-09-25「以公司为主体挖人：候选人按工作经历挂到公司下；新公司自动归类、补全官网与组织架构；分印尼本地与海外」）
 *
 * 建 6 张表 + 1 列：
 *   recruit_segments            赛道（美妆、快消…，管理员维护，三语名）
 *   recruit_companies           企业（自动建档 / 手动新建；名次与梯队人工定；官网等由补全任务填）
 *   recruit_company_aliases     别名 → 企业（alias_key 唯一：一个归一名只属于一家）
 *   recruit_candidate_companies 候选人每段经历挂到哪家企业（按人整体重建）
 *   recruit_title_functions     职位名 → 职能 / 职级（AI 归一次，人工可改）
 *   recruit_company_ignored     不建档的名字（自由职业、学校…）
 *   recruit_candidates.company_rev / company_prof_rev  挂靠做到哪个企业库版本 / 档案版本
 * 逻辑见 includes/recruit_company.php。
 *
 *   php scripts/data-fixes/create_recruit_companies_20260925.php           # dry-run
 *   php scripts/data-fixes/create_recruit_companies_20260925.php --apply
 * 幂等：已存在的表 / 列 / 索引跳过；唯一索引建之前先查重复（§7.9），有重复就中止并打印。
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

echo "=== 企业库 / 标杆企业 · " . ($apply ? 'APPLY' : 'dry-run') . " ===\n";
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
    'recruit_segments' => "CREATE TABLE recruit_segments (
        id $pk,
        name_zh {$str(64)} NOT NULL DEFAULT '',
        name_en {$str(64)} NOT NULL DEFAULT '',
        name_id {$str(64)} NOT NULL DEFAULT '',
        industry {$str(40)} NOT NULL DEFAULT '',
        description TEXT,
        source {$str(8)} NOT NULL DEFAULT 'manual',
        sort INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1,
        created_at DATETIME NULL
    )$tail",
    'recruit_companies' => "CREATE TABLE recruit_companies (
        id $pk,
        name {$str(191)} NOT NULL DEFAULT '',
        org_key {$str(191)} NOT NULL DEFAULT '',
        region {$str(12)} NOT NULL DEFAULT '',
        country {$str(4)} NOT NULL DEFAULT '',
        hq_city {$str(100)} NOT NULL DEFAULT '',
        website {$str(255)} NOT NULL DEFAULT '',
        linkedin_url {$str(255)} NOT NULL DEFAULT '',
        industry {$str(40)} NOT NULL DEFAULT '',
        segment_id INT NOT NULL DEFAULT 0,
        rank_in_segment INT NULL,
        tier {$str(12)} NOT NULL DEFAULT '',
        employee_range {$str(40)} NOT NULL DEFAULT '',
        founded_year INT NULL,
        parent_group {$str(191)} NOT NULL DEFAULT '',
        id_entities $txt,
        summary_json $txt,
        org_json $txt,
        sources_json $txt,
        suggest_segment {$str(64)} NOT NULL DEFAULT '',
        class_source {$str(8)} NOT NULL DEFAULT '',
        class_conf {$str(8)} NOT NULL DEFAULT '',
        classified_at DATETIME NULL,
        enrich_status {$str(12)} NOT NULL DEFAULT 'none',
        enrich_priority INT NOT NULL DEFAULT 0,
        enrich_error {$str(255)} NOT NULL DEFAULT '',
        enrich_attempts INT NOT NULL DEFAULT 0,
        enriched_at DATETIME NULL,
        enrich_meta $txt,
        locked_fields $txt,
        source {$str(8)} NOT NULL DEFAULT 'auto',
        notes $txt,
        active INT NOT NULL DEFAULT 1,
        merged_into INT NOT NULL DEFAULT 0,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",
    'recruit_company_aliases' => "CREATE TABLE recruit_company_aliases (
        id $pk,
        company_id INT NOT NULL,
        alias {$str(191)} NOT NULL DEFAULT '',
        alias_key {$str(191)} NOT NULL DEFAULT '',
        created_at DATETIME NULL
    )$tail",
    'recruit_candidate_companies' => "CREATE TABLE recruit_candidate_companies (
        id $pk,
        candidate_id INT NOT NULL,
        company_id INT NOT NULL,
        exp_index INT NOT NULL DEFAULT 0,
        title {$str(191)} NOT NULL DEFAULT '',
        title_key {$str(191)} NOT NULL DEFAULT '',
        start_ym {$str(7)} NOT NULL DEFAULT '',
        end_ym {$str(7)} NOT NULL DEFAULT '',
        is_current INT NOT NULL DEFAULT 0,
        months INT NOT NULL DEFAULT 0,
        company_industry {$str(40)} NOT NULL DEFAULT '',
        location {$str(100)} NOT NULL DEFAULT ''
    )$tail",
    'recruit_title_functions' => "CREATE TABLE recruit_title_functions (
        id $pk,
        title_key {$str(191)} NOT NULL DEFAULT '',
        title_sample {$str(191)} NOT NULL DEFAULT '',
        job_function {$str(24)} NOT NULL DEFAULT '',
        seniority {$str(12)} NOT NULL DEFAULT '',
        source {$str(8)} NOT NULL DEFAULT '',
        prompt_ver {$str(8)} NOT NULL DEFAULT '',
        attempts INT NOT NULL DEFAULT 0,
        updated_by INT NOT NULL DEFAULT 0,
        updated_at DATETIME NULL
    )$tail",
    // 关系网络（2026-09-26「候选人、行业、企业、学校之间的关系」）：学校也像公司一样归一挂靠，校友 / 同门从这里查
    'recruit_candidate_schools' => "CREATE TABLE recruit_candidate_schools (
        id $pk,
        candidate_id INT NOT NULL,
        school_key {$str(191)} NOT NULL DEFAULT '',
        school {$str(191)} NOT NULL DEFAULT '',
        degree {$str(16)} NOT NULL DEFAULT '',
        major {$str(191)} NOT NULL DEFAULT '',
        start_ym {$str(7)} NOT NULL DEFAULT '',
        end_ym {$str(7)} NOT NULL DEFAULT ''
    )$tail",
    'recruit_company_ignored' => "CREATE TABLE recruit_company_ignored (
        id $pk,
        org_key {$str(191)} NOT NULL DEFAULT '',
        sample {$str(191)} NOT NULL DEFAULT '',
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL
    )$tail",
];
// [表, 索引名, 列, 唯一?]
$indexes = [
    ['recruit_companies', 'idx_rco_key', 'org_key', false],
    ['recruit_companies', 'idx_rco_seg', 'segment_id', false],
    ['recruit_companies', 'idx_rco_enrich', 'enrich_status', false],
    ['recruit_company_aliases', 'uk_rca_key', 'alias_key', true],
    ['recruit_company_aliases', 'idx_rca_co', 'company_id', false],
    ['recruit_candidate_companies', 'idx_rcc_cand', 'candidate_id', false],
    ['recruit_candidate_companies', 'idx_rcc_co', 'company_id', false],
    ['recruit_candidate_companies', 'idx_rcc_title', 'title_key', false],
    ['recruit_title_functions', 'uk_rtf_key', 'title_key', true],
    ['recruit_company_ignored', 'uk_rci_key', 'org_key', true],
    ['recruit_candidate_schools', 'idx_rcs_cand', 'candidate_id', false],
    ['recruit_candidate_schools', 'idx_rcs_key', 'school_key', false],
];

$built = 0; $idxBuilt = 0; $cols = 0;
// 09-25 版已跑过的库：挂靠表早就有数据，新加的学校表 / 简历行业地点列要所有人重挂一遍才有值（结果态见下方 [ver ]）
$hadLinks = $exists('recruit_candidate_companies') && (int)$pdo->query("SELECT COUNT(*) FROM recruit_candidate_companies")->fetchColumn() > 0;
// 结果态判断（§7.2）：挂过人、学校表还空、而且当前没有待重挂的 → 需要把企业库版本 +1；已经 +1 过（有人待重挂）或学校已有数据就不再动
$verNow = max(1, (int)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='recruit.company.ver'")->fetchColumn());
$pending = $hadLinks && $hasCol('recruit_candidates', 'company_rev')
    ? (int)$pdo->query("SELECT COUNT(*) FROM recruit_candidates WHERE profile_rev>0 AND company_rev<>$verNow")->fetchColumn() : 0;
$schoolsEmpty = !$exists('recruit_candidate_schools') || (int)$pdo->query("SELECT COUNT(*) FROM recruit_candidate_schools")->fetchColumn() === 0;
$needRelink = $hadLinks && $schoolsEmpty && $pending === 0;
foreach ($tables as $name => $ddl) {
    if ($exists($name)) { echo "[skip] $name 已存在\n"; continue; }
    echo "[add ] $name\n";
    if ($apply) { $pdo->exec($ddl); $built++; }
}
foreach ($indexes as [$t, $iname, $col, $uniq]) {
    if (!$exists($t) || $hasIdx($t, $iname)) continue;
    if ($uniq) {   // §7.9：唯一约束建之前查冲突，不靠 try/catch 吞
        $dup = $pdo->query("SELECT $col, COUNT(*) n FROM $t GROUP BY $col HAVING COUNT(*) > 1 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        if ($dup) { echo "⛔ $t.$col 有重复，建不了唯一索引 $iname：" . json_encode($dup, JSON_UNESCAPED_UNICODE) . "\n"; exit(1); }
    }
    echo "[idx ] $iname\n";
    if ($apply) { $pdo->exec("CREATE " . ($uniq ? 'UNIQUE ' : '') . "INDEX $iname ON $t($col)"); $idxBuilt++; }
}
if (!$exists('recruit_candidates')) { echo "⛔ recruit_candidates 不存在（先跑 create_recruit_tables_20260923.php）\n"; exit(1); }
// 2026-09-26 追加：归类来源（resume 简历推断 / ai 快速归类 / web 联网检索 / manual 人工）；已建表的库补列
foreach ([['recruit_companies', 'class_source', "{$str(8)} NOT NULL DEFAULT ''"], ['recruit_companies', 'class_conf', "{$str(8)} NOT NULL DEFAULT ''"],
          ['recruit_companies', 'classified_at', 'DATETIME NULL'],
          // 简历里这段经历写的行业、地点：不联网就能先把公司归个大类、判本地 / 海外
          ['recruit_candidate_companies', 'company_industry', "{$str(40)} NOT NULL DEFAULT ''"], ['recruit_candidate_companies', 'location', "{$str(100)} NOT NULL DEFAULT ''"],
          // 赛道：AI 生成的（每日任务 / 对话生成）与人工建的区分开；description 给 AI 与人看这个赛道指什么
          ['recruit_segments', 'source', "{$str(8)} NOT NULL DEFAULT 'manual'"], ['recruit_segments', 'description', 'TEXT']] as [$t, $c, $def]) {
    if (!$exists($t) || $hasCol($t, $c)) continue;
    echo "[col ] $t.$c\n";
    if ($apply) { try { $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def"); $cols++; } catch (PDOException $e) {} }
}
// company_rev = 挂靠时的企业库版本（recruit.company.ver）；company_prof_rev = 挂靠时的档案版本（profile_rev）。任一落后就重挂
foreach (['company_rev', 'company_prof_rev'] as $c) {
    if ($hasCol('recruit_candidates', $c)) continue;
    echo "[col ] recruit_candidates.$c\n";
    if ($apply) { try { $pdo->exec("ALTER TABLE recruit_candidates ADD COLUMN $c INT NOT NULL DEFAULT 0"); $cols++; } catch (PDOException $e) {} }
}

// 企业库查看名单：没配过时默认所有系统管理员能看（includes/recruit_company_acl.php），之后在企业库「查看权限」页签里维护，这里不写死人。

// 企业库版本 +1：定时任务 ⑧ 把已挂过的人重挂，补上学校与简历里的行业 / 地点（否则简历推断归类没票可投）
if ($needRelink) {
    $ver = $verNow;
    echo "[ver ] recruit.company.ver $ver → " . ($ver + 1) . "（已挂靠的候选人全部重挂一遍）\n";
    if ($apply) {
        $up = $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='recruit.company.ver'");
        $up->execute([(string)($ver + 1)]);
        if ($up->rowCount() === 0) $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('recruit.company.ver', ?)")->execute([(string)($ver + 1)]);
    }
}
// 加归类来源列之前已联网补全过的公司：标成 web，免得简历推断 / AI 快速归类把核实过的行业覆盖掉
if ($exists('recruit_companies') && $hasCol('recruit_companies', 'class_source')) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM recruit_companies WHERE enrich_status='done' AND class_source=''")->fetchColumn();
    if ($n) {
        echo "[web ] 已联网补全、未标来源的公司 $n 家 → class_source='web'\n";
        if ($apply) $pdo->exec("UPDATE recruit_companies SET class_source='web', class_conf='high' WHERE enrich_status='done' AND class_source=''");
    }
} elseif ($hadLinks) echo "[web ] 加列后回填已联网补全公司的来源（--apply 时执行）\n";
if ($apply && $exists('recruit_companies') && $hasCol('recruit_companies', 'class_source')) {
    $left = (int)$pdo->query("SELECT COUNT(*) FROM recruit_companies WHERE enrich_status='done' AND class_source=''")->fetchColumn();
    if ($left) { echo "⛔ 还有 $left 家已补全公司未标来源\n"; exit(1); }
}

if ($apply) {
    foreach (array_keys($tables) as $t) if (!$exists($t)) { echo "⛔ $t 没建成\n"; exit(1); }
    foreach ($indexes as [$t, $iname]) if (!$hasIdx($t, $iname)) { echo "⛔ 索引 $iname 没建成\n"; exit(1); }
    foreach ([['recruit_candidates', 'company_rev'], ['recruit_candidates', 'company_prof_rev'], ['recruit_companies', 'class_source'], ['recruit_companies', 'class_conf'],
              ['recruit_companies', 'classified_at'], ['recruit_candidate_companies', 'company_industry'], ['recruit_candidate_companies', 'location'],
              ['recruit_segments', 'source'], ['recruit_segments', 'description']] as [$t, $c]) {
        if (!$hasCol($t, $c)) { echo "⛔ $t.$c 没加上\n"; exit(1); }
    }
    $n = (int)$pdo->query("SELECT COUNT(*) FROM recruit_candidates WHERE profile_rev>0 AND (company_rev=0 OR company_prof_rev<>profile_rev)")->fetchColumn();
    echo "\n✅ 建表 $built 张 · 索引 $idxBuilt 个 · 加列 $cols 列。待挂靠候选人 $n 人（定时任务 ⑧ 企业库 自动处理，每轮 500 人）\n";
    exit(0);
}
echo "\n（dry-run 未写入。重跑 --apply 后应报「建表 0 张 · 索引 0 个 · 加列 0 列」）\n";
