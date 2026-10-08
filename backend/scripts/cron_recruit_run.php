<?php
/**
 * OpenHunter · 一条定时任务串完全流程（2026-09-24「先去拉邮件，解析完之后再关联，尽快每次解析完直接关联到对应职位」）
 *
 * 严格按顺序，前一步跑完才跑下一步：
 *   ⓪ 拉招聘邮箱新邮件（cron_recruit_mail_sync.php --no-kick；附件直传 OSS）
 *   ① 解析新简历 → ② 拆职位要求 → ③ 向量化 → ④ 语义分 → ⑤ 大模型打分关联 → ⑥ 高分企微提醒（cron_recruit_parse.php）
 *   ⑦ 通知兜底与汇总：高分补发、新简历 / 待处理 / 故障（cron_recruit_notify.php，不看开关与预算）
 *   ⑧ 企业库：候选人按经历挂到公司、职位名归职能、公司资料补全（cron_recruit_company.php）
 * 只关联系统里在招的职位（职位「招聘中」且项目「进行中」）；已关闭/暂停/不存在的职位不关联、不提醒。
 *
 * crontab 只配这一条（替换原来 mail_sync 每 10 分钟 + parse 每 2 分钟两条）：
 *   * /5 * * * * /usr/bin/php /var/www/openhunter/backend/scripts/cron_recruit_run.php >> /tmp/openhunter_recruit_run.log 2>&1
 *
 * 手动：
 *   php scripts/cron_recruit_run.php                 # 终端里跑，逐封 / 逐份打印进度
 *   php scripts/cron_recruit_run.php --mail-limit=100  # 本轮多拉些邮件（默认 30 封 / 邮箱）
 *   php scripts/cron_recruit_run.php --skip-mail       # 只跑解析匹配提醒
 *
 * 开关：拉邮件不看开关（不花 AI 钱）；① 起受「自动解析」开关与每日 token 预算控制，⑥ 受「高分提醒」开关控制
 *       ——都在「系统设置 · 业务配置 · 招聘设置」。
 * 两个子脚本各有自己的锁；这里再加一把总锁，上一轮没跑完（邮件多 + 解析 100 秒）就跳过本轮。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }
date_default_timezone_set('Asia/Jakarta');   // 与子脚本（config/database.php）日志时间一致

$mailLimit = 30; $skipMail = false; $pass = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--mail-limit=(\d+)$/', $a, $m)) $mailLimit = max(1, min(1000, (int)$m[1]));
    elseif ($a === '--skip-mail') $skipMail = true;
    elseif ($a === '--verbose') $pass[] = $a;
    else { fwrite(STDERR, "未知参数 $a\n"); exit(1); }
}

$lock = fopen(sys_get_temp_dir() . '/openhunter_recruit_run.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo date('c'), " 上一轮仍在运行，跳过\n"; exit(0); }

$php = PHP_BINARY ?: 'php';
$dir = __DIR__;
/* 子进程直接继承本进程的 stdout/stderr（不走 passthru 的管道）：
   终端里手动跑时子脚本仍认得出是终端、照常打逐份进度；cron 下照常写进同一个日志 */
$sh = function (string $script, array $args) use ($php, $dir): int {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg("$dir/$script") . ($args ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '');
    $p = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    return is_resource($p) ? proc_close($p) : 1;
};
$rc = 0;
if (!$skipMail) {
    echo date('c'), " ⓪ 拉邮件\n";
    // 邮箱连不上不挡后面：之前收到还没解析的简历照样要解析、打分
    $rc = max($rc, $sh('cron_recruit_mail_sync.php', ['--no-kick', "--limit=$mailLimit"]));
}
echo date('c'), " ①–⑥ 解析 → 关联 → 提醒\n";
$rc = max($rc, $sh('cron_recruit_parse.php', $pass));
// ⑦ 通知兜底与汇总：不看解析开关 / AI 预算（上一步提前退出时高分也要照发），见 cron_recruit_notify.php
echo date('c'), " ⑦ 通知\n";
$sh('cron_recruit_notify.php', []);
// ⑧ 企业库：挂靠 / 职能归类 / 资料补全（cron_recruit_company.php；挂靠不花钱照跑，另两步看开关与预算）
echo date('c'), " ⑧ 企业库\n";
$sh('cron_recruit_company.php', []);
exit($rc);
