<?php
/**
 * OpenHunter · 高分人选提醒（2026-09-24「高分人选自动企微提醒负责人和对应的 HR，推荐理由也要给我」）。
 *
 * 流水线打完分后执行（cron_recruit_parse.php 第 ⑥ 步）：
 *   找出 分数 ≥ 阈值 且还没提醒过（alerted_at 为空）的「人 × 职位」，给 ① 候选人归属的招聘专员 ② 设置里的固定接收人（默认负责人）
 *   各发一条站内信 + 企微：候选人、职位、项目、分数、推荐理由（按接收人语言）、缺项。每个组合只提醒一次。
 * 只提醒系统里在招的职位（职位「招聘中」+ 项目「进行中」），已关闭/暂停的不提醒。
 * 打开开关那一刻之前打的分不补发（since），免得一开就把历史高分全推一遍。
 * 配置：system_settings `recruit.alert.config` = {"enabled":bool,"threshold":4,"user_ids":[..],"since":"Y-m-d H:i:s"}，
 *       在「系统设置 · 招聘设置 · 高分提醒」里改。
 * 另外永远抄送「招聘抄送人」（recruitNotifyCcIds，默认负责人）——与这里的固定接收人分开存，改高分设置不会把他丢掉（2026-09-24 补漏）。
 * 触发点：流水线第 ⑥ 步；cron_recruit_notify.php（自动解析关着 / 超预算时流水线提前退出，靠它兜底）；人工改分后立即（$onlyLinkId）。
 */

require_once __DIR__ . '/recruit_code.php';
require_once __DIR__ . '/recruit_notify.php';
require_once __DIR__ . '/host.php';

const RECRUIT_ALERT_DEFAULTS = ['enabled' => false, 'threshold' => 4.0, 'user_ids' => [], 'since' => ''];

function recruitAlertConfig(PDO $pdo): array {
    $cfg = RECRUIT_ALERT_DEFAULTS;
    $saved = false;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='recruit.alert.config'");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
        if (is_array($j)) { $cfg = array_replace($cfg, array_intersect_key($j, $cfg)); $saved = true; }
    } catch (Throwable $e) {}
    if (!$saved) {   // 从没保存过：固定接收人默认系统管理员
        $cfg['user_ids'] = recruitHostDefaultWatchers($pdo);
    }
    $cfg['user_ids'] = array_values(array_unique(array_filter(array_map('intval', (array)$cfg['user_ids']))));
    $cfg['threshold'] = (float)$cfg['threshold'];
    return $cfg;
}

/**
 * 发提醒。$notify = fn(int $userId, array $params, string $link): void（默认 createNotification；测试注入假的，避免真发企微）
 * @return array{enabled:bool, links:int, messages:int}
 */
function recruitHighScoreAlerts(PDO $pdo, ?callable $notify = null, int $limit = 50, int $onlyLinkId = 0): array {
    $cfg = recruitAlertConfig($pdo);
    $rep = ['enabled' => (bool)$cfg['enabled'], 'links' => 0, 'messages' => 0];
    if (!$cfg['enabled']) return $rep;
    $notify ??= function (int $uid, array $p, string $link) use ($pdo) {
        if (function_exists('createNotification')) {
            createNotification($pdo, $uid, 'recruit_high_score', '', '', $link, 'recruit_candidate', (int)$p['cid'], 'recruit_high_score', $p);
        }
    };
    $since = (string)$cfg['since'];
    $cc = recruitNotifyCcIds($pdo);
    $st = $pdo->prepare("SELECT cj.id, cj.candidate_id, cj.job_id, cj.ai_score, cj.human_score, cj.ai_reason, cj.ai_gaps,
            c.name cand_name, c.owner_user_id, c.owner_user_name, c.latest_title, c.years_exp,
            j.title job_title, p.id project_id, p.name project_name, COALESCE(cu.name,'') group_name
        FROM recruit_candidate_jobs cj
        JOIN recruit_candidates c ON c.id=cj.candidate_id
        JOIN recruit_jobs j ON j.id=cj.job_id AND j.status='open'
        JOIN recruit_projects p ON p.id=j.project_id AND p.status='open'
        LEFT JOIN recruit_clients cu ON cu.id=p.customer_id
        WHERE cj.alerted_at IS NULL AND cj.stage NOT IN ('removed','rejected','withdrawn','hired')
          AND c.status<>'blacklisted'
          AND COALESCE(cj.human_score, cj.ai_score) >= CAST(? AS DECIMAL(3,1))
          " . ($since !== '' ? "AND COALESCE(cj.human_at, cj.ai_at) >= ?" : '') . "
          " . ($onlyLinkId > 0 ? 'AND cj.id=' . $onlyLinkId : '') . "
        ORDER BY COALESCE(cj.human_score, cj.ai_score) DESC, cj.id LIMIT " . (int)$limit);
    $st->execute($since !== '' ? [(string)$cfg['threshold'], $since] : [(string)$cfg['threshold']]);
    $mark = $pdo->prepare("UPDATE recruit_candidate_jobs SET alerted_at=(" . dbNow() . ") WHERE id=? AND alerted_at IS NULL");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // 先占位再发：并发两轮也不会重复提醒
        $mark->execute([(int)$r['id']]);
        if ($mark->rowCount() === 0) continue;
        $reason = json_decode((string)$r['ai_reason'], true) ?: [];
        $gaps = json_decode((string)$r['ai_gaps'], true) ?: [];
        $score = $r['human_score'] !== null ? (float)$r['human_score'] : (float)$r['ai_score'];
        $params = [
            'cid' => (int)$r['candidate_id'], 'code' => recruitCandCode((int)$r['candidate_id']),
            'name' => $r['cand_name'] ?: recruitCandCode((int)$r['candidate_id']),
            'job' => $r['job_title'], 'project' => $r['group_name'] !== '' ? "{$r['project_name']} · {$r['group_name']}" : $r['project_name'],
            'score' => number_format($score, 1), 'owner' => $r['owner_user_name'] ?: '-',
            'title' => trim(($r['latest_title'] ?: '') . ($r['years_exp'] !== null ? " · {$r['years_exp']}y" : '')),
            'reason_zh' => $reason['zh'] ?? '', 'reason_en' => $reason['en'] ?? '', 'reason_id' => $reason['id'] ?? '',
            'gaps' => $gaps ? implode('；', array_slice($gaps, 0, 3)) : '-',
        ];
        $to = array_unique(array_filter(array_merge([(int)$r['owner_user_id']], $cfg['user_ids'], $cc)));
        $link = "/recruit/talent?project_id={$r['project_id']}&job_id={$r['job_id']}";
        foreach ($to as $uid) { $notify((int)$uid, $params, $link); $rep['messages']++; }
        $rep['links']++;
    }
    return $rep;
}
