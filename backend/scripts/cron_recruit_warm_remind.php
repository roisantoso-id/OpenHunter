<?php
/**
 * OpenHunter · 保温提醒（2026-09-23「候选人需要保温，要定时提醒招聘专员去做」）
 *
 * 每个工作日早上给每位招聘专员发一条站内信 + 企微：今天有几个人该联系了（约好的 / 在流程中 / 高分没联系过 / 高分晾太久），
 * 并**再强调一次他的固定收简历地址**（plus 地址）——简历和候选人往来邮件一律发/抄送到这个地址，
 * 不要发到个人邮箱：系统只收这个地址，个人邮箱里的东西进不了人才库（我们没法维护一堆个人邮箱）。
 * 判定规则与人力工作台同一份（includes/recruit_warm.php）。没有要保温的人就不发，不打扰。
 *
 *   0 9 * * 1-5 /usr/bin/php /var/www/openhunter/backend/scripts/cron_recruit_warm_remind.php >> /tmp/openhunter_recruit_warm.log 2>&1
 *
 * 手动：
 *   php scripts/cron_recruit_warm_remind.php --dry-run     # 只打印每人会收到什么，不发
 *   php scripts/cron_recruit_warm_remind.php --user=19     # 只给这一个人发（联调）
 *
 * 开关：system_settings `recruit.warm.remind_enabled` = '1' 才发（默认关，上线后在设置里开）。
 * 通知类型 recruit_warm 在「设置 · 通知配置」里也能单独关。
 * 同一人 20 小时内发过就不再发（cron 重跑、手动补跑都不会重复轰炸）。
 *
 * 2026-09-24 补漏（需求方「候选人的整个保温都要给 HR 通知，也要给负责人通知」）：
 *   · 收件人 = 名下有候选人的在职员工（原先只发有专员代码的人，手动转过去的归属人收不到）
 *   · 另给「招聘抄送人」（默认负责人，includes/recruit_notify.php）发一条汇总：每位专员要保温几人 + 没归属的几人
 *     （通知类型 recruit_warm_digest，可在通知配置里单独关）
 *   · 印尼公休日不发（holiday_cache，「设置 · 节假日」拉取的数据；没拉过就照常发）
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_warm.php';
require_once $root . '/includes/recruit_notify.php';

$dry = in_array('--dry-run', $argv, true);
$onlyUser = 0;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') continue;
    if (preg_match('/^--user=(\d+)$/', $a, $m)) { $onlyUser = (int)$m[1]; continue; }
    fwrite(STDERR, "未知参数 $a\n"); exit(1);
}

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='recruit.warm.remind_enabled'");
$st->execute();
if ((string)$st->fetchColumn() !== '1' && !$dry) { echo date('c'), " recruit.warm.remind_enabled 未开启，跳过\n"; exit(0); }

if (recruitNotifyIsHoliday($pdo) && !$dry && !$onlyUser) { echo date('c'), " 今天是印尼公休日，不发\n"; exit(0); }

// 名下有候选人的在职员工 + 有专员代码的人（代码在、暂时没人也列出来，dry-run 看得到）
$users = $pdo->query("SELECT u.id, u.name FROM users u WHERE u.status='active' AND (
                          u.id IN (SELECT owner_user_id FROM recruit_candidates WHERE owner_user_id>0)
                          OR u.id IN (SELECT user_id FROM recruit_sources WHERE active=1 AND user_id>0))
                      ORDER BY u.id")->fetchAll(PDO::FETCH_KEY_PAIR);
$recent = $pdo->prepare("SELECT 1 FROM notifications WHERE user_id=? AND type=? AND created_at>=? LIMIT 1");
$sent = 0; $skipped = 0;
$digest = [];   // 给抄送人的汇总：专员名 => 要保温的人数
foreach ($users as $uid => $name) {
    $uid = (int)$uid;
    if ($onlyUser && $uid !== $onlyUser) continue;
    $warm = recruitWarmList($pdo, $uid);
    if (!$warm) { echo "  {$name}(#{$uid})：没有要保温的人，不发\n"; continue; }
    $digest[$name] = count($warm);
    $cnt = ['due' => 0, 'active_stage' => 0, 'never' => 0, 'strong_idle' => 0];
    foreach ($warm as $w) $cnt[$w['reason']]++;
    $addr = array_values(array_filter(recruitUserAddresses($pdo, $uid), fn($a) => $a['enabled']));
    $params = ['n' => count($warm), 'due' => $cnt['due'], 'active' => $cnt['active_stage'], 'never' => $cnt['never'], 'idle' => $cnt['strong_idle'],
               'names' => implode('、', array_map(fn($w) => $w['name'] ?: recruitCandCode((int)$w['id']), array_slice($warm, 0, 3))),
               'address' => $addr ? $addr[0]['address'] : '-'];
    echo "  {$name}(#{$uid})：", json_encode($params, JSON_UNESCAPED_UNICODE), "\n";
    if ($dry) continue;
    // created_at 可能是 UTC（库默认值）也可能是雅加达时间，20 小时窗口两种都能挡住同一天重跑
    $recent->execute([$uid, 'recruit_warm', date('Y-m-d H:i:s', time() - 20 * 3600)]);
    if ($recent->fetchColumn()) { echo "    20 小时内已发过，跳过\n"; $skipped++; continue; }
    createNotification($pdo, $uid, 'recruit_warm', '', '', '/recruit/workbench', 'recruit_workbench', 0, 'recruit_warm', $params);
    $sent++;
}

// 抄送人汇总（--user 联调时不发）：各专员要保温的人数 + 没归属的人数
if (!$onlyUser) {
    $unowned = count(recruitWarmList($pdo, 0));
    if ($digest || $unowned) {
        $by = implode('、', array_map(fn($n, $c) => "$n $c", array_keys($digest), $digest));
        $params = ['n' => array_sum($digest) + $unowned, 'by' => $by !== '' ? $by : '-', 'unowned' => $unowned];
        echo "  抄送汇总：", json_encode($params, JSON_UNESCAPED_UNICODE), "\n";
        if (!$dry) {
            foreach (recruitNotifyCcIds($pdo) as $cc) {
                $recent->execute([$cc, 'recruit_warm_digest', date('Y-m-d H:i:s', time() - 20 * 3600)]);
                if ($recent->fetchColumn()) { echo "    #{$cc} 20 小时内已发过汇总，跳过\n"; continue; }
                recruitNotify($pdo, 'recruit_warm_digest', [$cc], $params, '/recruit/stats');
                $sent++;
            }
        }
    }
}
echo date('c'), ($dry ? ' [dry-run] ' : ' '), "发送 $sent 条，跳过 $skipped 人\n";
