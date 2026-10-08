<?php
/**
 * OpenHunter · 通知统一出口（2026-09-24「一定要及时给 HR 通知……也要给负责人通知」）
 *
 * 招聘里所有站内信 + 企微都从这里发：谁收、发没发过（recruit_notify_log 去重）、发给谁都在一处决定。
 * 接收人三类：
 *   归属人      recruit_candidates.owner_user_id（候选人的招聘专员）
 *   项目负责人  recruit_projects.manager_user_id
 *   抄送人      system_settings `recruit.notify.cc_user_ids`（没保存过 = 按 username 找负责人），与高分提醒的「固定接收人」分开存，
 *              改高分设置不会把他丢掉
 * 负责人（抄送人）只收关键事件（用户 2026-09-24 选定）：高分人选、面试 / Offer / 录用、系统故障、保温汇总日报。
 * 新简历、被拒、推荐信已发这些常规事件只发给经手的 HR / 负责人。
 *
 * 事件一览（通知类型 = 模板 key，在「设置 · 通知配置」里都能单独关）：
 *   recruit_high_score     高分人选            归属人 + 固定接收人 + 抄送人        recruit_alert.php
 *   recruit_stage          阶段变更            归属人 + 项目负责人（+ 抄送人：面试/Offer/录用）  recruitNotifyStageChange
 *   recruit_owner          候选人转给你         新归属人                            recruitNotifyOwnerChanged
 *   recruit_reco_sent      推荐信已发客户       项目负责人 + 归属人                  recruitNotifyRecoSent
 *   recruit_new_resumes    新简历到了（汇总）    归属人；没归属的发招聘管理员          recruitNotifyDigests
 *   recruit_attention      待人工处理（汇总）    招聘管理员：解析失败 / 手机号冲突 / 没归属  recruitNotifyDigests
 *   recruit_fault          系统故障            招聘管理员 + 抄送人，同一故障 6 小时一次   recruitNotifyDigests
 *   recruit_warm / recruit_warm_digest  保温    归属人各自一条 + 抄送人一条汇总      cron_recruit_warm_remind.php
 * 操作人自己不收自己触发的通知。
 *
 * ⛔ 汇总类（新简历 / 待处理 / 故障）靠 recruit_notify_log 去重；表没建（迁移未跑）就不发，宁可漏也不每 5 分钟重复轰炸。
 *    建表：scripts/data-fixes/create_recruit_notify_log_20260924.php
 */

require_once __DIR__ . '/recruit_code.php';
require_once __DIR__ . '/db_dialect.php';
require_once __DIR__ . '/host.php';

/** 抄送人会收到的阶段变更 */
const RECRUIT_NOTIFY_CC_STAGES = ['interviewing', 'offered', 'hired'];
/** 会通知的阶段变更（AI 推荐 / 初筛 / 移除是日常操作，不发） */
const RECRUIT_NOTIFY_STAGES = ['submitted', 'interviewing', 'offered', 'hired', 'rejected', 'withdrawn'];
/** 汇总类只看这么久以内的新事件：首次上线不会把历史全推一遍 */
const RECRUIT_NOTIFY_LOOKBACK_HOURS = 24;
/** 同一故障的重复提醒间隔 */
const RECRUIT_NOTIFY_FAULT_HOURS = 6;

/** 通知里的阶段名（与前端 pages.recruit.stage.* 一致；通知模板只做占位替换，翻译在这里给三份） */
const RECRUIT_NOTIFY_STAGE_LABELS = [
    'suggested' => ['AI 推荐', 'AI suggested', 'Saran AI'],
    'shortlisted' => ['初筛通过', 'Shortlisted', 'Lolos seleksi awal'],
    'submitted' => ['已推给客户', 'Submitted', 'Diajukan ke klien'],
    'interviewing' => ['面试中', 'Interviewing', 'Wawancara'],
    'offered' => ['已发 Offer', 'Offered', 'Ditawarkan'],
    'hired' => ['已录用', 'Hired', 'Diterima'],
    'rejected' => ['未通过', 'Rejected', 'Ditolak'],
    'withdrawn' => ['候选人放弃', 'Withdrawn', 'Mundur'],
    'removed' => ['已移除', 'Removed', 'Dihapus'],
];

/** 测试注入：fn(int $userId, string $type, array $params, string $link): void。null = 真发（createNotification） */
function recruitNotifySetHook(?callable $hook): void { $GLOBALS['__recruitNotifyHook'] = $hook; }

/** 抄送人（默认系统管理员）。$ids 显式传 [] 表示「不抄送任何人」，与「从没设置过」区分开 */
function recruitNotifyCcIds(PDO $pdo): array {
    $saved = null;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='recruit.notify.cc_user_ids'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v !== false) $saved = json_decode((string)$v, true);
    } catch (Throwable $e) {}
    if (is_array($saved)) return array_values(array_unique(array_filter(array_map('intval', $saved))));
    return recruitHostDefaultWatchers($pdo);
}

/**
 * 招聘管理员：有 recruit_admin 模块的在职员工，**不含**只因系统管理员角色而有权限的人
 * （IT / 老板账号不该每天收解析失败）。一个都没有时由调用方兜底发抄送人。
 */
function recruitNotifyAdminIds(PDO $pdo): array {
    if (!function_exists('userHasModule')) return [];
    $out = [];
    foreach ($pdo->query("SELECT id FROM users WHERE status='active' AND role<>'admin'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (userHasModule($pdo, (int)$id, 'recruit_admin')) $out[] = (int)$id;
    }
    return $out;
}

function recruitNotifyLogReady(PDO $pdo): bool {
    static $ok = [];
    $k = spl_object_id($pdo);
    if (!isset($ok[$k])) {
        try { $pdo->query("SELECT 1 FROM recruit_notify_log LIMIT 1"); $ok[$k] = true; } catch (Throwable $e) { $ok[$k] = false; }
    }
    return $ok[$k];
}

/** 占位：这个 (事件, 键, 人) 第一次出现返回 true 并记下；发过的返回 false。表没建返回 false（不发） */
function recruitNotifyOnce(PDO $pdo, string $event, string $key, int $userId = 0): bool {
    if (!recruitNotifyLogReady($pdo)) return false;
    try {
        $pdo->prepare("INSERT INTO recruit_notify_log (event, dedupe_key, user_id, created_at) VALUES (?, ?, ?, (" . dbNow() . "))")
            ->execute([$event, mb_substr($key, 0, 191), $userId]);
        return true;
    } catch (PDOException $e) {
        if (dbIsDuplicateKeyError($e)) return false;
        throw $e;
    }
}

/**
 * 发给一批人（去重、去 0、去操作人本人）。@return int 实际发出的条数
 */
function recruitNotify(PDO $pdo, string $type, array $userIds, array $params, string $link, string $refType = '', int $refId = 0, int $exclude = 0): int {
    $to = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($u) => $u > 0 && $u !== $exclude)));
    $hook = $GLOBALS['__recruitNotifyHook'] ?? null;
    foreach ($to as $uid) {
        if ($hook) { $hook($uid, $type, $params, $link); continue; }
        if (function_exists('createNotification')) createNotification($pdo, $uid, $type, '', '', $link, $refType, $refId, $type, $params);
    }
    return count($to);
}

function recruitNotifyStageParams(string $stage, string $prefix): array {
    $l = RECRUIT_NOTIFY_STAGE_LABELS[$stage] ?? [$stage, $stage, $stage];
    return ["{$prefix}_zh" => $l[0], "{$prefix}_en" => $l[1], "{$prefix}_id" => $l[2]];
}

/** 一条「人 × 职位」的上下文：候选人、职位、项目（带客户群名）、归属人、项目负责人 */
function recruitNotifyLinkCtx(PDO $pdo, int $linkId): ?array {
    $st = $pdo->prepare("SELECT cj.id, cj.candidate_id, cj.job_id, COALESCE(cj.human_score, cj.ai_score) score,
            c.name cand_name, c.owner_user_id, c.owner_user_name, j.title job_title, p.id project_id, p.name project_name,
            COALESCE(p.manager_user_id,0) manager_user_id, COALESCE(cu.name,'') group_name
        FROM recruit_candidate_jobs cj JOIN recruit_candidates c ON c.id=cj.candidate_id
        JOIN recruit_jobs j ON j.id=cj.job_id JOIN recruit_projects p ON p.id=j.project_id
        LEFT JOIN recruit_clients cu ON cu.id=p.customer_id WHERE cj.id=?");
    $st->execute([$linkId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $cid = (int)$r['candidate_id'];
    $r['params'] = [
        'cid' => $cid, 'code' => recruitCandCode($cid), 'name' => $r['cand_name'] ?: recruitCandCode($cid),
        'job' => $r['job_title'], 'project' => $r['group_name'] !== '' ? "{$r['project_name']} · {$r['group_name']}" : $r['project_name'],
        'owner' => $r['owner_user_name'] ?: '-', 'score' => $r['score'] !== null ? number_format((float)$r['score'], 1) : '-',
    ];
    $r['link'] = "/recruit/talent?project_id={$r['project_id']}&job_id={$r['job_id']}";
    return $r;
}

/** 阶段变更（人工跟进里改阶段、推荐信标记已发自动推进都走这里）。@return int 发出条数 */
function recruitNotifyStageChange(PDO $pdo, int $linkId, string $before, string $after, int $actorId, string $actorName): int {
    if ($before === $after || !in_array($after, RECRUIT_NOTIFY_STAGES, true)) return 0;
    $ctx = recruitNotifyLinkCtx($pdo, $linkId);
    if (!$ctx) return 0;
    $to = [(int)$ctx['owner_user_id'], (int)$ctx['manager_user_id']];
    if (in_array($after, RECRUIT_NOTIFY_CC_STAGES, true)) $to = array_merge($to, recruitNotifyCcIds($pdo));
    $params = $ctx['params'] + ['by' => $actorName ?: '-'] + recruitNotifyStageParams($before, 'from') + recruitNotifyStageParams($after, 'to');
    return recruitNotify($pdo, 'recruit_stage', $to, $params, $ctx['link'], 'recruit_candidate', (int)$ctx['candidate_id'], $actorId);
}

/**
 * 候选人转给新归属人（手动指定）：告诉他接手了谁、这人手上有几个高分职位，让他马上跟进。
 * 高分提醒是「每个人 × 职位一次」、已经发给了原归属人；新归属人靠这条补上。
 */
function recruitNotifyOwnerChanged(PDO $pdo, int $cid, int $newOwner, string $fromName, int $actorId, string $actorName): int {
    if ($newOwner <= 0) return 0;
    $st = $pdo->prepare("SELECT name FROM recruit_candidates WHERE id=?");
    $st->execute([$cid]);
    $name = $st->fetchColumn();
    if ($name === false) return 0;
    $st = $pdo->prepare("SELECT j.title, p.name project_name, COALESCE(cj.human_score, cj.ai_score) sc
        FROM recruit_candidate_jobs cj JOIN recruit_jobs j ON j.id=cj.job_id AND j.status='open'
        JOIN recruit_projects p ON p.id=j.project_id AND p.status='open'
        WHERE cj.candidate_id=? AND cj.stage NOT IN ('removed','rejected','withdrawn')
        ORDER BY COALESCE(cj.human_score, cj.ai_score) DESC LIMIT 1");
    $st->execute([$cid]);
    $best = $st->fetch(PDO::FETCH_ASSOC);
    $params = ['cid' => $cid, 'code' => recruitCandCode($cid), 'name' => $name ?: recruitCandCode($cid),
               'from' => $fromName ?: '-', 'by' => $actorName ?: '-',
               'best' => $best ? "{$best['title']} · {$best['project_name']}" . ($best['sc'] !== null ? '（' . number_format((float)$best['sc'], 1) . '）' : '') : '-'];
    return recruitNotify($pdo, 'recruit_owner', [$newOwner], $params, "/recruit/talent?candidate_id=$cid", 'recruit_candidate', $cid, $actorId);
}

/** 推荐信标记已发给客户 */
function recruitNotifyRecoSent(PDO $pdo, int $cid, int $jobId, string $client, int $actorId, string $actorName): int {
    $st = $pdo->prepare("SELECT id FROM recruit_candidate_jobs WHERE candidate_id=? AND job_id=?");
    $st->execute([$cid, $jobId]);
    $lid = (int)$st->fetchColumn();
    $ctx = $lid ? recruitNotifyLinkCtx($pdo, $lid) : null;
    if (!$ctx) return 0;
    $params = $ctx['params'] + ['client' => $client !== '' ? $client : $ctx['params']['project'], 'by' => $actorName ?: '-'];
    return recruitNotify($pdo, 'recruit_reco_sent', [(int)$ctx['owner_user_id'], (int)$ctx['manager_user_id']], $params, $ctx['link'],
        'recruit_candidate', $cid, $actorId);
}

/** 一串名字：前 3 个 + 「等 N 人」由模板里的 {{n}} 表达 */
function recruitNotifyNames(array $rows): string {
    return implode('、', array_map(fn($r) => ($r['name'] ?? '') !== '' ? $r['name'] : recruitCandCode((int)$r['cid']), array_slice($rows, 0, 3)));
}

/**
 * 汇总类通知（cron_recruit_notify.php 每 5 分钟跑）：新简历、待人工处理、系统故障。
 * 每个事件用 recruit_notify_log 占位，只发一次；只看最近 RECRUIT_NOTIFY_LOOKBACK_HOURS 小时的。
 * @return array 各类发出条数
 */
function recruitNotifyDigests(PDO $pdo, ?int $now = null): array {
    $rep = ['new_resumes' => 0, 'attention' => 0, 'fault' => 0, 'skipped' => false];
    if (!recruitNotifyLogReady($pdo)) { $rep['skipped'] = true; return $rep; }
    $now ??= time();
    $since = date('Y-m-d H:i:s', $now - RECRUIT_NOTIFY_LOOKBACK_HOURS * 3600);
    // 去重记录只需覆盖回看窗口；留 7 天足够，防表无限长
    $pdo->prepare("DELETE FROM recruit_notify_log WHERE created_at < ?")->execute([date('Y-m-d H:i:s', $now - 7 * 86400)]);
    $admins = recruitNotifyAdminIds($pdo);
    $cc = recruitNotifyCcIds($pdo);
    $adminsOrCc = $admins ?: $cc;

    // ① 新简历：按归属人各发一条「新到 N 位」；没归属的（没带专员代码）进 ② 让管理员分配
    $st = $pdo->prepare("SELECT r.id rid, c.id cid, c.name, c.owner_user_id FROM recruit_resumes r JOIN recruit_candidates c ON c.id=r.candidate_id
        WHERE r.parse_status='parsed' AND r.doc_type='cv' AND r.parsed_at >= ? ORDER BY r.parsed_at, r.id");
    $st->execute([$since]);
    $byOwner = [];
    $cnt = ['failed' => 0, 'conflict' => 0, 'unowned' => 0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!recruitNotifyOnce($pdo, 'new_resume', 'r' . $r['rid'])) continue;
        if ((int)$r['owner_user_id'] <= 0) { $cnt['unowned']++; continue; }
        $byOwner[(int)$r['owner_user_id']][(int)$r['cid']] = $r;   // 同一个人多份简历只算一次
    }
    foreach ($byOwner as $owner => $rows) {
        $rows = array_values($rows);
        $rep['new_resumes'] += recruitNotify($pdo, 'recruit_new_resumes', [$owner], ['n' => count($rows), 'names' => recruitNotifyNames($rows)], '/recruit/workbench');
    }

    // ② 待人工处理：没归属的新简历、解析彻底失败、手机号冲突待合并
    $st = $pdo->prepare("SELECT id FROM recruit_resumes WHERE parse_status='failed' AND updated_at >= ?");
    $st->execute([$since]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) if (recruitNotifyOnce($pdo, 'parse_failed', 'r' . $id)) $cnt['failed']++;
    $st = $pdo->prepare("SELECT id FROM recruit_candidates WHERE review_flags LIKE '%,phone_conflict,%' AND updated_at >= ?");
    $st->execute([$since]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) if (recruitNotifyOnce($pdo, 'phone_conflict', 'c' . $id)) $cnt['conflict']++;
    if (array_sum($cnt) > 0) $rep['attention'] += recruitNotify($pdo, 'recruit_attention', $adminsOrCc, $cnt, '/recruit/resumes');

    // ③ 系统故障：邮箱登录/同步出错、流水线失败或中止。同一故障每 RECRUIT_NOTIFY_FAULT_HOURS 小时最多一次
    $bucket = (int)floor($now / (RECRUIT_NOTIFY_FAULT_HOURS * 3600));
    $faultTo = array_merge($admins, $cc);
    foreach ($pdo->query("SELECT id, name, username, last_error FROM recruit_mailboxes WHERE enabled=1 AND COALESCE(last_error,'')<>''")->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if (!recruitNotifyOnce($pdo, 'fault_mailbox', "mb{$b['id']}:$bucket")) continue;
        $rep['fault'] += recruitNotify($pdo, 'recruit_fault', $faultTo,
            ['what' => ($b['name'] ?: $b['username']) ?: "#{$b['id']}", 'error' => mb_substr((string)$b['last_error'], 0, 120)], '/recruit/resumes');
    }
    try {
        $st = $pdo->prepare("SELECT id, status, summary_json FROM recruit_pipeline_runs WHERE status IN ('failed','aborted') AND finished_at >= ? ORDER BY id DESC");
        $st->execute([date('Y-m-d H:i:s', $now - RECRUIT_NOTIFY_FAULT_HOURS * 3600)]);
        $runs = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $runs = []; }
    if ($runs && recruitNotifyOnce($pdo, 'fault_pipeline', "run:$bucket")) {
        $s = json_decode((string)$runs[0]['summary_json'], true) ?: [];
        $why = (string)($s['abort'] ?? $s['error'] ?? $runs[0]['status']);
        $rep['fault'] += recruitNotify($pdo, 'recruit_fault', $faultTo,
            ['what' => 'AI pipeline × ' . count($runs), 'error' => mb_substr($why, 0, 120)], '/recruit/resumes');
    }
    return $rep;
}

/** 今天是不是印尼公休日（holiday_cache，「设置 · 节假日」拉取的数据；没拉过就当工作日） */
function recruitNotifyIsHoliday(PDO $pdo, ?string $date = null): bool {
    $date ??= date('Y-m-d');
    try {
        $st = $pdo->prepare("SELECT 1 FROM holiday_cache WHERE country='ID' AND holiday_date=? AND type='holiday' LIMIT 1");
        $st->execute([$date]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
