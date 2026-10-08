<?php
/**
 * 站内通知：三语模板 + 写 notifications 表 + 列表 / 已读接口。
 *
 * 外部推送（企业微信、Slack、邮件……）不内置：设置 $GLOBALS['oh_notify_push'] = function($pdo,$userId,$title,$content,$link){}
 * 即可挂一个推送钩子，失败不影响站内信。
 */

require_once __DIR__ . '/db_dialect.php';

// 通知模板：每个 key 对应 ['zh' => ['title','content'], 'en' => ..., 'id' => ...]，占位符 {{var}}
function notif_templates(): array {
    return [
        // 招聘高分人选提醒。includes/recruit_alert.php 发起（流水线打完分后），参数 name/job/project/score/owner/title/reason_zh/reason_en/reason_id/gaps
        'recruit_high_score' => [
            // 推荐理由放第一行：企微卡片正文只留 200 字（wecom.php），放后面会被截掉
            'zh' => ['title' => '高分人选：{{name}} · {{job}}（{{score}} 分）',
                     'content' => "推荐理由：{{reason_zh}}\n缺项：{{gaps}}\n候选人：{{name}}（{{code}}，{{title}}），归属 {{owner}}\n项目：{{project}}"],
            'en' => ['title' => 'High-score candidate: {{name}} · {{job}} ({{score}})',
                     'content' => "Why: {{reason_en}}\nGaps: {{gaps}}\nCandidate: {{name}} ({{code}}, {{title}}), owner {{owner}}\nProject: {{project}}"],
            'id' => ['title' => 'Kandidat skor tinggi: {{name}} · {{job}} ({{score}})',
                     'content' => "Alasan: {{reason_id}}\nKekurangan: {{gaps}}\nKandidat: {{name}} ({{code}}, {{title}}), pemilik {{owner}}\nProyek: {{project}}"],
        ],
        // 招聘提示词新版本自动启用。includes/recruit_prompts.php recruitPromptEvaluate 发起，参数 scene/from/to/cases/summary
        'recruit_prompt_promoted' => [
            'zh' => ['title' => 'AI 提示词已自动升级：{{scene}} {{from}} → {{to}}',
                     'content' => "在 {{cases}} 条回归用例上新版本更好，已自动启用。\n指标：{{summary}}\n不满意可在 设置 · 招聘 · AI 提示词 一键回退。"],
            'en' => ['title' => 'AI prompt auto-upgraded: {{scene}} {{from}} → {{to}}',
                     'content' => "The new version did better on {{cases}} regression cases and is now active.\nMetrics: {{summary}}\nRoll back in Settings · Recruitment · AI prompts if needed."],
            'id' => ['title' => 'Prompt AI diperbarui otomatis: {{scene}} {{from}} → {{to}}',
                     'content' => "Versi baru lebih baik pada {{cases}} kasus uji dan sudah aktif.\nMetrik: {{summary}}\nBisa dikembalikan di Pengaturan · Rekrutmen · Prompt AI."],
        ],
        // 招聘保温提醒。scripts/cron_recruit_warm_remind.php 发起，参数 n/due/active/never/idle/names/address
        'recruit_warm' => [
            'zh' => ['title' => '今天有 {{n}} 位候选人需要保温',
                     'content' => '约好今天跟进 {{due}}、流程中久未联系 {{active}}、高分从未联系 {{never}}、高分晾太久 {{idle}}（如 {{names}}）。简历和候选人邮件请发/抄送到你的固定地址 {{address}}，不要发到个人邮箱。'],
            'en' => ['title' => '{{n}} candidates need a check-in today',
                     'content' => 'Due today {{due}}, in process but idle {{active}}, high score never contacted {{never}}, high score idle too long {{idle}} (e.g. {{names}}). Send / CC resumes and candidate emails to your fixed address {{address}}, not your personal mailbox.'],
            'id' => ['title' => '{{n}} kandidat perlu dihubungi hari ini',
                     'content' => 'Jadwal hari ini {{due}}, dalam proses tapi lama tak dihubungi {{active}}, skor tinggi belum pernah dihubungi {{never}}, skor tinggi terlalu lama didiamkan {{idle}} (mis. {{names}}). Kirim / CC CV dan email kandidat ke alamat tetap Anda {{address}}, bukan email pribadi.'],
        ],
        // ===== 招聘通知补漏（2026-09-24 负责人，includes/recruit_notify.php；接收人规则见该文件头注释）=====
        // 阶段变更。recruitNotifyStageChange 发起，参数 name/code/job/project/owner/score/by/from_zh|en|id/to_zh|en|id
        'recruit_stage' => [
            'zh' => ['title' => '{{name}} · {{job}}：{{to_zh}}',
                     'content' => "{{by}} 把阶段从「{{from_zh}}」改为「{{to_zh}}」\n候选人：{{name}}（{{code}}），归属 {{owner}}，分数 {{score}}\n项目：{{project}}"],
            'en' => ['title' => '{{name}} · {{job}}: {{to_en}}',
                     'content' => "{{by}} moved the stage from \"{{from_en}}\" to \"{{to_en}}\"\nCandidate: {{name}} ({{code}}), owner {{owner}}, score {{score}}\nProject: {{project}}"],
            'id' => ['title' => '{{name}} · {{job}}: {{to_id}}',
                     'content' => "{{by}} mengubah tahap dari \"{{from_id}}\" ke \"{{to_id}}\"\nKandidat: {{name}} ({{code}}), pemilik {{owner}}, skor {{score}}\nProyek: {{project}}"],
        ],
        // 候选人转给你。recruitNotifyOwnerChanged 发起，参数 name/code/from/by/best
        'recruit_owner' => [
            'zh' => ['title' => '候选人 {{name}} 已转给你跟进',
                     'content' => "{{by}} 把 {{name}}（{{code}}）从 {{from}} 转给你。最匹配的职位：{{best}}。请尽快联系。"],
            'en' => ['title' => 'Candidate {{name}} is now yours',
                     'content' => "{{by}} moved {{name}} ({{code}}) from {{from}} to you. Best-matching job: {{best}}. Please follow up soon."],
            'id' => ['title' => 'Kandidat {{name}} kini milik Anda',
                     'content' => "{{by}} memindahkan {{name}} ({{code}}) dari {{from}} ke Anda. Posisi paling cocok: {{best}}. Mohon segera dihubungi."],
        ],
        // 推荐信已发客户。recruitNotifyRecoSent 发起，参数 name/code/job/project/client/by
        'recruit_reco_sent' => [
            'zh' => ['title' => '已推荐给客户：{{name}} · {{job}}',
                     'content' => "{{by}} 已把 {{name}}（{{code}}）的推荐材料发给 {{client}}。项目：{{project}}。请跟进客户反馈。"],
            'en' => ['title' => 'Submitted to client: {{name}} · {{job}}',
                     'content' => "{{by}} sent {{name}} ({{code}}) to {{client}}. Project: {{project}}. Please chase the client's feedback."],
            'id' => ['title' => 'Diajukan ke klien: {{name}} · {{job}}',
                     'content' => "{{by}} telah mengirim {{name}} ({{code}}) ke {{client}}. Proyek: {{project}}. Mohon tindak lanjuti umpan balik klien."],
        ],
        // 新简历到了（按归属人汇总）。recruitNotifyDigests 发起，参数 n/names
        'recruit_new_resumes' => [
            'zh' => ['title' => '新到 {{n}} 位候选人的简历',
                     'content' => '如 {{names}}。已解析入库，AI 匹配完成后高分人选会另行提醒。'],
            'en' => ['title' => 'Resumes from {{n}} new candidates',
                     'content' => 'E.g. {{names}}. Parsed and saved; high-score matches will be alerted separately.'],
            'id' => ['title' => 'CV dari {{n}} kandidat baru',
                     'content' => 'Mis. {{names}}. Sudah diurai dan disimpan; kandidat skor tinggi akan diberitahukan terpisah.'],
        ],
        // 待人工处理（汇总）。recruitNotifyDigests 发起，参数 unowned/failed/conflict
        'recruit_attention' => [
            'zh' => ['title' => '招聘待处理：没归属 {{unowned}} · 解析失败 {{failed}} · 手机号冲突 {{conflict}}',
                     'content' => '没归属 = 简历没带专员代码，请分配归属人；解析失败请看原件或重新上传；手机号冲突请核对后合并。'],
            'en' => ['title' => 'Recruitment to-do: unassigned {{unowned}} · parse failed {{failed}} · phone conflict {{conflict}}',
                     'content' => 'Unassigned = resume without a recruiter code, please assign an owner; parse failed: check the original or re-upload; phone conflict: verify and merge.'],
            'id' => ['title' => 'Tugas rekrutmen: tanpa pemilik {{unowned}} · gagal urai {{failed}} · konflik nomor {{conflict}}',
                     'content' => 'Tanpa pemilik = CV tanpa kode rekruter, mohon tetapkan pemilik; gagal urai: cek file asli atau unggah ulang; konflik nomor: verifikasi lalu gabungkan.'],
        ],
        // 系统故障。recruitNotifyDigests 发起，参数 what/error
        'recruit_fault' => [
            'zh' => ['title' => '招聘系统故障：{{what}}',
                     'content' => "错误：{{error}}\n简历可能收不进来或没有打分，请尽快处理。"],
            'en' => ['title' => 'Recruitment system fault: {{what}}',
                     'content' => "Error: {{error}}\nResumes may not be received or scored. Please fix soon."],
            'id' => ['title' => 'Gangguan sistem rekrutmen: {{what}}',
                     'content' => "Error: {{error}}\nCV mungkin tidak masuk atau tidak dinilai. Mohon segera ditangani."],
        ],
        // 保温汇总（给招聘抄送人）。cron_recruit_warm_remind.php 发起，参数 n/by/unowned
        'recruit_warm_digest' => [
            'zh' => ['title' => '今天共 {{n}} 位候选人需要保温',
                     'content' => '按专员：{{by}}；没归属：{{unowned}}。'],
            'en' => ['title' => '{{n}} candidates need a check-in today',
                     'content' => 'By recruiter: {{by}}; unassigned: {{unowned}}.'],
            'id' => ['title' => '{{n}} kandidat perlu dihubungi hari ini',
                     'content' => 'Per rekruter: {{by}}; tanpa pemilik: {{unowned}}.'],
        ],
    ];
}

function notif_render(string $tplKey, array $params, string $lang = 'zh-CN'): array {
    static $cache = null;
    if ($cache === null) $cache = notif_templates();
    $short = strtolower(substr($lang, 0, 2));
    $map = ['zh' => 'zh', 'en' => 'en', 'id' => 'id'];
    $loc = $map[$short] ?? 'zh';
    $tpl = $cache[$tplKey][$loc] ?? $cache[$tplKey]['zh'] ?? null;
    if (!$tpl) return ['title' => $tplKey, 'content' => ''];
    return [
        'title' => notif_interpolate($tpl['title'], $params),
        'content' => notif_interpolate($tpl['content'], $params),
    ];
}

function notif_interpolate(string $s, array $params): string {
    return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($params) {
        return isset($params[$m[1]]) ? (string)$params[$m[1]] : '';
    }, $s);
}


function createNotification($pdo, $userId, $type, $title, $content = '', $linkUrl = '', $refType = '', $refId = 0, $tplKey = '', $tplParams = []) {
    if (!$userId) return false;
    if (!isNotifEnabled($pdo, $type)) return false;

    // 传了模板 key：按收件人语言渲染标题 / 正文快照（DB 同时存 key + 参数，前端可按当前语言重渲染）
    if ($tplKey !== '') {
        $userLang = 'zh-CN';
        try {
            $ls = $pdo->prepare("SELECT lang FROM users WHERE id=?");
            $ls->execute([(int)$userId]);
            $userLang = (string)($ls->fetchColumn() ?: 'zh-CN');
        } catch (Exception $e) {}
        $rendered = notif_render($tplKey, $tplParams, $userLang);
        $title = $rendered['title'];
        $content = $rendered['content'];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, content, link_url, ref_type, ref_id, template_key, template_params, created_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, (" . dbNow() . "))");
        $stmt->execute([(int)$userId, $type, $title, $content, $linkUrl, $refType, (int)$refId,
            $tplKey, $tplKey !== '' ? json_encode($tplParams, JSON_UNESCAPED_UNICODE) : '']);
        $newId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('createNotification failed: ' . $e->getMessage());
        return false;
    }

    $push = $GLOBALS['oh_notify_push'] ?? null;
    if (is_callable($push)) {
        try { $push($pdo, (int)$userId, $title, $content, $linkUrl); }
        catch (Throwable $e) { error_log('notify push failed: ' . $e->getMessage()); }
    }
    return $newId;
}

function handleGetNotifications($pdo) {
    $userId = verifyToken();
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $onlyUnread = (int)($_GET['unread'] ?? 0) === 1;
    $sql = "SELECT id, type, title, content, link_url, ref_type, ref_id, is_read, created_at, template_key, template_params FROM notifications WHERE user_id = ?";
    if ($onlyUnread) $sql .= " AND is_read = 0";
    $sql .= " ORDER BY id DESC LIMIT " . $limit;
    $s = $pdo->prepare($sql);
    $s->execute([$userId]);
    $rows = $s->fetchAll();
    // unread count
    $c = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $c->execute([$userId]);
    jsonResponse(['success' => true, 'data' => $rows, 'unreadCount' => (int)$c->fetchColumn()]);
}

function handleGetUnreadNotificationCount($pdo) {
    $userId = verifyToken();
    $c = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $c->execute([$userId]);
    jsonResponse(['success' => true, 'count' => (int)$c->fetchColumn()]);
}

function handleMarkNotificationRead($pdo, $input) {
    $userId = verifyToken();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'errorMessage' => 'id required'], 400);
    $s = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = (" . dbNow() . ") WHERE id = ? AND user_id = ?");
    $s->execute([$id, $userId]);
    jsonResponse(['success' => true]);
}

function handleMarkAllNotificationsRead($pdo) {
    $userId = verifyToken();
    $s = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = (" . dbNow() . ") WHERE user_id = ? AND is_read = 0");
    $s->execute([$userId]);
    jsonResponse(['success' => true]);
}
