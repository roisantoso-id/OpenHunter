<?php
/**
 * OpenHunter · 通知兜底与汇总（2026-09-24「一定要及时给这个 HR 通知」）
 *
 * 由 cron_recruit_run.php 在最后一步 ⑦ 调用（每 5 分钟），**不看「自动解析」开关、不看 AI 预算**——不花 AI 钱：
 *   · 高分提醒兜底：流水线在开关关着 / 超预算时会在第 ⑥ 步之前退出，人工打的高分就一直发不出去；这里补发
 *     （每个「人 × 职位」只发一次，与第 ⑥ 步共用 alerted_at，不会重复）
 *   · 新简历到了（按归属人汇总）、待人工处理（没归属 / 解析失败 / 手机号冲突）、系统故障（邮箱出错、流水线失败）
 * 规则与接收人见 includes/recruit_notify.php 头注释。
 *
 * 手动：
 *   php scripts/cron_recruit_notify.php            # 跑一轮
 *   php scripts/cron_recruit_notify.php --dry-run  # 只打印接收人配置，不发
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_alert.php';

$dry = in_array('--dry-run', $argv, true);
foreach (array_slice($argv, 1) as $a) if ($a !== '--dry-run') { fwrite(STDERR, "未知参数 $a\n"); exit(1); }

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($dry) {
    $ac = recruitAlertConfig($pdo);
    echo "高分提醒：", $ac['enabled'] ? "开 · ≥{$ac['threshold']} · 固定接收人 " . implode(',', $ac['user_ids']) : '关', "\n";
    echo "抄送人：", implode(',', recruitNotifyCcIds($pdo)) ?: '（无）', "\n";
    echo "招聘管理员：", implode(',', recruitNotifyAdminIds($pdo)) ?: '（无，汇总发给抄送人）', "\n";
    echo "去重表：", recruitNotifyLogReady($pdo) ? '已建' : '⚠️ 未建（跑 create_recruit_tables_20260923.php --apply），汇总类通知不发', "\n";
    exit(0);
}

$out = [];
try {
    $al = recruitHighScoreAlerts($pdo);
    $out[] = $al['enabled'] ? "高分 {$al['links']} 组 {$al['messages']} 条" : '高分提醒未开';
} catch (Throwable $e) { $out[] = '高分提醒失败：' . $e->getMessage(); }
try {
    $d = recruitNotifyDigests($pdo);
    $out[] = $d['skipped'] ? '汇总跳过（去重表未建）' : "新简历 {$d['new_resumes']} 条 · 待处理 {$d['attention']} 条 · 故障 {$d['fault']} 条";
} catch (Throwable $e) { $out[] = '汇总失败：' . $e->getMessage(); }
echo date('c'), ' ⑦ 通知：', implode(' ｜ ', $out), "\n";
