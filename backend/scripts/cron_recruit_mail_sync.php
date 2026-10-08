<?php
/**
 * 招聘邮箱定时同步（招聘模块 P1）
 *
 *   php /var/www/openhunter/backend/scripts/cron_recruit_mail_sync.php
 *
 * 定时任务不单独配这个脚本：配 cron_recruit_run.php，它先调本脚本拉邮件、再调解析匹配（顺序保证，见该文件）。
 *
 * 手动补拉历史（每次一批，反复跑到「剩余 0 封」）：
 *   php scripts/cron_recruit_mail_sync.php              # 每批 50 封，逐封打印进度
 *   php scripts/cron_recruit_mail_sync.php --limit=200  # 一批多拉一些
 *   php scripts/cron_recruit_mail_sync.php --quiet      # 不打逐封进度（cron 用）
 *   --no-kick   拉到新简历也不自动拉起解析（cron_recruit_run.php 用：它下一步自己跑解析，免得两边抢锁）
 *
 * 容错：单封失败本轮重连重试 3 次；仍失败记入 recruit_mail_failures、游标照常往后走，之后每轮先重试，
 * 累计 10 轮放弃（「招聘邮箱」页可看、可手动重试）。见 includes/recruit_mailsync.php recruitMailSyncRun。
 *
 * 与签证邮件的 cron_mail_sync.php 互不影响：不同配置表、不同落库表。
 * 没有启用的招聘邮箱时静默退出。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_mailsync.php';

/* 防重入：上一轮没跑完（大附件 + 网络慢）时 cron 又拉起一个，两个进程抢同一批 UID。
   唯一键能挡住重复入库，但会白传一遍 OSS。flock 直接让后来的退出。 */
$limit = 50; $quiet = false; $kick = true;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--quiet') $quiet = true;
    elseif ($a === '--no-kick') $kick = false;
    elseif (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = max(1, min(1000, (int)$m[1]));
    else { fwrite(STDERR, "未知参数 $a\n"); exit(1); }
}
// 不是终端（cron 重定向到日志）就默认安静，只留最后的汇总
if (function_exists('posix_isatty') && !posix_isatty(STDOUT)) $quiet = true;

$lock = fopen(sys_get_temp_dir() . '/openhunter_recruit_mail.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo date('c'), " 上一轮仍在运行，跳过\n"; exit(0); }

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try { $pdo->query("SELECT 1 FROM recruit_mail_failures LIMIT 1"); }
catch (Throwable $e) { echo date('c'), " ✗ 缺表 recruit_mail_failures：先跑 php scripts/data-fixes/create_recruit_tables_20260923.php --apply\n"; exit(1); }

try {
    echo date('H:i:s'), " 连接招聘邮箱……\n";
    $r = recruitMailSyncRun($pdo, $limit, $quiet ? null : function (string $ev, array $x) {
        if ($ev === 'connected') {
            printf("%s %s：还有 %d 封没拉，本批拉 %d 封%s\n", date('H:i:s'), $x['box'], $x['total'], $x['batch'],
                $x['retry'] ? "，先重试之前失败的 {$x['retry']} 封" : '');
        } else {
            $what = $x['status'] === 'failed' ? "✗ 失败（试了 {$x['tries']} 次，已记入重试清单）：" . mb_substr($x['error'], 0, 80)
                  : ($x['status'] === 'new' ? "入库 · 简历 {$x['resumes']} 份" : '已有，跳过') . ($x['tries'] > 1 ? "（第 {$x['tries']} 次成功）" : '');
            printf("%s [%d/%d]%s UID %d · %dKB · %s · %.1fs\n", date('H:i:s'), $x['i'], $x['n'], $x['retry'] ? ' 重试' : '',
                $x['uid'], $x['kb'], $what, $x['sec']);
        }
        flush();
    });
} catch (Throwable $e) {
    echo date('c'), " ✗ ", $e->getMessage(), "\n";
    exit(1);
}

if (!$r['boxes']) { echo date('c'), " ", $r['msg'], "\n"; exit(0); }
foreach ($r['boxes'] as $b) {
    printf("%s [#%d %s] 拉取 %d · 新邮件 %d · 简历 %d · 待认领 %d · 剩余 %d 封 · 失败 %d（重试成功 %d，待重试 %d，放弃 %d）%s\n",
        date('c'), $b['mailbox_id'], $b['name'], $b['fetched'], $b['new'], $b['resumes'], $b['unclaimed'], $b['remaining'],
        $b['failed'], $b['retried_ok'], $b['pending_failures'] ?? 0, $b['gave_up'], $b['error'] !== '' ? ' · ✗ ' . $b['error'] : '');
}
// 拉到新简历：自动拉一轮解析 + 匹配（「自动解析」开着才拉，已在跑就不重复），不用等下一个 2 分钟的解析定时任务
if ($kick && array_sum(array_column($r['boxes'], 'resumes')) > 0) {
    require_once $root . '/includes/recruit_pipeline.php';
    recruitKickPipeline($pdo, '新邮件简历');
}
exit($r['ok'] ? 0 : 1);
