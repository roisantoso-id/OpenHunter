<?php
/**
 * 按租户出用量账单依据：AI token（按场景）+ 存储（简历、合同等附件的字节数）。软件本身不收费，按量计费就看这张表。
 *
 *   php scripts/usage_report.php                     # 本月，所有活跃租户，表格
 *   php scripts/usage_report.php --month=2026-09     # 指定月份
 *   php scripts/usage_report.php --tenant=acme       # 只看一个租户
 *   php scripts/usage_report.php --format=json|csv   # 给计费系统吃
 *
 * 数据来源（每个租户自己的库）：
 *   AI    ai_api_usage（每次 LLM / 向量调用一行，含 prompt / completion token；scene 以 recruit 开头）。usd 按租户设置里的单价折算（includes/recruit_cost.php）
 *   存储  recruit_resumes.file_size + recruit_documents.file_size（上传时记录的字节数，已删除的不算）；storage_snapshot 是「此刻」的存量，不是月累计
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/recruit_cost.php';

$args = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
$month = $args['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) { fwrite(STDERR, "--month 格式 YYYY-MM\n"); exit(1); }
$from = $month . '-01 00:00:00';
$to = date('Y-m-t 23:59:59', strtotime($from));
$format = $args['format'] ?? 'table';

$rows = [];
foreach (Tenant::all() as $t) {
    if ($t['status'] !== 'active' || (!empty($args['tenant']) && $args['tenant'] !== $t['slug'])) continue;
    Tenant::set($t['slug']);
    $pdo = Database::getInstance()->getConnection();
    $ai = recruitAiUsage($pdo, $from, $to);
    $stor = ['files' => 0, 'bytes' => 0];
    foreach (['recruit_resumes', 'recruit_documents'] as $tb) {
        if (!dbTableExists($pdo, $tb)) continue;
        $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(file_size),0) b FROM $tb WHERE file_path<>''")->fetch();
        $stor['files'] += (int)$r['n']; $stor['bytes'] += (int)$r['b'];
    }
    $rows[] = [
        'tenant' => $t['slug'], 'month' => $month,
        'ai_calls' => $ai['total']['calls'], 'ai_failed' => $ai['total']['failed'], 'ai_tokens' => $ai['total']['tokens'], 'ai_usd' => $ai['total']['usd'],
        'ai_by_scene' => array_map(fn($s) => ['scene' => $s['scene'], 'calls' => $s['calls'], 'prompt' => $s['prompt'], 'completion' => $s['completion'], 'usd' => $s['usd']], $ai['by_scene']),
        'storage_files' => $stor['files'], 'storage_bytes' => $stor['bytes'], 'storage_mb' => round($stor['bytes'] / 1048576, 2),
    ];
}

if ($format === 'json') { echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"; exit(0); }
$cols = ['tenant', 'month', 'ai_calls', 'ai_failed', 'ai_tokens', 'ai_usd', 'storage_files', 'storage_mb'];
if ($format === 'csv') {
    echo implode(',', $cols) . "\n";
    foreach ($rows as $r) echo implode(',', array_map(fn($c) => $r[$c], $cols)) . "\n";
    exit(0);
}
printf("%-16s %-8s %9s %7s %12s %9s %8s %10s\n", ...$cols);
foreach ($rows as $r) printf("%-16s %-8s %9d %7d %12d %9.4f %8d %10.2f\n", ...array_map(fn($c) => $r[$c], $cols));
