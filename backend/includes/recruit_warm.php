<?php
/**
 * OpenHunter · 候选人保温（2026-09-23「候选人需要维护、需要保温，要定时提醒招聘专员去做」）
 *
 * 「保温」= 好的人选隔一段时间没人工联系就会凉掉（被别家挖走、换号、忘了我们）。
 * 判定只看**人工跟进记录**（recruit_followups.kind<>'system'）——系统事件、AI 推荐都不算联系过。
 *
 * 规则（system_settings `recruit.warm.config` 可覆盖）：
 *   due          约好的下次跟进时间到了（最优先）
 *   active_stage 在流程中（已入围/已推荐/面试中/已发 offer），超过 active_days 天没联系
 *   never        有 ≥ strong_score 分的匹配，但从来没人联系过
 *   strong_idle  有 ≥ strong_score 分的匹配，超过 pool_days 天没联系
 * 已入职 / 不感兴趣 / 联系不上 / 黑名单 的人不提醒。
 *
 * 由 handlers/recruit.php（人力工作台）与 scripts/cron_recruit_warm_remind.php（企微提醒）共用同一份判定。
 */

const RECRUIT_WARM_DEFAULTS = ['active_days' => 3, 'pool_days' => 14, 'strong_score' => 4.0];
const RECRUIT_WARM_SKIP_STATUS = ['placed', 'not_interested', 'unreachable', 'blacklisted'];
const RECRUIT_WARM_ACTIVE_STAGES = ['shortlisted', 'submitted', 'interviewing', 'offered'];

function recruitWarmConfig(PDO $pdo): array {
    $cfg = RECRUIT_WARM_DEFAULTS;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='recruit.warm.config'");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
        if (is_array($j)) $cfg = array_replace($cfg, array_intersect_key($j, $cfg));
    } catch (Throwable $e) { /* 用默认 */ }
    return $cfg;
}

/**
 * 纯函数：一个人要不要保温、为什么、已经晾了几天。
 * $c 需要：status, next_followup_at, last_note_at, first_received_at, active_stage（最靠后的在途阶段或 ''）, best_score（null 可）
 * @return array{reason:string, idle_days:?int}|null  null = 不用提醒
 */
function recruitWarmReason(array $c, array $cfg, string $now): ?array {
    if (in_array($c['status'] ?? '', RECRUIT_WARM_SKIP_STATUS, true)) return null;
    $day = fn(?string $v) => $v ? (int)floor((strtotime(substr($now, 0, 10)) - strtotime(substr($v, 0, 10))) / 86400) : null;
    $idle = $day($c['last_note_at'] ?? null);
    if (!empty($c['next_followup_at']) && substr((string)$c['next_followup_at'], 0, 10) <= substr($now, 0, 10)) {
        return ['reason' => 'due', 'idle_days' => $idle];
    }
    $active = ($c['active_stage'] ?? '') !== '';
    if ($active && ($idle === null || $idle >= (int)$cfg['active_days'])) {
        return ['reason' => 'active_stage', 'idle_days' => $idle ?? $day($c['first_received_at'] ?? null)];
    }
    $strong = isset($c['best_score']) && $c['best_score'] !== null && (float)$c['best_score'] >= (float)$cfg['strong_score'];
    if ($strong && $idle === null) return ['reason' => 'never', 'idle_days' => $day($c['first_received_at'] ?? null)];
    if ($strong && $idle >= (int)$cfg['pool_days']) return ['reason' => 'strong_idle', 'idle_days' => $idle];
    return null;
}

/** 排序：约好的先、在流程中的次之、再按晾的天数 */
function recruitWarmSort(array &$rows): void {
    $rank = ['due' => 0, 'active_stage' => 1, 'never' => 2, 'strong_idle' => 3];
    usort($rows, fn($a, $b) => [$rank[$a['reason']], -($a['idle_days'] ?? 0), (int)$a['id']]
                            <=> [$rank[$b['reason']], -($b['idle_days'] ?? 0), (int)$b['id']]);
}

/**
 * 某位招聘专员名下需要保温的人（按 recruitWarmSort 排好）。
 * @return array 每项：候选人基本信息 + reason / idle_days / last_note_at / active_stage / best_score / best_job
 */
function recruitWarmList(PDO $pdo, int $ownerId): array {
    $cfg = recruitWarmConfig($pdo);
    $skip = "'" . implode("','", RECRUIT_WARM_SKIP_STATUS) . "'";
    $st = $pdo->prepare("SELECT c.id, c.name, c.status, c.latest_title, c.phone_display, c.phone_key, c.next_followup_at, c.first_received_at,
            (SELECT MAX(f.created_at) FROM recruit_followups f WHERE f.candidate_id=c.id AND f.kind<>'system') last_note_at
        FROM recruit_candidates c WHERE c.owner_user_id=? AND c.status NOT IN ($skip)");
    $st->execute([$ownerId]);
    $cands = array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    if (!$cands) return [];

    // 每人最好的一条匹配 + 最靠后的在途阶段
    $rank = array_flip(RECRUIT_WARM_ACTIVE_STAGES);
    foreach (array_chunk(array_keys($cands), 500) as $ids) {
        $rows = $pdo->query("SELECT cj.candidate_id, cj.stage, COALESCE(cj.human_score, cj.ai_score) sc, j.title job_title, p.name project_name
            FROM recruit_candidate_jobs cj JOIN recruit_jobs j ON j.id=cj.job_id JOIN recruit_projects p ON p.id=j.project_id
            WHERE cj.candidate_id IN (" . implode(',', array_map('intval', $ids)) . ") AND cj.stage<>'removed'
              AND j.status='open' AND p.status='open'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $c = &$cands[(int)$r['candidate_id']];
            if ($r['sc'] !== null && (!isset($c['best_score']) || (float)$r['sc'] > (float)$c['best_score'])) {
                $c['best_score'] = (float)$r['sc'];
                $c['best_job'] = $r['job_title'] . ' · ' . $r['project_name'];
            }
            if (isset($rank[$r['stage']]) && (!isset($c['active_stage']) || $c['active_stage'] === '' || $rank[$r['stage']] > $rank[$c['active_stage']])) {
                $c['active_stage'] = $r['stage'];
                $c['active_job'] = $r['job_title'] . ' · ' . $r['project_name'];
            }
            unset($c);
        }
    }
    $now = date('Y-m-d H:i:s');
    $out = [];
    foreach ($cands as $c) {
        $c += ['best_score' => null, 'best_job' => '', 'active_stage' => '', 'active_job' => ''];
        if ($w = recruitWarmReason($c, $cfg, $now)) $out[] = $c + $w;
    }
    recruitWarmSort($out);
    return $out;
}

/**
 * 招聘专员的固定收简历地址：每个启用的招聘邮箱 × 他的 plus 代码（recruit_sources，管理员分配，本人不能改）。
 * 2026-09-23：地址写死给他，不要让他发到自己邮箱——我们没法维护一堆个人邮箱。
 */
function recruitUserAddresses(PDO $pdo, int $userId): array {
    $st = $pdo->prepare("SELECT code FROM recruit_sources WHERE user_id=? AND active=1 ORDER BY code");
    $st->execute([$userId]);
    $codes = $st->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($pdo->query("SELECT id, username, enabled FROM recruit_mailboxes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if (!preg_match('/^([^@+]+)@(.+)$/', (string)$b['username'], $m)) continue;
        foreach ($codes as $code) $out[] = ['address' => "{$m[1]}+{$code}@{$m[2]}", 'code' => $code, 'enabled' => (int)$b['enabled'] === 1];
    }
    return $out;
}
