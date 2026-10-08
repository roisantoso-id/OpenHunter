<?php
/**
 * 招聘项目全生命周期 P1（2026-09-24「记录候选人是否入职、是否过保证期、回款；合同上传；项目情况一眼清楚」）
 *
 * 加什么：
 *   recruit_projects   委托条款：service_type / fee_* / guarantee_* / billing_terms / 合同状态与日期 / PT 主体
 *   recruit_placements 新表：一次入职一行（Offer 接受 → 入职 → 过保 | 保证期内离职 → 补人 / 退款 / 关闭）
 *   recruit_documents  新表：多态文档（项目 / 入职记录 / 候选人），客户协议同时镜像到 opportunity_files
 *   recruit_followups  kind 放宽到 16 字符 + detail_json / reason_code / scheduled_at / updated_at（面试 / Offer / 淘汰结构化）
 *   recruit_candidates 现状字段：现薪 / 期望薪 / 通知期 / 在职 / 意向 / 可到岗
 * 回填（--apply 才写）：老项目补默认条款（contingency · 12 个月薪资基数 · 90 天 · 免费补人 1 次，币种取商机币种）；
 *   stage='hired' 且还没有 placement 的候选人×职位 → 一条 status='started' 的 placement（入职日 = 阶段变更日，费用留空）。
 *
 *   php scripts/data-fixes/add_recruit_lifecycle_20260924.php           # dry-run
 *   php scripts/data-fixes/add_recruit_lifecycle_20260924.php --apply
 * 幂等：加列 / 建表 / 索引「已存在」跳过；回填按结果态判（已有默认条款、已有 placement 的不再动）；重跑报「0 / 0」。
 * 逻辑常量（状态、里程碑默认）在 includes/handlers/recruit_lifecycle.php，这里只建结构 + 回填。
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
$txt   = 'TEXT';

echo "=== 招聘全生命周期 P1 结构 · " . ($apply ? 'APPLY' : 'dry-run') . " ===\n";

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

// ---- 1. 新表（建表 / 索引失败不吞异常，§7.9）----
$tables = [
    'recruit_placements' => "CREATE TABLE recruit_placements (
        id $pk,
        project_id INT NOT NULL,
        job_id INT NOT NULL,
        candidate_id INT NOT NULL,
        candidate_job_id INT NOT NULL,
        replacement_of_placement_id INT NOT NULL DEFAULT 0,
        replacement_seq INT NOT NULL DEFAULT 0,
        status {$str(20)} NOT NULL DEFAULT 'offer_accepted',
        offer_date DATE NULL,
        offer_accepted_at DATE NULL,
        expected_start_date DATE NULL,
        actual_start_date DATE NULL,
        offer_salary_monthly {$dec('15,2')} NULL,
        offer_salary_annual {$dec('15,2')} NULL,
        currency {$str(8)} NOT NULL DEFAULT 'IDR',
        allowances_text {$str(255)} NOT NULL DEFAULT '',
        guarantee_days INT NOT NULL DEFAULT 0,
        guarantee_end_date DATE NULL,
        guarantee_remedy {$str(16)} NOT NULL DEFAULT 'replacement',
        left_at DATE NULL,
        left_reason_code {$str(32)} NOT NULL DEFAULT '',
        left_note $txt,
        remedy_outcome {$str(16)} NOT NULL DEFAULT '',
        expected_refund_amount {$dec('15,2')} NULL,
        fee_amount {$dec('15,2')} NULL,
        fee_note {$str(255)} NOT NULL DEFAULT '',
        quotation_item_id INT NOT NULL DEFAULT 0,
        delivery_task_id INT NOT NULL DEFAULT 0,
        billing_json $txt,
        refund_payment_id INT NOT NULL DEFAULT 0,
        notes $txt,
        status_changed_at DATETIME NULL,
        status_changed_by INT NOT NULL DEFAULT 0,
        created_by INT NOT NULL DEFAULT 0,
        created_by_name {$str(191)} NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        service_type {$str(16)} NOT NULL DEFAULT '',
        replacement_quota INT NOT NULL DEFAULT 0
    )$tail",
    'recruit_documents' => "CREATE TABLE recruit_documents (
        id $pk,
        entity_type {$str(12)} NOT NULL DEFAULT '',
        entity_id INT NOT NULL,
        project_id INT NOT NULL DEFAULT 0,
        doc_type {$str(24)} NOT NULL DEFAULT 'other',
        title {$str(191)} NOT NULL DEFAULT '',
        file_name {$str(255)} NOT NULL DEFAULT '',
        file_path {$str(500)} NOT NULL DEFAULT '',
        file_ext {$str(8)} NOT NULL DEFAULT '',
        file_size INT NOT NULL DEFAULT 0,
        signed_at DATE NULL,
        valid_until DATE NULL,
        opportunity_file_id INT NOT NULL DEFAULT 0,
        notes $txt,
        uploaded_by INT NOT NULL DEFAULT 0,
        uploaded_by_name {$str(191)} NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        deleted_at DATETIME NULL
    )$tail",
];
$indexes = [
    'recruit_placements' => ['idx_rpl_project' => 'recruit_placements(project_id)', 'idx_rpl_cand' => 'recruit_placements(candidate_id)',
                             'idx_rpl_link' => 'recruit_placements(candidate_job_id)', 'idx_rpl_status' => 'recruit_placements(status)'],
    'recruit_documents'  => ['idx_rdoc_entity' => 'recruit_documents(entity_type, entity_id)', 'idx_rdoc_project' => 'recruit_documents(project_id)'],
];
$built = 0;
foreach ($tables as $name => $ddl) {
    if ($exists($name)) { echo "[skip] $name 已存在\n"; }
    else { echo "[add ] $name\n"; if ($apply) { $pdo->exec($ddl); $built++; } }
    foreach ($indexes[$name] as $iname => $spec) {
        if ($exists($name) || $apply) {
            if ($hasIdx($name, $iname)) continue;
            echo "[idx ] $iname\n";
            if ($apply) $pdo->exec("CREATE INDEX $iname ON $spec");
        }
    }
}

// ---- 2. 加列（ADD COLUMN 失败只可能是「已存在」，可 try/catch）----
$addCols = [
    'recruit_projects' => [
        'service_type' => "{$str(16)} NOT NULL DEFAULT 'contingency'", 'fee_type' => "{$str(20)} NOT NULL DEFAULT 'percent_of_salary'",
        'fee_percent' => "{$dec('5,2')} NULL", 'fee_basis_months' => 'INT NOT NULL DEFAULT 12',
        'flat_fee' => "{$dec('15,2')} NULL", 'monthly_fee' => "{$dec('15,2')} NULL", 'currency' => "{$str(8)} NOT NULL DEFAULT 'IDR'",
        'guarantee_days' => 'INT NOT NULL DEFAULT 90', 'guarantee_remedy' => "{$str(16)} NOT NULL DEFAULT 'replacement'", 'replacement_count' => 'INT NOT NULL DEFAULT 1',
        'billing_terms' => 'TEXT', 'deposit_amount' => "{$dec('15,2')} NULL",
        'contract_status' => "{$str(16)} NOT NULL DEFAULT 'draft'", 'contract_signed_at' => 'DATE NULL', 'contract_start' => 'DATE NULL', 'contract_end' => 'DATE NULL',
        'client_entity_id' => 'INT NOT NULL DEFAULT 0', 'terms_note' => 'TEXT', 'terms_set_at' => 'DATETIME NULL',
        'service_types' => "{$str(128)} NOT NULL DEFAULT ''",   // 项目的合作模式（多选，逗号分隔；混合项目：猎头 + EOR + RPO）
    ],
    // 职位条款（2026-09-25「一个项目可能是混合的」）：service_type='' = 沿用项目默认条款，否则整组用职位自己的
    'recruit_jobs' => [
        'service_type' => "{$str(16)} NOT NULL DEFAULT ''", 'fee_type' => "{$str(20)} NOT NULL DEFAULT ''",
        'fee_percent' => "{$dec('5,2')} NULL", 'fee_basis_months' => 'INT NULL', 'flat_fee' => "{$dec('15,2')} NULL", 'monthly_fee' => "{$dec('15,2')} NULL",
        'guarantee_days' => 'INT NULL', 'guarantee_remedy' => "{$str(16)} NOT NULL DEFAULT ''", 'replacement_count' => 'INT NULL', 'billing_terms' => 'TEXT',
    ],
    // 入职记录快照：按哪个模式算的、补人额度多少（事后改条款不影响已登记的人）
    'recruit_placements' => ['service_type' => "{$str(16)} NOT NULL DEFAULT ''", 'replacement_quota' => 'INT NOT NULL DEFAULT 0'],
    'recruit_followups' => ['detail_json' => 'TEXT', 'reason_code' => "{$str(32)} NOT NULL DEFAULT ''", 'scheduled_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL'],
    'recruit_candidates' => [
        'current_salary' => "{$dec('15,2')} NULL", 'expected_salary_amt' => "{$dec('15,2')} NULL", 'salary_currency' => "{$str(8)} NOT NULL DEFAULT ''",
        'notice_period_days' => 'INT NULL', 'is_employed' => 'INT NULL', 'intent' => "{$str(8)} NOT NULL DEFAULT ''",
        'availability_date' => 'DATE NULL', 'status_fields_at' => 'DATETIME NULL',
    ],
];
$colsAdded = 0;
foreach ($addCols as $t => $cols) {
    if (!$exists($t)) {
        if ($t === 'recruit_placements') { echo "[col ] $t：随建表一起加\n"; continue; }   // dry-run 时本脚本要建的表还没建
        echo "⛔ 表 $t 不存在（先跑 create_recruit_tables_20260923.php）\n"; exit(1);
    }
    foreach ($cols as $c => $def) {
        if ($hasCol($t, $c)) continue;
        echo "[col ] $t.$c\n";
        if ($apply) { try { $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def"); $colsAdded++; } catch (PDOException $e) {} }
    }
}
// kind 放宽：MySQL 原 VARCHAR(8) 装不下 'interview'；SQLite TEXT 本无上限
if ($my) {
    $st = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recruit_followups' AND COLUMN_NAME='kind'");
    $len = (int)$st->fetchColumn();
    if ($len > 0 && $len < 16) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM recruit_followups")->fetchColumn();
        echo "[mod ] recruit_followups.kind VARCHAR($len) → VARCHAR(16)（{$n} 行" . ($n > 100000 ? '，量大请低峰执行' : '') . "）\n";
        if ($apply) $pdo->exec("ALTER TABLE recruit_followups MODIFY kind VARCHAR(16) NOT NULL DEFAULT 'note'");
    }
}

// ---- 3. 回填 ----
echo "\n--- 回填 ---\n";
if (!$apply && !$exists('recruit_placements')) {
    echo "（dry-run：表还没建，回填清单以 hired 记录估算）\n";
}
// 3a. 老项目默认条款：terms_set_at 为空的都补（币种取商机币种）
$projRows = $pdo->query("SELECT p.id, p.name, p.kind, '' opp_ccy FROM recruit_projects p"
    . ($hasCol('recruit_projects', 'terms_set_at') ? " WHERE p.terms_set_at IS NULL" : ''))->fetchAll(PDO::FETCH_ASSOC);
echo "待补默认条款的项目：" . count($projRows) . " 个\n";
foreach ($projRows as $p) echo "  #{$p['id']} {$p['name']}（{$p['kind']}，币种 " . ($p['opp_ccy'] ?: 'IDR') . "）\n";
if ($apply) {
    $up = $pdo->prepare("UPDATE recruit_projects SET service_type='contingency', fee_type='percent_of_salary', fee_basis_months=12, currency=?,
                              guarantee_days=90, guarantee_remedy='replacement', replacement_count=1, billing_terms=?, contract_status='draft',
                              terms_set_at=(" . dbNow() . ") WHERE id=? AND terms_set_at IS NULL");
    foreach ($projRows as $p) $up->execute([recruitCcy($p['opp_ccy'] ?: 'IDR'), json_encode(recruitDefaultBillingTerms('contingency')), (int)$p['id']]);
}
// 3a'. 合作模式标签（多选）：老项目 = 它的默认模式；老入职记录补模式与补人额度快照
if ($hasCol('recruit_projects', 'service_types')) {
    $n1 = (int)$pdo->query("SELECT COUNT(*) FROM recruit_projects WHERE COALESCE(service_types,'')='' AND COALESCE(service_type,'')<>''")->fetchColumn();
    echo "待补合作模式标签的项目：$n1 个\n";
    if ($apply) $pdo->exec("UPDATE recruit_projects SET service_types=service_type WHERE COALESCE(service_types,'')='' AND COALESCE(service_type,'')<>''");
}
if ($hasCol('recruit_placements', 'service_type')) {
    $n2 = (int)$pdo->query("SELECT COUNT(*) FROM recruit_placements WHERE service_type=''")->fetchColumn();
    echo "待补模式快照的入职记录：$n2 条\n";
    if ($apply) $pdo->exec("UPDATE recruit_placements SET service_type=(SELECT p.service_type FROM recruit_projects p WHERE p.id=recruit_placements.project_id),
                                   replacement_quota=(SELECT p.replacement_count FROM recruit_projects p WHERE p.id=recruit_placements.project_id)
                            WHERE service_type=''");
}
// 3b. hired 且无 placement 的 link → started
$hired = $pdo->query("SELECT cj.id link_id, cj.candidate_id, cj.job_id, j.project_id, cj.stage_changed_at, c.name cand, j.title,
                             " . ($hasCol('recruit_projects', 'guarantee_days') ? "p.guarantee_days gd, p.guarantee_remedy rem, p.currency ccy" : "90 gd, 'replacement' rem, 'IDR' ccy") . "
                      FROM recruit_candidate_jobs cj JOIN recruit_jobs j ON j.id=cj.job_id JOIN recruit_projects p ON p.id=j.project_id
                      JOIN recruit_candidates c ON c.id=cj.candidate_id WHERE cj.stage='hired'"
    . ($exists('recruit_placements') ? " AND NOT EXISTS (SELECT 1 FROM recruit_placements pl WHERE pl.candidate_job_id=cj.id)" : '')
    . " ORDER BY cj.id")->fetchAll(PDO::FETCH_ASSOC);
echo "hired 待回填 placement：" . count($hired) . " 条\n";
foreach ($hired as $h) echo "  link#{$h['link_id']} {$h['cand']} · {$h['title']}（项目 #{$h['project_id']}，阶段变更 " . substr((string)$h['stage_changed_at'], 0, 10) . "）\n";
if ($apply && $hired) {
    $ins = $pdo->prepare("INSERT INTO recruit_placements (project_id, job_id, candidate_id, candidate_job_id, status, actual_start_date, guarantee_days, guarantee_end_date,
                              guarantee_remedy, currency, notes, status_changed_at, created_at, updated_at, service_type, replacement_quota)
                          VALUES (?, ?, ?, ?, 'started', ?, ?, ?, ?, ?, ?, (" . dbNow() . "), (" . dbNow() . "), (" . dbNow() . "),
                                  (SELECT service_type FROM recruit_projects WHERE id=?), (SELECT replacement_count FROM recruit_projects WHERE id=?))");
    foreach ($hired as $h) {
        $start = substr((string)($h['stage_changed_at'] ?: date('Y-m-d')), 0, 10);
        $end = date('Y-m-d', strtotime($start . ' +' . (int)$h['gd'] . ' days'));
        $ins->execute([(int)$h['project_id'], (int)$h['job_id'], (int)$h['candidate_id'], (int)$h['link_id'], $start, (int)$h['gd'], $end,
                       $h['rem'], $h['ccy'], '由 add_recruit_lifecycle_20260924 按 hired 阶段回填；入职日取阶段变更日，费用待补',
                       (int)$h['project_id'], (int)$h['project_id']]);
    }
}

// ---- 4. 结果态 ----
if ($apply) {
    foreach (['recruit_placements', 'recruit_documents'] as $t) if (!$exists($t)) { echo "⛔ $t 没建成\n"; exit(1); }
    foreach ($addCols as $t => $cols) foreach ($cols as $c => $_) if (!$hasCol($t, $c)) { echo "⛔ $t.$c 没加上\n"; exit(1); }
    $leftP = (int)$pdo->query("SELECT COUNT(*) FROM recruit_projects WHERE terms_set_at IS NULL")->fetchColumn();
    $leftH = (int)$pdo->query("SELECT COUNT(*) FROM recruit_candidate_jobs cj JOIN recruit_jobs j ON j.id=cj.job_id JOIN recruit_projects p ON p.id=j.project_id
                                JOIN recruit_candidates c ON c.id=cj.candidate_id
                                WHERE cj.stage='hired' AND NOT EXISTS (SELECT 1 FROM recruit_placements pl WHERE pl.candidate_job_id=cj.id)")->fetchColumn();   // 与回填同一组 JOIN
    $leftT = (int)$pdo->query("SELECT COUNT(*) FROM recruit_projects WHERE COALESCE(service_types,'')='' AND COALESCE(service_type,'')<>''")->fetchColumn()
           + (int)$pdo->query("SELECT COUNT(*) FROM recruit_placements WHERE service_type=''")->fetchColumn();
    echo "\n✅ 建表 $built 张 · 加列 $colsAdded 列 · 待补条款剩 $leftP · 待回填 placement 剩 $leftH · 待补模式剩 $leftT\n";
    exit($leftP === 0 && $leftH === 0 && $leftT === 0 ? 0 : 1);
}
echo "\n（dry-run 未写入。重跑 --apply 应报「待补条款剩 0 · 待回填 placement 剩 0」）\n";
