<?php
/**
 * 【建表】OpenHunter：邮箱 / 来源 / 邮件 / 项目 / 职位 / 候选人 / 简历 / 匹配 / 跟进
 *
 * 背景（2026-09-23）：客户找我们做猎头/招聘 → 建招聘项目（关联客户/商机，下挂多个职位 JD）。
 * 招聘专员把共用 Gmail 的 plus 地址（<base>+cl@ / +fr@ / +el@）发到各平台，简历自动进来：
 * 先存 OSS → Gemini 客观拆字段 → 按手机号识别为同一个人 → 自动匹配所有开放职位（一人可对多岗）→ 跟进。
 * 候选人归属 = 最先收到他的招聘专员（先到先得）。
 * 方案：docs/plans/RECRUITMENT_20260923.md 与 ~/.claude/plans 里批准的 P2/P3 计划。
 *
 * 表关系：
 *   recruit_projects 1─N recruit_jobs
 *   recruit_candidates（一个人，phone_key 唯一）1─N recruit_resumes（一个收到的文件）
 *   recruit_candidates N─N recruit_jobs  经 recruit_candidate_jobs（AI 分 + 人工分 + 阶段）
 *   recruit_candidates 1─N recruit_followups（跟进记录 + 系统事件 = 时间线）
 *
 * ⛔ 不复用签证的 mailbox_messages / mailbox_attachments，签证链路零改动。
 * ⛔ recruit_candidates.phone_key 无号码时必须是 NULL 不能是 ''——
 *    UNIQUE 索引允许多个 NULL，但第二个 '' 会撞键。所有写入走 recruitPhoneKeyOrNull()。
 * ⛔ 排序规则显式 utf8mb4_unicode_ci（不写会落 0900_ai_ci，JOIN 既有表报 1267）。
 * ⛔ 唯一索引不 try/catch（§7.9）：新表不可能有冲突数据，建失败就是真错。
 *
 * P1（2026-09-23 早些时候）建过旧结构：recruit_candidates 是「一份简历一行」、recruit_jobs 带 client_name。
 * 生产从未执行过 P1。本脚本识别旧结构：**表为空才 DROP 重建，有数据 exit 1**。
 *
 * 执行：
 *   php scripts/data-fixes/create_recruit_tables_20260923.php            # dry-run
 *   php scripts/data-fixes/create_recruit_tables_20260923.php --apply
 */

$root = dirname(__DIR__, 2);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$pdo   = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$my    = dbIsMysql();
$tail  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
$pk    = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$str   = fn(int $n) => $my ? "VARCHAR($n)" : 'TEXT';
$txt   = 'TEXT';
$big   = $my ? 'MEDIUMTEXT' : 'TEXT';
$dec   = fn(string $p) => $my ? "DECIMAL($p)" : 'REAL';
$chr   = fn(int $n) => $my ? "CHAR($n)" : 'TEXT';
$blob  = $my ? 'MEDIUMBLOB' : 'BLOB';

echo "=== OpenHunter 建表 " . ($apply ? '[APPLY]' : '[DRY-RUN]') . " ===\n\n";

$tables = [
    // —— P1 起就有、结构不变 ——
    'recruit_mailboxes' => "CREATE TABLE recruit_mailboxes (
        id $pk,
        name {$str(100)} NOT NULL DEFAULT '',
        imap_host {$str(191)} NOT NULL DEFAULT 'imap.gmail.com',
        imap_port INT NOT NULL DEFAULT 993,
        imap_ssl INT NOT NULL DEFAULT 1,
        username {$str(191)} NOT NULL DEFAULT '',
        password_enc $txt,
        folder {$str(100)} NOT NULL DEFAULT 'INBOX',
        enabled INT NOT NULL DEFAULT 0,
        last_uid INT NOT NULL DEFAULT 0,
        last_sync_at DATETIME NULL,
        last_error $txt,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",

    'recruit_sources' => "CREATE TABLE recruit_sources (
        id $pk,
        code {$str(32)} NOT NULL,
        user_id INT NOT NULL DEFAULT 0,
        user_name {$str(191)} NOT NULL DEFAULT '',
        active INT NOT NULL DEFAULT 1,
        created_at DATETIME NULL
    )$tail",

    'recruit_messages' => "CREATE TABLE recruit_messages (
        id $pk,
        mailbox_id INT NOT NULL,
        imap_uid INT NOT NULL,
        message_id {$str(255)} NOT NULL DEFAULT '',
        subject $txt,
        from_addr {$str(255)} NOT NULL DEFAULT '',
        to_addr {$str(255)} NOT NULL DEFAULT '',
        plus_code {$str(32)} NOT NULL DEFAULT '',
        source_id INT NOT NULL DEFAULT 0,
        received_at DATETIME NULL,
        body_text $big,
        attachment_count INT NOT NULL DEFAULT 0,
        sender_check {$str(16)} NOT NULL DEFAULT '',
        created_at DATETIME NULL
    )$tail",

    // —— 项目与职位 ——
    'recruit_projects' => "CREATE TABLE recruit_projects (
        id $pk,
        name {$str(191)} NOT NULL DEFAULT '',
        kind {$str(16)} NOT NULL DEFAULT 'client',
        customer_id INT NOT NULL DEFAULT 0,
        opportunity_id INT NOT NULL DEFAULT 0,
        status {$str(16)} NOT NULL DEFAULT 'open',
        manager_user_id INT NOT NULL DEFAULT 0,
        description $txt,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",

    /* jd_rev：title/jd/location 变更或重新开放时 +1，匹配 worker 据此重评。
       jd_requirements_json：JD 拆出的要求 [{text, level: core|nice}]，同一职位对所有人用同一把尺子；
       jd_req_source='manual' 表示 HR 改过，AI 不再覆盖；jd_req_rev = 拆要求时对应的 jd_rev。 */
    'recruit_jobs' => "CREATE TABLE recruit_jobs (
        id $pk,
        project_id INT NOT NULL,
        title {$str(191)} NOT NULL DEFAULT '',
        location {$str(191)} NOT NULL DEFAULT '',
        employment_type {$str(32)} NOT NULL DEFAULT '',
        headcount INT NOT NULL DEFAULT 1,
        salary_text {$str(191)} NOT NULL DEFAULT '',
        jd_text $big,
        status {$str(16)} NOT NULL DEFAULT 'open',
        jd_rev INT NOT NULL DEFAULT 1,
        jd_requirements_json $big,
        jd_req_rev INT NOT NULL DEFAULT 0,
        jd_req_source {$str(8)} NOT NULL DEFAULT '',
        jd_req_attempts INT NOT NULL DEFAULT 0,
        jd_req_retry_at DATETIME NULL,
        jd_req_error $txt,
        vec_rev INT NOT NULL DEFAULT 0,
        posting_json $big,
        posted_json $txt,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",

    // —— 人 ——
    'recruit_candidates' => "CREATE TABLE recruit_candidates (
        id $pk,
        phone_key {$str(32)} NULL,
        phone_display {$str(64)} NOT NULL DEFAULT '',
        email_key {$str(191)} NULL,
        name {$str(191)} NOT NULL DEFAULT '',
        gender {$str(8)} NOT NULL DEFAULT '',
        birth_date {$str(10)} NOT NULL DEFAULT '',
        city {$str(100)} NOT NULL DEFAULT '',
        province {$str(100)} NOT NULL DEFAULT '',
        country {$str(64)} NOT NULL DEFAULT '',
        nationality {$str(64)} NOT NULL DEFAULT '',
        highest_edu {$str(8)} NOT NULL DEFAULT '',
        latest_school {$str(191)} NOT NULL DEFAULT '',
        latest_major {$str(191)} NOT NULL DEFAULT '',
        latest_title {$str(191)} NOT NULL DEFAULT '',
        latest_company {$str(191)} NOT NULL DEFAULT '',
        years_exp {$dec('4,1')} NULL,
        industries {$str(255)} NOT NULL DEFAULT '',
        languages_text {$str(255)} NOT NULL DEFAULT '',
        religion {$str(40)} NOT NULL DEFAULT '',
        religion_code {$str(16)} NOT NULL DEFAULT '',
        marital_status {$str(40)} NOT NULL DEFAULT '',
        marital_code {$str(16)} NOT NULL DEFAULT '',
        ethnicity {$str(40)} NOT NULL DEFAULT '',
        resume_lang {$str(8)} NOT NULL DEFAULT '',
        lang_codes {$str(64)} NOT NULL DEFAULT '',
        languages_json $txt,
        search_text $txt,
        profile_json $big,
        profile_resume_id INT NOT NULL DEFAULT 0,
        profile_rev INT NOT NULL DEFAULT 0,
        profile_hash {$chr(40)} NOT NULL DEFAULT '',
        locked_fields $txt,
        owner_user_id INT NOT NULL DEFAULT 0,
        owner_user_name {$str(191)} NOT NULL DEFAULT '',
        owner_source_id INT NOT NULL DEFAULT 0,
        owner_resume_id INT NOT NULL DEFAULT 0,
        owner_set_by {$str(8)} NOT NULL DEFAULT '',
        owner_set_at DATETIME NULL,
        status {$str(16)} NOT NULL DEFAULT 'new',
        status_changed_at DATETIME NULL,
        last_followup_at DATETIME NULL,
        next_followup_at DATETIME NULL,
        first_received_at DATETIME NULL,
        last_received_at DATETIME NULL,
        resume_count INT NOT NULL DEFAULT 0,
        review_flags {$str(191)} NOT NULL DEFAULT '',
        match_fail_count INT NOT NULL DEFAULT 0,
        match_retry_at DATETIME NULL,
        vec_rev INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",

    // —— 一个收到的文件（或只有正文的邮件）——
    'recruit_resumes' => "CREATE TABLE recruit_resumes (
        id $pk,
        origin {$str(8)} NOT NULL DEFAULT 'email',
        message_id INT NOT NULL DEFAULT 0,
        dedupe_key {$str(191)} NOT NULL,
        source_id INT NOT NULL DEFAULT 0,
        source_user_id INT NOT NULL DEFAULT 0,
        source_user_name {$str(191)} NOT NULL DEFAULT '',
        received_at DATETIME NULL,
        file_path {$str(500)} NOT NULL DEFAULT '',
        file_name {$str(255)} NOT NULL DEFAULT '',
        file_ext {$str(8)} NOT NULL DEFAULT '',
        file_size INT NOT NULL DEFAULT 0,
        file_sha256 {$chr(64)} NOT NULL DEFAULT '',
        raw_text $big,
        text_chars INT NOT NULL DEFAULT 0,
        parse_status {$str(16)} NOT NULL DEFAULT 'pending',
        parse_mode {$str(8)} NOT NULL DEFAULT '',
        attempts INT NOT NULL DEFAULT 0,
        next_retry_at DATETIME NULL,
        locked_at DATETIME NULL,
        parse_error $txt,
        parse_error_kind {$str(16)} NOT NULL DEFAULT '',
        prompt_ver {$str(16)} NOT NULL DEFAULT '',
        parsed_at DATETIME NULL,
        doc_type {$str(16)} NOT NULL DEFAULT '',
        parsed_json $big,
        candidate_id INT NOT NULL DEFAULT 0,
        attach_mode {$str(12)} NOT NULL DEFAULT '',
        target_job_id INT NOT NULL DEFAULT 0,
        uploaded_by INT NOT NULL DEFAULT 0,
        needs_review INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",

    /* 人↔职位（多对多）。AI 只写 ai_* 列；stage / human_* / origin 只有人能改，
       AI 重新匹配永远不覆盖人的决定。ai_req_json = 逐条要求满足情况。 */
    'recruit_candidate_jobs' => "CREATE TABLE recruit_candidate_jobs (
        id $pk,
        candidate_id INT NOT NULL,
        job_id INT NOT NULL,
        origin {$str(8)} NOT NULL DEFAULT 'ai',
        ai_score {$dec('3,1')} NULL,
        ai_reason $txt,
        ai_gaps $txt,
        ai_req_json $txt,
        sem_score {$dec('5,4')} NULL,
        sem_facets_json $txt,
        transferable INT NOT NULL DEFAULT 0,
        ai_cand_rev INT NOT NULL DEFAULT 0,
        ai_job_rev INT NOT NULL DEFAULT 0,
        ai_at DATETIME NULL,
        human_score {$dec('3,1')} NULL,
        human_by INT NOT NULL DEFAULT 0,
        human_at DATETIME NULL,
        alerted_at DATETIME NULL,
        ai_prompt_ver {$str(8)} NOT NULL DEFAULT '',
        stage {$str(20)} NOT NULL DEFAULT 'suggested',
        stage_changed_at DATETIME NULL,
        stage_changed_by INT NOT NULL DEFAULT 0,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",

    'recruit_followups' => "CREATE TABLE recruit_followups (
        id $pk,
        candidate_id INT NOT NULL,
        candidate_job_id INT NOT NULL DEFAULT 0,
        kind {$str(8)} NOT NULL DEFAULT 'note',
        channel {$str(16)} NOT NULL DEFAULT '',
        content $txt,
        event_code {$str(32)} NOT NULL DEFAULT '',
        status_before {$str(16)} NOT NULL DEFAULT '',
        status_after {$str(16)} NOT NULL DEFAULT '',
        stage_before {$str(20)} NOT NULL DEFAULT '',
        stage_after {$str(20)} NOT NULL DEFAULT '',
        next_follow_at DATETIME NULL,
        created_by INT NOT NULL DEFAULT 0,
        created_by_name {$str(191)} NOT NULL DEFAULT '',
        created_at DATETIME NULL
    )$tail",

    /* 向量（2026-09-23「候选人维度不单一，要用向量做语义关联」）。
       每个候选人 / 职位 × 4 个维度（skills / experience / industry / headline），不是一整份一个向量。
       vec = 768 维 float32（精排语义分用），vec_s = 前 256 维重新归一化（人才库搜索用，读得快）。
       写入时已 L2 归一化 → 余弦 = 点积。text_hash 没变就不重算（不花钱）。
       存在 MySQL 而不是向量数据库：几万人的规模筛选后暴力点积不到 1 秒，且个人信息不出库；
       读写都经 includes/recruit_embed.php 的存储层，超过约 10 万人再换 DashVector/Qdrant。 */
    'recruit_vectors' => "CREATE TABLE recruit_vectors (
        id $pk,
        owner_type {$str(8)} NOT NULL,
        owner_id INT NOT NULL,
        facet {$str(16)} NOT NULL,
        model {$str(64)} NOT NULL DEFAULT '',
        dims INT NOT NULL DEFAULT 0,
        vec $blob,
        vec_s $blob,
        text_hash {$chr(40)} NOT NULL DEFAULT '',
        updated_at DATETIME NULL
    )$tail",

    /* 预先算好的语义分（纯 PHP 点积，不调模型）。页面与精排只读这张表，2 核生产机不做实时向量计算。
       cand_rev / job_rev = 算分时两边的向量版本，任一边向量重算过就重算这一对。 */
    'recruit_sem_scores' => "CREATE TABLE recruit_sem_scores (
        candidate_id INT NOT NULL,
        job_id INT NOT NULL,
        score {$dec('5,4')} NOT NULL DEFAULT 0,
        facets_json {$str(255)} NOT NULL DEFAULT '',
        cand_rev INT NOT NULL DEFAULT 0,
        job_rev INT NOT NULL DEFAULT 0,
        updated_at DATETIME NULL,
        PRIMARY KEY (candidate_id, job_id)
    )$tail",

    /* AI 流水线运行记录（2026-09-24「点了立即匹配，日志要展示出来」）：页面按钮与 crontab 每跑一轮一行，
       log = 逐步进度文本，summary_json = 各步计数。cron 空跑（什么都没处理）不留记录。 */
    'recruit_pipeline_runs' => "CREATE TABLE recruit_pipeline_runs (
        id $pk,
        trigger_type {$str(8)} NOT NULL DEFAULT 'cron',
        started_by INT NOT NULL DEFAULT 0,
        started_by_name {$str(191)} NOT NULL DEFAULT '',
        status {$str(16)} NOT NULL DEFAULT 'queued',
        summary_json $txt,
        log $big,
        started_at DATETIME NULL,
        finished_at DATETIME NULL
    )$tail",

    /* 人才池（2026-09-24「人才检索」页）：一个池 = 保存的检索条件（一句话描述 / 样本候选人 / 维度 / 筛选）
       + 手动挑进来的成员。打开时成员在前、再按条件实时检索（新进来的简历自动出现）。
       visibility：private 只有自己 / all 招聘组都能看；只有创建人或 recruit_admin 能改。 */
    'recruit_pools' => "CREATE TABLE recruit_pools (
        id $pk,
        name {$str(191)} NOT NULL DEFAULT '',
        description $txt,
        query_text {$str(500)} NOT NULL DEFAULT '',
        facet {$str(16)} NOT NULL DEFAULT '',
        seed_id INT NOT NULL DEFAULT 0,
        filters_json $txt,
        visibility {$str(8)} NOT NULL DEFAULT 'private',
        created_by INT NOT NULL DEFAULT 0,
        created_by_name {$str(191)} NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",
    'recruit_pool_members' => "CREATE TABLE recruit_pool_members (
        pool_id INT NOT NULL,
        candidate_id INT NOT NULL,
        added_by INT NOT NULL DEFAULT 0,
        added_by_name {$str(191)} NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        PRIMARY KEY (pool_id, candidate_id)
    )$tail",

    /* 档案翻译缓存（2026-09-24「工作内容也支持翻译」）：一份简历抽取结果 / 候选人档案 × 目标语言一行。
       src_hash = 翻译时原文的 sha1，原文变了（重解析 / 修正档案）就重翻；AI 异步翻（scripts/recruit_translate_worker.php）。 */
    'recruit_translations' => "CREATE TABLE recruit_translations (
        id $pk,
        owner_type {$str(16)} NOT NULL DEFAULT '',
        owner_id INT NOT NULL,
        lang {$str(8)} NOT NULL DEFAULT 'zh',
        src_hash {$chr(40)} NOT NULL DEFAULT '',
        status {$str(16)} NOT NULL DEFAULT 'generating',
        content_json $big,
        error $txt,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",

    /* 推荐信模板（2026-09-24「推荐信要有固定格式、固定模板，AI 预填，HR 能改」）：按语言，一个模板 = 标题 + 块列表
       （fixed 固定文字 / ai AI 段落 / manual 手填项 / field 档案字段，见 includes/recruit_reco_tpl.php）。
       每封信生成时把模板快照进 content_json，日后改模板不影响已写好的信。某语言没设默认模板时用代码里的内置默认。 */
    'recruit_reco_templates' => "CREATE TABLE recruit_reco_templates (
        id $pk,
        lang {$str(8)} NOT NULL DEFAULT 'zh',
        name {$str(191)} NOT NULL DEFAULT '',
        title {$str(191)} NOT NULL DEFAULT '',
        blocks_json $txt,
        is_default INT NOT NULL DEFAULT 0,
        status {$str(16)} NOT NULL DEFAULT 'active',
        created_by INT NOT NULL DEFAULT 0,
        updated_by INT NOT NULL DEFAULT 0,
        updated_by_name {$str(191)} NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",

    /* 提示词自我迭代（2026-09-24「失败的提示词不能每次累计，要有自我迭代的能力」，见 includes/recruit_prompts.php）：
       recruit_prompts     各场景（jd_req / match / query）提示词的版本：draft / active / retired / rejected，builtin = 代码里的内置版本
       recruit_ai_feedback 回归用例：硬失败、人工纠正（gold 有标准答案），同一输入只一条（dedupe_key），重复踩坑累加 hits
       recruit_prompt_evals 每次回归评测：现行版本 vs 候选版本的指标、是否自动启用 */
    'recruit_prompts' => "CREATE TABLE recruit_prompts (
        id $pk,
        scene {$str(16)} NOT NULL DEFAULT '',
        ver {$str(8)} NOT NULL DEFAULT '',
        body $txt,
        status {$str(16)} NOT NULL DEFAULT 'draft',
        source {$str(16)} NOT NULL DEFAULT 'manual',
        note {$str(191)} NOT NULL DEFAULT '',
        metrics_json $txt,
        eval_at DATETIME NULL,
        created_by INT NOT NULL DEFAULT 0,
        created_by_name {$str(191)} NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        activated_at DATETIME NULL
    )$tail",
    'recruit_ai_feedback' => "CREATE TABLE recruit_ai_feedback (
        id $pk,
        scene {$str(16)} NOT NULL DEFAULT '',
        kind {$str(16)} NOT NULL DEFAULT '',
        status {$str(16)} NOT NULL DEFAULT 'open',
        prompt_ver {$str(8)} NOT NULL DEFAULT '',
        ref_type {$str(32)} NOT NULL DEFAULT '',
        ref_id INT NOT NULL DEFAULT 0,
        input_json $big,
        ai_json $big,
        human_json $txt,
        detail $txt,
        dedupe_key {$chr(40)} NOT NULL DEFAULT '',
        hits INT NOT NULL DEFAULT 1,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        last_at DATETIME NULL
    )$tail",
    'recruit_prompt_evals' => "CREATE TABLE recruit_prompt_evals (
        id $pk,
        scene {$str(16)} NOT NULL DEFAULT '',
        prompt_id INT NOT NULL,
        status {$str(16)} NOT NULL DEFAULT 'queued',
        decision {$str(16)} NOT NULL DEFAULT '',
        result_json $big,
        error $txt,
        created_by INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        finished_at DATETIME NULL
    )$tail",

    /* 发件人黑名单（2026-09-24「猎头公司的广告邮件直接拉黑，以后都不解析」，见 includes/recruit_mailsync.php）：
       pattern = 完整邮箱或 @域名；hits / last_hit_at = 之后又挡掉了几封 */
    'recruit_sender_blocks' => "CREATE TABLE recruit_sender_blocks (
        id $pk,
        pattern {$str(191)} NOT NULL DEFAULT '',
        note {$str(191)} NOT NULL DEFAULT '',
        source {$str(8)} NOT NULL DEFAULT 'manual',
        reason_key {$str(32)} NOT NULL DEFAULT '',
        reason_json $txt,
        sample_subject {$str(191)} NOT NULL DEFAULT '',
        sample_message_id INT NOT NULL DEFAULT 0,
        hits INT NOT NULL DEFAULT 0,
        last_hit_at DATETIME NULL,
        created_by INT NOT NULL DEFAULT 0,
        created_by_name {$str(191)} NOT NULL DEFAULT '',
        created_at DATETIME NULL
    )$tail",

    /* 收藏（2026-09-24「人才库可以收藏简历」）：按人收藏，每个招聘专员自己的收藏夹 */
    'recruit_favorites' => "CREATE TABLE recruit_favorites (
        user_id INT NOT NULL,
        candidate_id INT NOT NULL,
        created_at DATETIME NULL,
        PRIMARY KEY (user_id, candidate_id)
    )$tail",

    /* 邮件拉取失败清单（2026-09-24「拉失败重试 3 次，下次继续重试」）：本轮 3 次都失败的邮件记在这里，
       游标照常往后走（不卡后面的邮件），之后每轮先重试；累计 10 轮标 gave_up，页面可手动重置。 */
    'recruit_mail_failures' => "CREATE TABLE recruit_mail_failures (
        id $pk,
        mailbox_id INT NOT NULL,
        imap_uid INT NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        status {$str(16)} NOT NULL DEFAULT 'pending',
        last_error $txt,
        first_failed_at DATETIME NULL,
        last_try_at DATETIME NULL
    )$tail",

    /* 推荐材料（2026-09-24）：一个「候选人 × 职位 × 语言」一份，含推荐信 + 推荐版简历（content_json）。
       AI 生成初稿（异步 worker），招聘专员可改措辞；与候选人档案是两回事——这里改的只是给客户看的版本。
       cand_rev / job_rev = 生成时的档案/JD 版本，页面据此提示「档案已更新，建议重新生成」。 */
    'recruit_recommendations' => "CREATE TABLE recruit_recommendations (
        id $pk,
        candidate_id INT NOT NULL,
        job_id INT NOT NULL,
        lang {$str(8)} NOT NULL DEFAULT 'zh',
        status {$str(16)} NOT NULL DEFAULT 'generating',
        content_json $big,
        cand_rev INT NOT NULL DEFAULT 0,
        job_rev INT NOT NULL DEFAULT 0,
        error $txt,
        generated_at DATETIME NULL,
        generated_by INT NOT NULL DEFAULT 0,
        edited_at DATETIME NULL,
        edited_by INT NOT NULL DEFAULT 0,
        client_customer_id INT NOT NULL DEFAULT 0,
        client_name {$str(191)} NOT NULL DEFAULT '',
        sent_at DATETIME NULL,
        sent_by INT NOT NULL DEFAULT 0,
        sent_by_name {$str(191)} NOT NULL DEFAULT '',
        sent_note $txt,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )$tail",

    /* 通知去重（2026-09-24「一定要及时给 HR 通知」，includes/recruit_notify.php）：
       汇总类通知（新简历 / 待处理 / 故障）每 5 分钟扫一次，靠 (event, dedupe_key, user_id) 唯一键保证只发一次。保留 7 天，代码里自动清。 */
    'recruit_notify_log' => "CREATE TABLE recruit_notify_log (
        id $pk,
        event {$str(32)} NOT NULL,
        dedupe_key {$str(191)} NOT NULL,
        user_id INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL
    )$tail",

    /* 搜索词向量缓存（P3 搜索提效）：同一句搜索词不再每次现调 embedding 接口。
       query_hash = sha1(模型 + 维度 + 规范化后的搜索词)；7 天过期，代码里自动清 */
    'recruit_query_vec_cache' => "CREATE TABLE recruit_query_vec_cache (
        query_hash {$chr(40)} NOT NULL PRIMARY KEY,
        vec $blob,
        created_at DATETIME NULL
    )$tail",
];

$indexes = [
    'recruit_vectors'        => ["CREATE UNIQUE INDEX uk_rvec ON recruit_vectors(owner_type, owner_id, facet)"],
    'recruit_notify_log'     => ["CREATE UNIQUE INDEX uk_rnl ON recruit_notify_log(event, dedupe_key, user_id)",
                                 "CREATE INDEX idx_rnl_created ON recruit_notify_log(created_at)"],
    'recruit_query_vec_cache' => ["CREATE INDEX idx_rqvc_created ON recruit_query_vec_cache(created_at)"],
    'recruit_sem_scores'     => ["CREATE INDEX idx_rsem_job ON recruit_sem_scores(job_id, score)"],
    'recruit_recommendations' => ["CREATE UNIQUE INDEX uk_rreco ON recruit_recommendations(candidate_id, job_id, lang)"],
    'recruit_pipeline_runs'  => ["CREATE INDEX idx_rpr_started ON recruit_pipeline_runs(started_at)"],
    'recruit_pool_members'   => ["CREATE INDEX idx_rpm_cand ON recruit_pool_members(candidate_id)"],
    'recruit_translations'   => ["CREATE UNIQUE INDEX uk_rtr ON recruit_translations(owner_type, owner_id, lang)"],
    'recruit_prompts'        => ["CREATE UNIQUE INDEX uk_rprompt ON recruit_prompts(scene, ver)"],
    'recruit_sender_blocks'  => ["CREATE UNIQUE INDEX uk_rsblock ON recruit_sender_blocks(pattern)"],
    'recruit_ai_feedback'    => ["CREATE UNIQUE INDEX uk_raifb ON recruit_ai_feedback(dedupe_key)",
                                 "CREATE INDEX idx_raifb_scene ON recruit_ai_feedback(scene, status, last_at)"],
    'recruit_prompt_evals'   => ["CREATE INDEX idx_rpe_prompt ON recruit_prompt_evals(prompt_id, status)"],
    'recruit_mail_failures'  => ["CREATE UNIQUE INDEX uk_rmf ON recruit_mail_failures(mailbox_id, imap_uid)",
                                 "CREATE INDEX idx_rmf_status ON recruit_mail_failures(mailbox_id, status)"],
    'recruit_sources'        => ["CREATE UNIQUE INDEX uk_rsrc_code ON recruit_sources(code)"],
    'recruit_messages'       => ["CREATE UNIQUE INDEX uk_rmsg_uid ON recruit_messages(mailbox_id, imap_uid)",
                                 "CREATE INDEX idx_rmsg_source ON recruit_messages(source_id)"],
    'recruit_projects'       => ["CREATE INDEX idx_rproj_status ON recruit_projects(status)",
                                 "CREATE INDEX idx_rproj_cust ON recruit_projects(customer_id)",
                                 "CREATE INDEX idx_rproj_opp ON recruit_projects(opportunity_id)"],
    'recruit_jobs'           => ["CREATE INDEX idx_rjob_proj ON recruit_jobs(project_id, status)"],
    'recruit_candidates'     => ["CREATE UNIQUE INDEX uk_rcand_phone ON recruit_candidates(phone_key)",
                                 "CREATE INDEX idx_rcand_email ON recruit_candidates(email_key)",
                                 "CREATE INDEX idx_rcand_owner ON recruit_candidates(owner_user_id, first_received_at)",
                                 "CREATE INDEX idx_rcand_status ON recruit_candidates(status)",
                                 "CREATE INDEX idx_rcand_next ON recruit_candidates(next_followup_at)",
                                 "CREATE INDEX idx_rcand_last ON recruit_candidates(last_received_at)"],
    'recruit_resumes'        => ["CREATE UNIQUE INDEX uk_rres_dedupe ON recruit_resumes(dedupe_key)",
                                 "CREATE INDEX idx_rres_queue ON recruit_resumes(parse_status, next_retry_at)",
                                 "CREATE INDEX idx_rres_cand ON recruit_resumes(candidate_id, received_at)",
                                 "CREATE INDEX idx_rres_msg ON recruit_resumes(message_id)",
                                 "CREATE INDEX idx_rres_sha ON recruit_resumes(file_sha256)",
                                 "CREATE INDEX idx_rres_src ON recruit_resumes(source_user_id, received_at)"],
    'recruit_candidate_jobs' => ["CREATE UNIQUE INDEX uk_rcj ON recruit_candidate_jobs(candidate_id, job_id)",
                                 "CREATE INDEX idx_rcj_stage ON recruit_candidate_jobs(job_id, stage)",
                                 "CREATE INDEX idx_rcj_score ON recruit_candidate_jobs(job_id, ai_score)"],
    'recruit_followups'      => ["CREATE INDEX idx_rfu_cand ON recruit_followups(candidate_id, created_at)",
                                 "CREATE INDEX idx_rfu_by ON recruit_followups(created_by, created_at)",
                                 "CREATE INDEX idx_rfu_stage ON recruit_followups(stage_after, created_at)"],
];

$exists = function (string $t) use ($pdo): bool {
    try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); return true; } catch (PDOException $e) { return false; }
};
$hasCol = function (string $t, string $c) use ($pdo): bool {
    try { $pdo->query("SELECT $c FROM $t LIMIT 1"); return true; } catch (PDOException $e) { return false; }
};
$rows = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();

// ---- 1. 识别 P1 旧结构 ----
$legacy = [];
if ($exists('recruit_candidates') && $hasCol('recruit_candidates', 'resume_file_path')) $legacy[] = 'recruit_candidates';
if ($exists('recruit_jobs') && $hasCol('recruit_jobs', 'client_name'))                  $legacy[] = 'recruit_jobs';
foreach ($legacy as $t) {
    $n = $rows($t);
    if ($n > 0) {
        echo "❌ $t 是 P1 旧结构且有 $n 行数据，不能自动重建。先人工确认这些数据，中止\n";
        exit(1);
    }
    echo "[drop] {$t}（P1 旧结构，0 行）\n";
    if ($apply) $pdo->exec("DROP TABLE $t");
}

// ---- 2. 建表 ----
$created = 0;
foreach ($tables as $name => $ddl) {
    $stillLegacy = in_array($name, $legacy, true) && !$apply;   // dry-run 时旧表还在
    if ($exists($name) && !$stillLegacy) { echo "[skip] $name 已存在\n"; continue; }
    $created++;
    echo "[add ] $name\n";
    if ($apply) {
        $pdo->exec($ddl);
        foreach ($indexes[$name] ?? [] as $ix) $pdo->exec($ix);
    }
}

// ---- 2b. 后加的列（已建好的表补上；ADD COLUMN 失败只可能是「已存在」，可以 try/catch，§7.9）----
/* 2026-09-23 需求方「很多简历在本地，也要提供上传入口」：
     target_job_id —— 上传时指定的职位，解析出候选人后自动挂上去
     uploaded_by   —— 页面上传的操作人（邮件进来的为 0） */
$addCols = [
    'recruit_resumes' => ['target_job_id' => 'INT NOT NULL DEFAULT 0', 'uploaded_by' => 'INT NOT NULL DEFAULT 0',
                          // 需复核：模型对某些字段没把握（uncertain_fields），或同号异名。解析页据此筛
                          'needs_review' => 'INT NOT NULL DEFAULT 0'],
    // 向量版本：= 算向量时的 profile_rev / jd_rev；小于当前版本就要重算
    // p4 解析（2026-09-24「语言情况一定要标明，婚姻 / 种族 / 宗教也标」）：原文 + 归一码，lang_codes 两端带逗号供 LIKE 筛选
    'recruit_candidates' => ['vec_rev' => 'INT NOT NULL DEFAULT 0',
                             'religion' => ($my ? 'VARCHAR(40)' : 'TEXT') . " NOT NULL DEFAULT ''", 'religion_code' => ($my ? 'VARCHAR(16)' : 'TEXT') . " NOT NULL DEFAULT ''",
                             'marital_status' => ($my ? 'VARCHAR(40)' : 'TEXT') . " NOT NULL DEFAULT ''", 'marital_code' => ($my ? 'VARCHAR(16)' : 'TEXT') . " NOT NULL DEFAULT ''",
                             'ethnicity' => ($my ? 'VARCHAR(40)' : 'TEXT') . " NOT NULL DEFAULT ''", 'resume_lang' => ($my ? 'VARCHAR(8)' : 'TEXT') . " NOT NULL DEFAULT ''",
                             'lang_codes' => ($my ? 'VARCHAR(64)' : 'TEXT') . " NOT NULL DEFAULT ''", 'languages_json' => 'TEXT'],
    // 推荐信「标记已发送」：谁、何时、发给谁（备注）。发送后锁定不可再改——这是「人是本公司推荐的」的凭据
    // 推荐给哪个客户（生成时选，默认项目的客户；抬头「致」写这个客户）—— 2026-09-24「生成推荐报告需要选择客户」
    'recruit_recommendations' => ['client_customer_id' => 'INT NOT NULL DEFAULT 0', 'client_name' => ($my ? 'VARCHAR(191)' : 'TEXT') . " NOT NULL DEFAULT ''",
                                  'sent_at' => 'DATETIME NULL', 'sent_by' => 'INT NOT NULL DEFAULT 0',
                                  'sent_by_name' => ($my ? 'VARCHAR(191)' : 'TEXT') . " NOT NULL DEFAULT ''", 'sent_note' => 'TEXT'],
    // 招聘文案 {id|en|zh: 文本}（2026-09-24「生成招聘的 JD，HR 可以直接复制」）
    /* 发件人自动拉黑（2026-09-24「系统判定有问题直接拉黑，要显示原因」，includes/recruit_sender_guard.php）：
         source manual / auto、reason_key（ad / spam / repeat_fail / manual）、reason_json 三语原因、触发的那封邮件；
         recruit_messages.sender_check = 这封邮件查过发件人没有（application / ad / spam / other / skip…），查过不再查 */
    'recruit_sender_blocks' => ['source' => ($my ? 'VARCHAR(8)' : 'TEXT') . " NOT NULL DEFAULT 'manual'", 'reason_key' => ($my ? 'VARCHAR(32)' : 'TEXT') . " NOT NULL DEFAULT ''",
                                'reason_json' => 'TEXT', 'sample_subject' => ($my ? 'VARCHAR(191)' : 'TEXT') . " NOT NULL DEFAULT ''",
                                'sample_message_id' => 'INT NOT NULL DEFAULT 0'],
    'recruit_messages' => ['sender_check' => ($my ? 'VARCHAR(16)' : 'TEXT') . " NOT NULL DEFAULT ''"],
    // 已发布到哪些招聘平台 {linkedin: {at, by, by_name}, …}（2026-09-24「可以选择发了哪些平台」）
    'recruit_jobs' => ['vec_rev' => 'INT NOT NULL DEFAULT 0', 'posting_json' => ($my ? 'MEDIUMTEXT' : 'TEXT'), 'posted_json' => 'TEXT'],
    // 精排结果旁边留一份召回依据：语义总分、各维度分、是否跨行业可迁移
    'recruit_candidate_jobs' => ['sem_score' => ($my ? 'DECIMAL(5,4)' : 'REAL') . ' NULL', 'sem_facets_json' => 'TEXT',
                                 'transferable' => 'INT NOT NULL DEFAULT 0',
                                 // 高分企微提醒发过的时间（2026-09-24 负责人）：每个「人 × 职位」只提醒一次
                                 'alerted_at' => 'DATETIME NULL',
                                 // 打分时的提示词版本（m3 起）：升版本后已有组合自动重评
                                 'ai_prompt_ver' => ($my ? 'VARCHAR(8)' : 'TEXT') . " NOT NULL DEFAULT ''"],
];
foreach ($addCols as $t => $cols) {
    if (!$exists($t)) continue;   // 表刚在上面建（或 dry-run 还没建）：CREATE 里已带这些列
    foreach ($cols as $c => $def) {
        if ($hasCol($t, $c)) continue;
        echo "[col ] $t.$c\n";
        if ($apply) { try { $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def"); } catch (PDOException $e) {} }
    }
}

// ---- 3. 结果态校验：每张表的列齐全（§7.2）----
if ($apply) {
    $want = [
        'recruit_jobs'           => ['project_id', 'jd_rev', 'jd_requirements_json', 'jd_req_source', 'posting_json', 'posted_json'],
        'recruit_candidates'     => ['phone_key', 'profile_json', 'owner_user_id', 'owner_set_by', 'review_flags', 'religion_code', 'lang_codes', 'languages_json'],
        'recruit_resumes'        => ['dedupe_key', 'parse_status', 'attempts', 'next_retry_at', 'parsed_json', 'candidate_id', 'target_job_id', 'uploaded_by'],
        'recruit_candidate_jobs' => ['ai_score', 'ai_req_json', 'human_score', 'stage', 'alerted_at'],
        'recruit_followups'      => ['event_code', 'stage_after', 'next_follow_at'],
        'recruit_vectors'        => ['owner_type', 'facet', 'vec', 'vec_s', 'text_hash'],
        'recruit_sem_scores'     => ['score', 'facets_json', 'cand_rev', 'job_rev'],
        'recruit_recommendations' => ['candidate_id', 'job_id', 'lang', 'status', 'content_json', 'sent_at', 'sent_by_name', 'client_customer_id', 'client_name'],
        'recruit_mail_failures'  => ['mailbox_id', 'imap_uid', 'attempts', 'status'],
        'recruit_favorites'      => ['user_id', 'candidate_id'],
        'recruit_pools'          => ['name', 'query_text', 'facet', 'seed_id', 'filters_json', 'visibility', 'created_by'],
        'recruit_pool_members'   => ['pool_id', 'candidate_id'],
        'recruit_translations'   => ['owner_type', 'owner_id', 'lang', 'src_hash', 'status', 'content_json'],
        'recruit_reco_templates' => ['lang', 'name', 'title', 'blocks_json', 'is_default', 'status'],
        'recruit_prompts'        => ['scene', 'ver', 'body', 'status', 'source', 'metrics_json'],
        'recruit_sender_blocks'  => ['pattern', 'hits', 'created_by_name', 'source', 'reason_key', 'reason_json', 'sample_subject'],
        'recruit_ai_feedback'    => ['scene', 'kind', 'status', 'input_json', 'dedupe_key', 'hits'],
        'recruit_prompt_evals'   => ['scene', 'prompt_id', 'status', 'decision', 'result_json'],
        'recruit_pipeline_runs'  => ['trigger_type', 'status', 'log', 'summary_json'],
        'recruit_notify_log'     => ['event', 'dedupe_key', 'user_id', 'created_at'],
        'recruit_query_vec_cache' => ['query_hash', 'vec', 'created_at'],
    ];
    foreach ($want as $t => $cols) foreach ($cols as $c) {
        if (!$hasCol($t, $c)) { echo "❌ $t 缺列 $c —— 可能是别的旧结构残留，中止\n"; exit(1); }
    }
}

if (!$apply) {
    echo "\n将删除 P1 旧表 " . count($legacy) . " 张，新建 $created 张。（dry-run，未写库。加 --apply 执行）\n";
    exit(0);
}

// 招聘专员的来源代码（邮箱 plus 地址 +xx@）在「设置 · 招聘」页里配，不在这里写死人

// ---- 5. 内部招聘项目（按 kind 找，不写死 id §7.1）----
$internal = (int)$pdo->query("SELECT COUNT(*) FROM recruit_projects WHERE kind='internal'")->fetchColumn();
if ($internal === 0) {
    $pdo->exec("INSERT INTO recruit_projects (name, kind, status, created_at, updated_at)
                VALUES ('内部招聘 / Internal hiring', 'internal', 'open', (" . dbNow() . "), (" . dbNow() . "))");
    echo "\n[add ] 内部招聘项目\n";
}

echo "\n✅ 删旧表 " . count($legacy) . " 张；建表 $created 张\n";
echo "重跑应报「已存在」×" . count($tables) . "、建表 0 张、不再新增内部项目。\n";
