<?php
/**
 * 高分人选提醒测试（测试库集成，假通知不真发企微，自清理）：php tests/recruit_alert_test.php
 * 要守住的：只提醒在招职位（职位招聘中 + 项目进行中）；分数线按人工分优先；每个「人 × 职位」只提醒一次；
 * 开启前打的分不补发；已淘汰/移除的不提醒；接收人 = 归属招聘专员 + 固定接收人（去重）；推荐理由三语都带上。
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
$st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='recruit.alert.config'");
$st->execute();
$origCfg = $st->fetchColumn();
// 招聘抄送人（recruit_notify.php）另有测试；这里固定为「不抄送」，只验高分提醒自己的接收人
$st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='recruit.notify.cc_user_ids'");
$st->execute();
$origCc = $st->fetchColumn();
setSystemSetting($pdo, 'recruit.notify.cc_user_ids', '[]');
$made = ['proj' => [], 'job' => [], 'cand' => []];

try {
    echo "一、默认配置\n";
    $pdo->exec("DELETE FROM system_settings WHERE setting_key='recruit.alert.config'");
    $d = recruitAlertConfig($pdo);
    $watchers = recruitHostDefaultWatchers($pdo);
    $watcher = (int)($watchers[0] ?? 0);
    ok(!$d['enabled'] && $d['threshold'] === 4.0, '默认关、分数线 4');
    ok($d['user_ids'] === $watchers, '从没保存过：固定接收人默认系统管理员');
    ok(recruitHighScoreAlerts($pdo, fn() => null)['enabled'] === false, '开关关着 → 什么都不发');

    echo "\n二、只提醒在招职位的高分\n";
    $proj = function (string $status) use ($pdo, $now, &$made) {
        $pdo->prepare("INSERT INTO recruit_projects (name, kind, status, created_at, updated_at) VALUES (?, 'client', ?, $now, $now)")
            ->execute(["__alert_test_$status", $status]);
        return $made['proj'][] = (int)$pdo->lastInsertId();
    };
    $job = function (int $pid, string $title, string $status) use ($pdo, $now, &$made) {
        $pdo->prepare("INSERT INTO recruit_jobs (project_id, title, jd_text, status, jd_rev, created_at, updated_at) VALUES (?, ?, 'x', ?, 1, $now, $now)")
            ->execute([$pid, $title, $status]);
        return $made['job'][] = (int)$pdo->lastInsertId();
    };
    $pOpen = $proj('open'); $pClosed = $proj('closed');
    $jOpen = $job($pOpen, 'NOC Engineer', 'open');
    $jOpen2 = $job($pOpen, 'Sales', 'open');
    $jClosed = $job($pOpen, 'Old role', 'closed');
    $jInClosedProj = $job($pClosed, 'Role in closed project', 'open');
    $owner = (int)$pdo->query("SELECT id FROM users WHERE status='active' AND role<>'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, status, owner_user_id, owner_user_name, latest_title, years_exp, created_at, updated_at)
                   VALUES ('__Budi', '{}', 1, 'new', ?, 'HR甲', 'Network Engineer', 5, $now, $now)")->execute([$owner]);
    $cid = $made['cand'][] = (int)$pdo->lastInsertId();
    // 未来时间：保证不会碰到测试库里已有的真实组合（since 只放行这些）
    $link = function (int $jid, $ai, $human = null, string $stage = 'suggested', string $at = '2099-06-01 10:00:00') use ($pdo, $cid, $now) {
        $pdo->prepare("INSERT INTO recruit_candidate_jobs (candidate_id, job_id, origin, ai_score, human_score, ai_reason, ai_gaps, stage, ai_at, created_at, updated_at)
                       VALUES (?, ?, 'ai', ?, ?, ?, ?, ?, ?, $now, $now)")
            ->execute([$cid, $jid, $ai, $human, json_encode(['zh' => '做过 3 年 NOC', 'en' => '3 yrs NOC', 'id' => '3 thn NOC'], JSON_UNESCAPED_UNICODE),
                       json_encode(['无 CCNA'], JSON_UNESCAPED_UNICODE), $stage, $at]);
        return (int)$pdo->lastInsertId();
    };
    $lHit = $link($jOpen, 4.5);
    $lHuman = $link($jOpen2, 3.0, 4.0);                     // 人工分 4 盖过 AI 分 3
    $link($jClosed, 5.0);                                   // 职位已关闭
    $link($jInClosedProj, 5.0);                             // 项目已关闭
    $pdo->prepare("UPDATE recruit_candidate_jobs SET stage='removed' WHERE id=?")->execute([$lHuman]);
    $lRemovedCheck = $lHuman;
    // 再补一条被移除以外的对照：3.5 分不够线
    $pLow = $proj('open'); $jLow = $job($pLow, 'Low', 'open'); $link($jLow, 3.5);
    $jOld = $job($pLow, 'Old score', 'open'); $link($jOld, 5.0, null, 'suggested', '2099-01-01 00:00:00');   // 开启前打的分

    setSystemSetting($pdo, 'recruit.alert.config', json_encode(['enabled' => true, 'threshold' => 4, 'user_ids' => array_filter([$watcher, $owner]),
                                                                 'since' => '2099-05-01 00:00:00']));
    $sent = [];
    $rep = recruitHighScoreAlerts($pdo, function (int $uid, array $p, string $link) use (&$sent) { $sent[] = [$uid, $p, $link]; });
    $jobsHit = array_values(array_unique(array_map(fn($x) => $x[1]['job'], $sent)));
    ok($jobsHit === ['NOC Engineer'], '只提醒在招职位 ≥4 分：关闭职位 / 关闭项目 / 3.5 分 / 已移除 / 开启前的分都不提醒');
    ok($rep['links'] === 1, '1 个组合');
    $to = array_map(fn($x) => $x[0], $sent);
    ok($to === array_values(array_unique(array_filter([$owner, $watcher]))), '接收人 = 归属专员 + 固定接收人，重复的只发一次');
    $p = $sent[0][1] ?? [];
    ok(($p['reason_zh'] ?? '') === '做过 3 年 NOC' && ($p['reason_en'] ?? '') === '3 yrs NOC' && ($p['reason_id'] ?? '') === '3 thn NOC', '推荐理由三语都带上');
    ok(($p['score'] ?? '') === '4.5' && ($p['gaps'] ?? '') === '无 CCNA' && ($p['owner'] ?? '') === 'HR甲', '分数 / 缺项 / 归属专员');
    ok(str_contains($sent[0][2] ?? '', "job_id=$jOpen"), '链接直达该职位的人才库');

    echo "\n三、只提醒一次 / 人工分\n";
    $sent = [];
    recruitHighScoreAlerts($pdo, function (int $uid, array $p) use (&$sent) { $sent[] = $p; });
    ok($sent === [], '第二轮不重复提醒');
    $pdo->prepare("UPDATE recruit_candidate_jobs SET stage='shortlisted', human_at='2099-06-02 00:00:00' WHERE id=?")->execute([$lRemovedCheck]);
    recruitHighScoreAlerts($pdo, function (int $uid, array $p) use (&$sent) { $sent[] = $p; });
    ok(count($sent) >= 1 && $sent[0]['job'] === 'Sales' && $sent[0]['score'] === '4.0', '恢复后：人工分 4 盖过 AI 分 3 → 提醒');
} finally {
    if ($made['cand']) {
        $ids = implode(',', $made['cand']);
        $pdo->exec("DELETE FROM recruit_candidate_jobs WHERE candidate_id IN ($ids)");
        $pdo->exec("DELETE FROM recruit_candidates WHERE id IN ($ids)");
    }
    if ($made['job']) $pdo->exec("DELETE FROM recruit_jobs WHERE id IN (" . implode(',', $made['job']) . ")");
    if ($made['proj']) $pdo->exec("DELETE FROM recruit_projects WHERE id IN (" . implode(',', $made['proj']) . ")");
    if ($origCc === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key='recruit.notify.cc_user_ids'");
    else setSystemSetting($pdo, 'recruit.notify.cc_user_ids', (string)$origCc);
    if ($origCfg === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key='recruit.alert.config'");
    else setSystemSetting($pdo, 'recruit.alert.config', (string)$origCfg);
}

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
