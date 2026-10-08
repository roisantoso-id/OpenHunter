<?php
/**
 * OpenHunter API 入口：/api/handler.php?action=<name>
 * GET 参数走 $_GET，POST 走 JSON body（文件上传走 multipart，字段在 $_POST）。
 * 认证：Authorization: Bearer <token>（action=login 签发）。
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

header('Access-Control-Allow-Origin: ' . (getenv('OPENHUNTER_CORS_ORIGIN') ?: '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = Database::getInstance()->getConnection();

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    if (!empty($_FILES)) {
        $input = $_POST;
    } else {
        $raw = file_get_contents('php://input');
        if ($raw) $input = json_decode($raw, true) ?: [];
    }
}

// 免登录：登录本身 + 职位投递链接（候选人免登录看职位、投简历；handler 内只认 token，限流见 includes/recruit_apply.php）
$publicActions = ['login', 'recruitApplyInfo', 'recruitApplySubmit'];
if (!in_array($action, $publicActions, true)) {
    $userId = verifyToken();
    if (!$userId) jsonResponse(['success' => false, 'errorMessage' => 'Unauthorized', 'errorCode' => 401], 401);
}

try {
    switch ($action) {
        // ── 账号 ──
        case 'login':
            handleLogin($pdo, $input);
            break;
        case 'currentUser':
            handleCurrentUser($pdo);
            break;
        case 'updateMyLang':
            handleUpdateMyLang($pdo, $input);
            break;

        // ── 站内通知 ──
        case 'getNotifications':
            handleGetNotifications($pdo);
            break;
        case 'getUnreadNotificationCount':
            handleGetUnreadNotificationCount($pdo);
            break;
        case 'markNotificationRead':
            handleMarkNotificationRead($pdo, $input);
            break;
        case 'markAllNotificationsRead':
            handleMarkAllNotificationsRead($pdo);
            break;

        // ── 客户（includes/host.php）──
        case 'recruitCreateClient':
            handleRecruitCreateClient($pdo, $input);
            break;

        // ── 招聘（includes/handlers/recruit.php）──
        case 'recruitMeta':
            handleRecruitMeta($pdo);
            break;
        case 'recruitSearchClients':
            handleRecruitSearchClients($pdo);
            break;
        case 'recruitListProjects':
            handleRecruitListProjects($pdo);
            break;
        case 'recruitGetProject':
            handleRecruitGetProject($pdo);
            break;
        // 招聘全生命周期（includes/handlers/recruit_lifecycle.php）
        case 'recruitProjectOverview':
            handleRecruitProjectOverview($pdo);
            break;
        case 'recruitProjectPipeline':
            handleRecruitProjectPipeline($pdo);
            break;
        case 'recruitListPlacements':
            handleRecruitListPlacements($pdo);
            break;
        case 'recruitListDocuments':
            handleRecruitListDocuments($pdo);
            break;
        case 'recruitGetDocumentFile':
            handleRecruitGetDocumentFile($pdo);
            break;
        case 'recruitSaveProjectTerms':
            handleRecruitSaveProjectTerms($pdo, $input);
            break;
        case 'recruitSaveJobTerms':
            handleRecruitSaveJobTerms($pdo, $input);
            break;
        // 企业库（includes/handlers/recruit_company.php）
        case 'recruitCompanies':
            handleRecruitCompanies($pdo);
            break;
        case 'recruitCompanyDetail':
            handleRecruitCompanyDetail($pdo);
            break;
        case 'recruitCompanyPeople':
            handleRecruitCompanyPeople($pdo);
            break;
        case 'recruitCompanyIgnored':
            handleRecruitCompanyIgnored($pdo);
            break;
        case 'recruitSegments':
            handleRecruitSegments($pdo);
            break;
        case 'recruitTitleFunctions':
            handleRecruitTitleFunctions($pdo);
            break;
        case 'recruitSaveCompany':
            handleRecruitSaveCompany($pdo, $input);
            break;
        case 'recruitMergeCompany':
            handleRecruitMergeCompany($pdo, $input);
            break;
        case 'recruitIgnoreCompany':
            handleRecruitIgnoreCompany($pdo, $input);
            break;
        case 'recruitUnignoreCompany':
            handleRecruitUnignoreCompany($pdo, $input);
            break;
        case 'recruitCompanyEnrich':
            handleRecruitCompanyEnrich($pdo, $input);
            break;
        case 'recruitSaveSegment':
            handleRecruitSaveSegment($pdo, $input);
            break;
        case 'recruitSetTitleFunction':
            handleRecruitSetTitleFunction($pdo, $input);
            break;
        case 'recruitCompanyRelink':
            handleRecruitCompanyRelink($pdo, $input);
            break;
        case 'recruitCompanyViewers':
            handleRecruitCompanyViewers($pdo);
            break;
        case 'recruitCompanyResearch':
            handleRecruitCompanyResearch($pdo, $input);
            break;
        case 'recruitCompanyResearchStatus':
            handleRecruitCompanyResearchStatus($pdo);
            break;
        case 'recruitCompanyRecent':
            handleRecruitCompanyRecent($pdo);
            break;
        case 'recruitCompanyClassifyNow':
            handleRecruitCompanyClassifyNow($pdo, $input);
            break;
        case 'recruitSetCompanyEnrich':
            handleRecruitSetCompanyEnrich($pdo, $input);
            break;
        case 'recruitJobLink':
            handleRecruitJobLink($pdo);
            break;
        case 'recruitRevokeJobLink':
            handleRecruitRevokeJobLink($pdo, $input);
            break;
        case 'recruitApplyInfo':
            handleRecruitApplyInfo($pdo);
            break;
        case 'recruitApplySubmit':
            handleRecruitApplySubmit($pdo, $input);
            break;
        case 'recruitNetwork':
            handleRecruitNetwork($pdo);
            break;
        case 'recruitNetworkSearch':
            handleRecruitNetworkSearch($pdo);
            break;
        case 'recruitSegmentChat':
            handleRecruitSegmentChat($pdo, $input);
            break;
        case 'recruitSegmentApply':
            handleRecruitSegmentApply($pdo, $input);
            break;
        case 'recruitSaveCompanyViewers':
            handleRecruitSaveCompanyViewers($pdo, $input);
            break;
        case 'recruitSavePlacement':
            handleRecruitSavePlacement($pdo, $input);
            break;
        case 'recruitPlacementAction':
            handleRecruitPlacementAction($pdo, $input);
            break;
        case 'recruitUpdateFollowup':
            handleRecruitUpdateFollowup($pdo, $input);
            break;
        case 'recruitUpdateCandidateStatus':
            handleRecruitUpdateCandidateStatus($pdo, $input);
            break;
        case 'recruitUploadDocument':
            handleRecruitUploadDocument($pdo, $input);
            break;
        case 'recruitDeleteDocument':
            handleRecruitDeleteDocument($pdo, $input);
            break;
        case 'recruitListCandidates':
            handleRecruitListCandidates($pdo);
            break;
        case 'recruitGetCandidate':
            handleRecruitGetCandidate($pdo);
            break;
        case 'recruitGetResumeFile':
            handleRecruitGetResumeFile($pdo);
            break;
        case 'recruitResumesPage':
            handleRecruitResumesPage($pdo);
            break;
        case 'recruitGetResume':
            handleRecruitGetResume($pdo);
            break;
        case 'recruitSimilarCandidates':
            handleRecruitSimilarCandidates($pdo);
            break;
        case 'recruitRelatedJobs':
            handleRecruitRelatedJobs($pdo);
            break;
        case 'recruitRelatedPeople':
            handleRecruitRelatedPeople($pdo);
            break;
        case 'recruitSearch':
            handleRecruitSearch($pdo);
            break;
        case 'recruitPools':
            handleRecruitPools($pdo);
            break;
        case 'recruitRecoTemplates':
            handleRecruitRecoTemplates($pdo);
            break;
        case 'recruitTranslation':
            require_once __DIR__ . '/../includes/recruit_translate.php';
            handleRecruitTranslation($pdo);
            break;
        case 'recruitRecoGet':
            handleRecruitRecoGet($pdo);
            break;
        case 'recruitRecoGenerate':
            handleRecruitRecoGenerate($pdo, $input);
            break;
        case 'recruitRecoSave':
            handleRecruitRecoSave($pdo, $input);
            break;
        case 'recruitRecoDocx':
            handleRecruitRecoDocx($pdo);
            break;
        case 'recruitWorkbenchStats':
            handleRecruitWorkbenchStats($pdo);
            break;
        case 'recruitWorkbench':
            handleRecruitWorkbench($pdo);
            break;
        case 'recruitPipelineRuns':
            handleRecruitPipelineRuns($pdo);
            break;
        case 'recruitPipelineRun':
            handleRecruitPipelineRun($pdo);
            break;
        case 'recruitRunPipeline':
            handleRecruitRunPipeline($pdo, $input);
            break;
        case 'recruitStats':
            handleRecruitStats($pdo);
            break;
        case 'recruitTeamProgress':
            handleRecruitTeamProgress($pdo);
            break;
        case 'recruitSaveProject':
            handleRecruitSaveProject($pdo, $input);
            break;
        case 'recruitSaveJob':
            handleRecruitSaveJob($pdo, $input);
            break;
        case 'recruitToggleFavorite':
            handleRecruitToggleFavorite($pdo, $input);
            break;
        case 'recruitAddFollowup':
            handleRecruitAddFollowup($pdo, $input);
            break;
        case 'recruitAddMatch':
            handleRecruitAddMatch($pdo, $input);
            break;
        case 'recruitBulkAddMatch':
            handleRecruitBulkAddMatch($pdo, $input);
            break;
        case 'recruitPrompts':
            handleRecruitPrompts($pdo);
            break;
        case 'recruitPromptFeedback':
            handleRecruitPromptFeedback($pdo);
            break;
        case 'recruitPromptFeedbackSet':
            handleRecruitPromptFeedbackSet($pdo, $input);
            break;
        case 'recruitPromptSave':
            handleRecruitPromptSave($pdo, $input);
            break;
        case 'recruitPromptEval':
            handleRecruitPromptEval($pdo, $input);
            break;
        case 'recruitPromptActivate':
            handleRecruitPromptActivate($pdo, $input);
            break;
        case 'recruitSplitJd':
            handleRecruitSplitJd($pdo, $input);
            break;
        case 'recruitBlockSender':
            handleRecruitBlockSender($pdo, $input);
            break;
        case 'recruitSenderBlocks':
            handleRecruitSenderBlocks($pdo);
            break;
        case 'recruitSenderUnblock':
            handleRecruitSenderUnblock($pdo, $input);
            break;
        case 'recruitJobPostedSet':
            handleRecruitJobPostedSet($pdo, $input);
            break;
        case 'recruitJobPostingSave':
            handleRecruitJobPostingSave($pdo, $input);
            break;
        case 'recruitJobPosting':
            handleRecruitJobPosting($pdo, $input);
            break;
        case 'recruitRecoRewrite':
            handleRecruitRecoRewrite($pdo, $input);
            break;
        case 'recruitRecoSetClient':
            handleRecruitRecoSetClient($pdo, $input);
            break;
        case 'recruitRecoMarkSent':
            handleRecruitRecoMarkSent($pdo, $input);
            break;
        case 'recruitRecoUnlock':
            handleRecruitRecoUnlock($pdo, $input);
            break;
        case 'recruitRecoTemplateSave':
            handleRecruitRecoTemplateSave($pdo, $input);
            break;
        case 'recruitRecoTemplateArchive':
            handleRecruitRecoTemplateArchive($pdo, $input);
            break;
        case 'recruitTranslate':
            handleRecruitTranslate($pdo, $input);
            break;
        case 'recruitParseQuery':
            handleRecruitParseQuery($pdo, $input);
            break;
        case 'recruitSavePool':
            handleRecruitSavePool($pdo, $input);
            break;
        case 'recruitDeletePool':
            handleRecruitDeletePool($pdo, $input);
            break;
        case 'recruitPoolMembers':
            handleRecruitPoolMembers($pdo, $input);
            break;
        case 'recruitUpdateMatch':
            handleRecruitUpdateMatch($pdo, $input);
            break;
        case 'recruitRemoveMatch':
            handleRecruitRemoveMatch($pdo, $input);
            break;
        case 'recruitReparseResume':
            handleRecruitReparseResume($pdo, $input);
            break;
        case 'recruitUpdateCandidate':
            handleRecruitUpdateCandidate($pdo, $input);
            break;
        case 'recruitSetOwner':
            handleRecruitSetOwner($pdo, $input);
            break;
        case 'recruitMergeCandidates':
            handleRecruitMergeCandidates($pdo, $input);
            break;
        case 'recruitUploadResume':
            handleRecruitUploadResume($pdo, $input);
            break;
        case 'recruitMailboxes':
            handleRecruitMailboxes($pdo);
            break;
        case 'recruitConfigGet':
            handleRecruitConfigGet($pdo);
            break;
        case 'recruitConfigSave':
            handleRecruitConfigSave($pdo, $input);
            break;
        case 'recruitPermList':
            handleRecruitPermList($pdo);
            break;
        case 'recruitPermSet':
            handleRecruitPermSet($pdo, $input);
            break;
        case 'recruitSaveSource':
            handleRecruitSaveSource($pdo, $input);
            break;
        case 'recruitMailRetry':
            handleRecruitMailRetry($pdo, $input);
            break;
        case 'recruitSaveMailbox':
            handleRecruitSaveMailbox($pdo, $input);
            break;

        default:
            jsonResponse(['success' => false, 'errorMessage' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    error_log('[api] ' . $action . ': ' . $e->getMessage());
    jsonResponse(['success' => false, 'errorMessage' => $e->getMessage()], 500);
}
