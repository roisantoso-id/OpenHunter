<?php
/**
 * 招聘保温判定（纯函数）：php tests/recruit_warm_test.php
 * 要守住的：只看人工跟进；约好的最优先；在流程中 3 天、高分 14 天；已入职/不感兴趣/黑名单不提醒。
 */
require_once dirname(__DIR__) . '/includes/recruit_warm.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }
$cfg = RECRUIT_WARM_DEFAULTS;
$now = '2026-09-24 09:00:00';
$c = fn(array $x) => $x + ['status' => 'new', 'next_followup_at' => null, 'last_note_at' => null, 'first_received_at' => '2026-09-01 10:00:00',
                           'active_stage' => '', 'best_score' => null];

ok(recruitWarmReason($c(['next_followup_at' => '2026-09-24 15:00:00', 'last_note_at' => '2026-09-23 10:00:00']), $cfg, $now)['reason'] === 'due', '约好今天跟进 → due（哪怕昨天刚联系过）');
ok(recruitWarmReason($c(['active_stage' => 'interviewing', 'last_note_at' => '2026-09-22 10:00:00']), $cfg, $now) === null, '面试中、2 天前联系过 → 不提醒');
$r = recruitWarmReason($c(['active_stage' => 'interviewing', 'last_note_at' => '2026-09-21 10:00:00']), $cfg, $now);
ok($r['reason'] === 'active_stage' && $r['idle_days'] === 3, '面试中、3 天没联系 → active_stage，晾 3 天');
$r = recruitWarmReason($c(['best_score' => 4.5]), $cfg, $now);
ok($r['reason'] === 'never' && $r['idle_days'] === 23, '4.5 分从没联系过 → never，按收到简历算晾了 23 天');
ok(recruitWarmReason($c(['best_score' => 4.0, 'last_note_at' => '2026-09-15 10:00:00']), $cfg, $now) === null, '高分、9 天前联系过 → 不提醒');
ok(recruitWarmReason($c(['best_score' => 4.0, 'last_note_at' => '2026-09-10 10:00:00']), $cfg, $now)['reason'] === 'strong_idle', '高分、14 天没联系 → strong_idle');
ok(recruitWarmReason($c(['best_score' => 3.5]), $cfg, $now) === null, '3.5 分不算高分 → 不提醒');
foreach (['placed', 'not_interested', 'unreachable', 'blacklisted'] as $s)
    ok(recruitWarmReason($c(['status' => $s, 'best_score' => 5, 'next_followup_at' => '2026-09-20']), $cfg, $now) === null, "$s → 不提醒");

$rows = [['id' => 1, 'reason' => 'strong_idle', 'idle_days' => 30], ['id' => 2, 'reason' => 'active_stage', 'idle_days' => 3],
         ['id' => 3, 'reason' => 'due', 'idle_days' => 1], ['id' => 4, 'reason' => 'active_stage', 'idle_days' => 9]];
recruitWarmSort($rows);
ok(array_column($rows, 'id') === [3, 4, 2, 1], '排序：约好的 → 流程中（晾得久的在前）→ 高分');

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
