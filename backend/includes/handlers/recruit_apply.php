<?php
/**
 * 职位投递链接的接口（逻辑在 includes/recruit_apply.php）。
 *   recruitJobLink        登录：我在这个职位上的投递链接（没有就生成）+ 打开数 / 投递数
 *   recruitRevokeJobLink  登录：撤销我在这个职位上的链接
 *   recruitApplyInfo      ⛔ 免登录：候选人打开链接看职位（api/handler.php 的 $publicActions）
 *   recruitApplySubmit    ⛔ 免登录：候选人提交简历
 * 免登录接口只认 token，不返回任何内部数据；失效 / 撤销 / 职位关闭统一回 linkInvalid。
 */

function recruitApplyNeedReady(PDO $pdo): void {
    require_once __DIR__ . '/../recruit_apply.php';
    if (!recruitApplyReady($pdo)) recruitErr('applyPending', 'apply tables not created');
}

function handleRecruitJobLink(PDO $pdo): void {
    [$uid] = recruitAuth($pdo);
    recruitApplyNeedReady($pdo);
    $jobId = (int)($_GET['job_id'] ?? 0);
    $st = $pdo->prepare("SELECT j.id FROM recruit_jobs j WHERE j.id=?");
    $st->execute([$jobId]);
    if (!$st->fetchColumn()) recruitErr('notFound', 'job not found');
    jsonResponse(['success' => true, 'data' => recruitApplyLinkFor($pdo, $jobId, $uid)]);
}

function handleRecruitRevokeJobLink(PDO $pdo, array $in): void {
    [$uid] = recruitAuth($pdo);
    recruitApplyNeedReady($pdo);
    recruitApplyRevoke($pdo, (int)($in['job_id'] ?? 0), $uid);
    jsonResponse(['success' => true]);
}

function handleRecruitApplyInfo(PDO $pdo): void {
    recruitApplyNeedReady($pdo);
    $l = recruitApplyResolve($pdo, (string)($_GET['token'] ?? ''));
    if (!$l) recruitErr('linkInvalid', 'link invalid', 404);
    if (($_GET['first'] ?? '') === '1') $pdo->prepare("UPDATE recruit_job_links SET views=views+1 WHERE id=?")->execute([(int)$l['link_id']]);   // 切语言重取不算一次打开
    $lang = in_array($_GET['lang'] ?? '', RECRUIT_APPLY_LANGS, true) ? (string)$_GET['lang'] : 'id';
    // 取 / 起这个语言的 AI 译文（标题、薪资总要翻；描述有人工招聘文案时用文案）；职位改过 JD 会重翻
    $tr = recruitApplyTranslation($pdo, (int)$l['job_id'], $lang);
    jsonResponse(['success' => true, 'data' => recruitApplyPublicJob($l, $lang, $tr)]);
}

function handleRecruitApplySubmit(PDO $pdo, array $in): void {
    recruitApplyNeedReady($pdo);
    $in += $_POST;   // 表单提交：没带文件时路由不会把 $_POST 当 $input
    $l = recruitApplyResolve($pdo, (string)($in['token'] ?? ''));
    if (!$l) recruitErr('linkInvalid', 'link invalid', 404);
    $f = $_FILES['file'] ?? null;
    if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) recruitErr('apply.fileRequired', 'file required');
    try {
        $r = recruitApplySubmit($pdo, $l, $in, ['name' => basename((string)$f['name']), 'bin' => (string)file_get_contents((string)$f['tmp_name'])],
            (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    } catch (InvalidArgumentException $e) {
        recruitErr('apply.' . $e->getMessage(), $e->getMessage(), $e->getMessage() === 'tooMany' ? 429 : 200);
    } catch (Throwable $e) {
        error_log('recruitApplySubmit: ' . $e->getMessage());
        recruitErr('apply.failed', 'failed', 500);   // OSS / 数据库故障：不把内部错误原文给外部
    }
    if ($r['status'] === 'added') {   // 「自动解析」开着就立刻拉一轮，几分钟内就能在职位里看到这个人
        require_once __DIR__ . '/../recruit_pipeline.php';
        recruitKickPipeline($pdo, '链接投递');
    }
    jsonResponse(['success' => true, 'data' => ['status' => $r['status']]]);
}
