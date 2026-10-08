<?php
/**
 * 招聘通知补漏测试（测试库集成，假通知不真发企微，自清理）：php tests/recruit_notify_test.php
 * 要守住的（2026-09-24「一定要及时给 HR 通知，也要给负责人通知」）：
 *   抄送人默认负责人、显式清空就不抄送；高分提醒永远带上抄送人、人工改分可单独立即发；
 *   阶段变更发归属人 + 项目负责人，面试 / Offer / 录用才抄送，操作人自己不收；转归属通知新归属人；推荐信已发通知负责人；
 *   汇总类（新简历 / 待处理 / 故障）每件事只发一次、故障 6 小时一次；印尼公休日判定。
 */

$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_alert.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = dbNow();
$orig = [];
foreach (['recruit.alert.config', 'recruit.notify.cc_user_ids'] as $k) {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
    $st->execute([$k]);
    $orig[$k] = $st->fetchColumn();
}
$made = ['proj' => [], 'job' => [], 'cand' => [], 'res' => [], 'mb' => [], 'hol' => []];
$sent = [];
recruitNotifySetHook(function (int $uid, string $type, array $p, string $link) use (&$sent) { $sent[] = compact('uid', 'type', 'p', 'link'); });
// 普通闭包按引用拿 $sent（箭头函数是按值捕获，拿到的永远是定义时的空数组）
$to = function (string $type) use (&$sent) { return array_values(array_map(fn($x) => $x['uid'], array_filter($sent, fn($x) => $x['type'] === $type))); };

$users = array_map('intval', $pdo->query("SELECT id FROM users WHERE status='active' AND role<>'admin' ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN));
if (count($users) < 3) { echo "测试库至少要 3 个在职用户\n"; exit(1); }
[$hr, $mgr, $cc] = $users;

try {
    echo "一、抄送人\n";
    $pdo->exec("DELETE FROM system_settings WHERE setting_key='recruit.notify.cc_user_ids'");
    $ws = recruitHostDefaultWatchers($pdo);
    ok(recruitNotifyCcIds($pdo) === $ws, '从没设置过 → 默认系统管理员');
    setSystemSetting($pdo, 'recruit.notify.cc_user_ids', '[]');
    ok(recruitNotifyCcIds($pdo) === [], '显式清空 → 不抄送任何人');
    setSystemSetting($pdo, 'recruit.notify.cc_user_ids', json_encode([$cc]));
    ok(recruitNotifyCcIds($pdo) === [$cc], '设置了就按设置的');

    echo "\n二、高分提醒带抄送人 / 人工改分单独发\n";
    $pdo->prepare("INSERT INTO recruit_projects (name, kind, status, manager_user_id, created_at, updated_at) VALUES ('__notify_test', 'client', 'open', ?, $now, $now)")->execute([$mgr]);
    $pid = $made['proj'][] = (int)$pdo->lastInsertId();
    $job = function (string $title) use ($pdo, $pid, $now, &$made) {
        $pdo->prepare("INSERT INTO recruit_jobs (project_id, title, jd_text, status, jd_rev, created_at, updated_at) VALUES (?, ?, 'x', 'open', 1, $now, $now)")->execute([$pid, $title]);
        return $made['job'][] = (int)$pdo->lastInsertId();
    };
    $cand = function (string $name, int $owner) use ($pdo, $now, &$made) {
        $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, status, owner_user_id, owner_user_name, created_at, updated_at)
                       VALUES (?, '{}', 1, 'new', ?, ?, $now, $now)")->execute([$name, $owner, $owner ? 'HR甲' : '']);
        return $made['cand'][] = (int)$pdo->lastInsertId();
    };
    $link = function (int $cid, int $jid, ?float $ai, string $stage = 'suggested') use ($pdo, $now) {
        $pdo->prepare("INSERT INTO recruit_candidate_jobs (candidate_id, job_id, origin, ai_score, ai_reason, stage, ai_at, created_at, updated_at)
                       VALUES (?, ?, 'ai', ?, '{}', ?, '2099-06-01 10:00:00', $now, $now)")->execute([$cid, $jid, $ai, $stage]);
        return (int)$pdo->lastInsertId();
    };
    $j1 = $job('NOC'); $j2 = $job('Sales');
    $c1 = $cand('__Budi', $hr);
    $l1 = $link($c1, $j1, 4.5); $l2 = $link($c1, $j2, 4.5);
    setSystemSetting($pdo, 'recruit.alert.config', json_encode(['enabled' => true, 'threshold' => 4, 'user_ids' => [], 'since' => '2099-05-01 00:00:00']));
    $got = [];
    recruitHighScoreAlerts($pdo, function (int $uid, array $p) use (&$got) { $got[] = [$uid, $p['job']]; }, 50, $l1);
    ok($got === [[$hr, 'NOC'], [$cc, 'NOC']], '只发指定的组合；固定接收人清空了，抄送人照样收到');
    $got = [];
    recruitHighScoreAlerts($pdo, function (int $uid, array $p) use (&$got) { $got[] = $p['job']; });
    ok($got === ['Sales', 'Sales'], '其余组合下一轮照发，已发过的不重复');

    echo "\n三、阶段变更\n";
    $sent = [];
    recruitNotifyStageChange($pdo, $l1, 'shortlisted', 'interviewing', $hr, 'HR甲');
    ok($to('recruit_stage') === [$mgr, $cc], '面试中：项目负责人 + 抄送人；操作人（归属人本人）不收');
    ok(($sent[0]['p']['to_zh'] ?? '') === '面试中' && ($sent[0]['p']['from_en'] ?? '') === 'Shortlisted', '阶段名三语都带上');
    $sent = [];
    recruitNotifyStageChange($pdo, $l1, 'interviewing', 'rejected', $mgr, '经理');
    ok($to('recruit_stage') === [$hr], '未通过：只发归属人（不抄送、操作人不收）');
    $sent = [];
    recruitNotifyStageChange($pdo, $l1, 'suggested', 'shortlisted', $mgr, '经理');
    ok($sent === [], '初筛通过是日常操作，不发');

    echo "\n四、转归属 / 推荐信已发\n";
    $sent = [];
    recruitNotifyOwnerChanged($pdo, $c1, $mgr, 'HR甲', $cc, '管理员');
    ok($to('recruit_owner') === [$mgr] && str_contains($sent[0]['p']['best'] ?? '', 'NOC'), '新归属人收到，带最匹配的职位');
    $sent = [];
    recruitNotifyOwnerChanged($pdo, $c1, $mgr, 'HR甲', $mgr, '经理');
    ok($sent === [], '转给自己不通知');
    $sent = [];
    recruitNotifyRecoSent($pdo, $c1, $j1, 'PT Klien', $hr, 'HR甲');
    ok($to('recruit_reco_sent') === [$mgr] && ($sent[0]['p']['client'] ?? '') === 'PT Klien', '推荐信已发：项目负责人收到（操作人不收）');

    echo "\n五、汇总类只发一次\n";
    $ready = recruitNotifyLogReady($pdo);
    ok($ready, '去重表已建（没建先跑 create_recruit_tables_20260923.php --apply）');
    if ($ready) {
        $c2 = $cand('__Siti', 0);
        $res = function (int $cid, string $status) use ($pdo, $now, &$made) {
            $pdo->prepare("INSERT INTO recruit_resumes (dedupe_key, parse_status, doc_type, candidate_id, parsed_at, created_at, updated_at)
                           VALUES (?, ?, 'cv', ?, $now, $now, $now)")->execute(['__notify_test_' . uniqid('', true), $status, $cid]);
            return $made['res'][] = (int)$pdo->lastInsertId();
        };
        $res($c1, 'parsed'); $res($c1, 'parsed'); $res($c2, 'parsed'); $res(0, 'failed');
        $pdo->prepare("INSERT INTO recruit_mailboxes (name, username, enabled, last_error, created_at, updated_at) VALUES ('__notify_mb', 'x@test', 1, 'LOGIN failed', $now, $now)")->execute();
        $made['mb'][] = (int)$pdo->lastInsertId();
        $sent = [];
        $t0 = time();
        $d = recruitNotifyDigests($pdo, $t0);
        $nr = array_values(array_filter($sent, fn($x) => $x['type'] === 'recruit_new_resumes' && $x['uid'] === $hr));
        ok(count($nr) === 1 && (int)$nr[0]['p']['n'] === 1, '归属人一条：同一人两份简历只算 1 位');
        $att = array_values(array_filter($sent, fn($x) => $x['type'] === 'recruit_attention'));
        ok($att && $att[0]['p']['unowned'] >= 1 && $att[0]['p']['failed'] >= 1, '待处理：没归属 + 解析失败');
        ok(in_array($cc, $to('recruit_fault'), true), '邮箱出错 → 抄送人收到故障');
        $sent = [];
        recruitNotifyDigests($pdo, $t0 + 60);
        ok($to('recruit_new_resumes') === [] && $to('recruit_attention') === [] && $to('recruit_fault') === [], '5 分钟后再跑：同样的事不再发');
        $sent = [];
        recruitNotifyDigests($pdo, $t0 + RECRUIT_NOTIFY_FAULT_HOURS * 3600 + 60);
        ok(in_array($cc, $to('recruit_fault'), true), '6 小时后故障还在 → 再提醒一次');
    }

    echo "\n六、公休日\n";
    $d = '2099-08-17';
    $pdo->prepare("INSERT INTO holiday_cache (year, country, holiday_date, name, type, fetched_at) VALUES (2099, 'ID', ?, '__test', 'holiday', CURRENT_TIMESTAMP)")->execute([$d]);
    $made['hol'][] = $d;
    ok(recruitNotifyIsHoliday($pdo, $d) && !recruitNotifyIsHoliday($pdo, '2099-08-18'), '印尼公休日判定');
} finally {
    recruitNotifySetHook(null);
    if ($made['cand']) {
        $ids = implode(',', $made['cand']);
        $pdo->exec("DELETE FROM recruit_candidate_jobs WHERE candidate_id IN ($ids)");
        $pdo->exec("DELETE FROM recruit_candidates WHERE id IN ($ids)");
    }
    if ($made['res']) $pdo->exec("DELETE FROM recruit_resumes WHERE id IN (" . implode(',', $made['res']) . ")");
    if ($made['mb']) $pdo->exec("DELETE FROM recruit_mailboxes WHERE id IN (" . implode(',', $made['mb']) . ")");
    if ($made['job']) $pdo->exec("DELETE FROM recruit_jobs WHERE id IN (" . implode(',', $made['job']) . ")");
    if ($made['proj']) $pdo->exec("DELETE FROM recruit_projects WHERE id IN (" . implode(',', $made['proj']) . ")");
    foreach ($made['hol'] as $d) $pdo->prepare("DELETE FROM holiday_cache WHERE holiday_date=? AND name='__test'")->execute([$d]);
    if (recruitNotifyLogReady($pdo)) {
        foreach ($made['res'] as $r) $pdo->prepare("DELETE FROM recruit_notify_log WHERE dedupe_key=?")->execute(["r$r"]);
        foreach ($made['mb'] as $m) $pdo->exec("DELETE FROM recruit_notify_log WHERE event='fault_mailbox' AND dedupe_key LIKE 'mb$m:%'");
    }
    foreach ($orig as $k => $v) {
        if ($v === false) $pdo->prepare("DELETE FROM system_settings WHERE setting_key=?")->execute([$k]);
        else setSystemSetting($pdo, $k, (string)$v);
    }
}

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
