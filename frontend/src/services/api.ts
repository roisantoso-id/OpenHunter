import { request } from '@umijs/max';
import { API_BASE_URL } from './config';

export async function login(params: API.LoginParams) {
  return request<API.LoginResult>('', {
    method: 'POST',
    params: { action: 'login' },
    data: params,
  });
}

export async function getCurrentUser() {
  return request<{ success: boolean; data: API.CurrentUser }>('', {
    params: { action: 'currentUser' },
  });
}

// ── Recruiting (backend: includes/handlers/recruit.php) ──
// 业务错误返回 200 + success:false + message_key，页面自己 t(message_key)，不走全局英文兜底提示
const recruitGet = (action: string, params: Record<string, any> = {}) =>
  request<any>('', { params: { action, ...params } });
const recruitPost = (action: string, data: Record<string, any>) =>
  request<any>('', { method: 'POST', params: { action }, data });
export const recruitMeta = () => recruitGet('recruitMeta');
export const recruitSearchClients = (type: 'customer', keyword: string) =>
  recruitGet('recruitSearchClients', { type, keyword });
export const recruitListProjects = (params: { status?: string } = {}) => recruitGet('recruitListProjects', params);
export const recruitGetProject = (id: number) => recruitGet('recruitGetProject', { id });
export const recruitSaveProject = (data: Record<string, any>) => recruitPost('recruitSaveProject', data);
export const recruitSaveJob = (data: Record<string, any>) => recruitPost('recruitSaveJob', data);
export const recruitSplitJd = (data: { id?: number; title?: string; jd_text: string }) => recruitPost('recruitSplitJd', data);
export const recruitJobPosting = (data: Record<string, any>) => recruitPost('recruitJobPosting', data);
export const recruitJobPostingSave = (id: number, lang: string, text: string) => recruitPost('recruitJobPostingSave', { id, lang, text });
// 发件人黑名单（猎头广告邮件拉黑后不再解析）
export const recruitBlockSender = (resume_id: number, scope: 'email' | 'domain', note = '') => recruitPost('recruitBlockSender', { resume_id, scope, note });
export const recruitSenderBlocks = () => recruitGet('recruitSenderBlocks');
export const recruitSenderUnblock = (id: number) => recruitPost('recruitSenderUnblock', { id });
export const recruitJobPostedSet = (id: number, platform: string, on: boolean) => recruitPost('recruitJobPostedSet', { id, platform, on: on ? 1 : 0 });
// 提示词自我迭代（招聘设置 · AI 提示词，recruit_admin；includes/recruit_prompts.php）
export const recruitPrompts = (scene: string) => recruitGet('recruitPrompts', { scene });
export const recruitPromptFeedback = (params: { scene: string; status?: string; page?: number }) => recruitGet('recruitPromptFeedback', params);
export const recruitPromptFeedbackSet = (id: number, status: string) => recruitPost('recruitPromptFeedbackSet', { id, status });
export const recruitPromptSave = (data: { scene: string; body: string; note?: string }) => recruitPost('recruitPromptSave', data);
export const recruitPromptEval = (id: number) => recruitPost('recruitPromptEval', { id });
export const recruitPromptActivate = (id: number) => recruitPost('recruitPromptActivate', { id });
export const recruitListCandidates = (params: Record<string, any>) => recruitGet('recruitListCandidates', params);
export const recruitGetCandidate = (id: number) => recruitGet('recruitGetCandidate', { id });
export const recruitRunPipeline = () => recruitPost('recruitRunPipeline', {});
export const recruitPipelineRuns = () => recruitGet('recruitPipelineRuns');
export const recruitPipelineRun = (id: number) => recruitGet('recruitPipelineRun', { id });
export const recruitStats = (params: { from?: string; to?: string } = {}) => recruitGet('recruitStats', params);
export const recruitTeamProgress = () => recruitGet('recruitTeamProgress');
export const recruitToggleFavorite = (candidate_id: number, on: boolean) => recruitPost('recruitToggleFavorite', { candidate_id, on: on ? 1 : 0 });
export const recruitAddFollowup = (data: Record<string, any>) => recruitPost('recruitAddFollowup', data);
export const recruitAddMatch = (candidate_id: number, job_id: number) => recruitPost('recruitAddMatch', { candidate_id, job_id });
export const recruitUpdateMatch = (id: number, human_score: number | null) => recruitPost('recruitUpdateMatch', { id, human_score });
export const recruitRemoveMatch = (id: number, reason = '') => recruitPost('recruitRemoveMatch', { id, reason });
export const recruitReparseResume = (id: number) => recruitPost('recruitReparseResume', { id });
export const recruitUpdateCandidate = (data: Record<string, any>) => recruitPost('recruitUpdateCandidate', data);
export const recruitSetOwner = (candidate_id: number, user_id: number) => recruitPost('recruitSetOwner', { candidate_id, user_id });
export const recruitMergeCandidates = (keep_id: number, drop_id: number) => recruitPost('recruitMergeCandidates', { keep_id, drop_id });
/** 简历原文件：带 token 拿 blob。⛔ 不能拼 <a href> 直链——浏览器打开链接不带 token，而且简历是个人信息 */
export async function recruitGetResumeFile(id: number): Promise<Blob> {
  return request('', { params: { action: 'recruitGetResumeFile', id }, responseType: 'blob', getResponse: false }) as any;
}
export const recruitResumesPage = (params: Record<string, any>) => recruitGet('recruitResumesPage', params);
export const recruitGetResume = (id: number) => recruitGet('recruitGetResume', { id });
export const recruitSimilarCandidates = (id: number, facet?: string) => recruitGet('recruitSimilarCandidates', { id, facet });
export const recruitRelatedJobs = (id: number) => recruitGet('recruitRelatedJobs', { id });
export const recruitRelatedPeople = (id: number) => recruitGet('recruitRelatedPeople', { id });
export const recruitSearch = (params: Record<string, any>) => recruitGet('recruitSearch', params);
export const recruitPools = () => recruitGet('recruitPools');
export const recruitParseQuery = (q: string) => recruitPost('recruitParseQuery', { q });
export const recruitTranslate = (owner_type: 'resume' | 'candidate', owner_id: number, lang: string) => recruitPost('recruitTranslate', { owner_type, owner_id, lang });
export const recruitTranslation = (id: number) => recruitGet('recruitTranslation', { id });
export const recruitSavePool = (data: Record<string, any>) => recruitPost('recruitSavePool', data);
export const recruitDeletePool = (id: number) => recruitPost('recruitDeletePool', { id });
export const recruitPoolMembers = (pool_id: number, add: number[] = [], remove: number[] = []) => recruitPost('recruitPoolMembers', { pool_id, add, remove });
export const recruitBulkAddMatch = (job_id: number, candidate_ids: number[]) => recruitPost('recruitBulkAddMatch', { job_id, candidate_ids });
export const recruitRecoGet = (candidate_id: number, job_id: number) => recruitGet('recruitRecoGet', { candidate_id, job_id });
export const recruitRecoGenerate = (data: { candidate_id: number; job_id: number; lang: string; customer_id?: number }) => recruitPost('recruitRecoGenerate', data);
export const recruitRecoSave = (data: { id: number; content: any }) => recruitPost('recruitRecoSave', data);
export const recruitRecoRewrite = (data: { id: number; text: string; instruction: string }) => recruitPost('recruitRecoRewrite', data);
export const recruitRecoMarkSent = (id: number, note: string) => recruitPost('recruitRecoMarkSent', { id, note });
export const recruitRecoSetClient = (id: number, customer_id: number) => recruitPost('recruitRecoSetClient', { id, customer_id });
export const recruitRecoUnlock = (id: number) => recruitPost('recruitRecoUnlock', { id });
export const recruitRecoTemplates = () => recruitGet('recruitRecoTemplates');
export const recruitRecoTemplateSave = (data: Record<string, any>) => recruitPost('recruitRecoTemplateSave', data);
export const recruitRecoTemplateArchive = (id: number, restore = false) => recruitPost('recruitRecoTemplateArchive', { id, restore });
export async function recruitRecoDocx(id: number, kind: 'letter' | 'resume'): Promise<Blob> {
  return request('', { params: { action: 'recruitRecoDocx', id, kind }, responseType: 'blob', getResponse: false }) as any;
}
export const recruitWorkbenchStats = (params: { range: string; user_id?: number }) => recruitGet('recruitWorkbenchStats', params);
export const recruitWorkbench = (user_id?: number) => recruitGet('recruitWorkbench', user_id ? { user_id } : {});
export const recruitMailboxes = () => recruitGet('recruitMailboxes');
export const recruitSaveMailbox = (data: Record<string, any>) => recruitPost('recruitSaveMailbox', data);
export const recruitPermList = () => recruitGet('recruitPermList');
export const recruitPermSet = (data: { user_id: number; module: string; on: number }) => recruitPost('recruitPermSet', data);
export const recruitConfigGet = () => recruitGet('recruitConfigGet');
export const recruitConfigSave = (data: Record<string, any>) => recruitPost('recruitConfigSave', data);
export const recruitMailRetry = (data: { id?: number; mailbox_id?: number }) => recruitPost('recruitMailRetry', data);
export const recruitSaveSource = (data: Record<string, any>) => recruitPost('recruitSaveSource', data);
// 招聘全生命周期（includes/handlers/recruit_lifecycle.php）：项目总览 / 条款 / 入职记录 / 结构化跟进 / 合同文件
export const recruitProjectOverview = (id: number) => recruitGet('recruitProjectOverview', { id });
export const recruitProjectPipeline = (id: number, bucket: string) => recruitGet('recruitProjectPipeline', { id, bucket });
export const recruitSaveProjectTerms = (data: Record<string, any>) => recruitPost('recruitSaveProjectTerms', data);
export const recruitSaveJobTerms = (data: Record<string, any>) => recruitPost('recruitSaveJobTerms', data);
// 企业库 / 标杆企业（includes/handlers/recruit_company.php）
export const recruitCompanies = (params: Record<string, any>) => recruitGet('recruitCompanies', params);
export const recruitCompanyDetail = (id: number) => recruitGet('recruitCompanyDetail', { id });
export const recruitCompanyPeople = (params: { id: number; bucket?: string; func?: string; sen?: string }) => recruitGet('recruitCompanyPeople', params);
export const recruitSaveCompany = (data: Record<string, any>) => recruitPost('recruitSaveCompany', data);
export const recruitMergeCompany = (from_id: number, into_id: number) => recruitPost('recruitMergeCompany', { from_id, into_id });
export const recruitIgnoreCompany = (id: number) => recruitPost('recruitIgnoreCompany', { id });
export const recruitCompanyIgnored = () => recruitGet('recruitCompanyIgnored');
export const recruitUnignoreCompany = (id: number) => recruitPost('recruitUnignoreCompany', { id });
export const recruitCompanyEnrich = (id: number) => recruitPost('recruitCompanyEnrich', { id });
export const recruitCompanyRelink = () => recruitPost('recruitCompanyRelink', {});
export const recruitCompanyViewers = () => recruitGet('recruitCompanyViewers');
export const recruitSaveCompanyViewers = (ids: number[]) => recruitPost('recruitSaveCompanyViewers', { ids });
export const recruitCompanyResearch = (data: { id?: number; name?: string }) => recruitPost('recruitCompanyResearch', data);
export const recruitCompanyResearchStatus = (id: number) => recruitGet('recruitCompanyResearchStatus', { id });
export const recruitCompanyRecent = () => recruitGet('recruitCompanyRecent');
export const recruitCompanyClassifyNow = () => recruitPost('recruitCompanyClassifyNow', {});
export const recruitSetCompanyEnrich = (enabled: boolean) => recruitPost('recruitSetCompanyEnrich', { enabled: enabled ? 1 : 0 });
// 职位投递链接（includes/handlers/recruit_apply.php）
export const recruitJobLink = (job_id: number) => recruitGet('recruitJobLink', { job_id });
export const recruitRevokeJobLink = (job_id: number) => recruitPost('recruitRevokeJobLink', { job_id });
// ⛔ 免登录的两个用原生 fetch：不带登录态、不走全局拦截（候选人没登录，拦截器会把人跳去登录页）
export async function recruitApplyInfo(token: string, lang: string, first = false) {
  const res = await fetch(`${API_BASE_URL}?action=recruitApplyInfo&token=${encodeURIComponent(token)}&lang=${encodeURIComponent(lang)}${first ? '&first=1' : ''}`);
  return res.json();
}
export async function recruitApplySubmit(fd: FormData) {
  const res = await fetch(`${API_BASE_URL}?action=recruitApplySubmit`, { method: 'POST', body: fd });
  return res.json();
}
export const recruitSegmentChat = (data: { message: string; industry?: string; history?: any[] }) => recruitPost('recruitSegmentChat', data);
export const recruitSegmentApply = (segments: any[]) => recruitPost('recruitSegmentApply', { segments });
export const recruitNetwork = (params: { type: string; ref: string; with?: string; max?: number }) => recruitGet('recruitNetwork', params);
export const recruitNetworkSearch = (q: string) => recruitGet('recruitNetworkSearch', { q });
export const recruitSegments = () => recruitGet('recruitSegments');
export const recruitSaveSegment = (data: Record<string, any>) => recruitPost('recruitSaveSegment', data);
export const recruitTitleFunctions = (params: Record<string, any>) => recruitGet('recruitTitleFunctions', params);
export const recruitSetTitleFunction = (data: { title_key: string; job_function: string; seniority: string }) => recruitPost('recruitSetTitleFunction', data);
export const recruitListPlacements = (params: { project_id?: number; candidate_id?: number }) => recruitGet('recruitListPlacements', params);
export const recruitSavePlacement = (data: Record<string, any>) => recruitPost('recruitSavePlacement', data);
export const recruitPlacementAction = (id: number, action: string, payload: Record<string, any> = {}) => recruitPost('recruitPlacementAction', { id, action, payload });
export const recruitUpdateFollowup = (data: Record<string, any>) => recruitPost('recruitUpdateFollowup', data);
export const recruitUpdateCandidateStatus = (data: Record<string, any>) => recruitPost('recruitUpdateCandidateStatus', data);
export const recruitListDocuments = (entity_type: string, entity_id: number) => recruitGet('recruitListDocuments', { entity_type, entity_id });
export const recruitDeleteDocument = (id: number) => recruitPost('recruitDeleteDocument', { id });
export async function recruitGetDocumentFile(id: number): Promise<Blob> {
  return request('', { params: { action: 'recruitGetDocumentFile', id }, responseType: 'blob', getResponse: false }) as any;
}


/** 新建一个独立客户（项目抽屉里搜不到客户时当场建）。返回 { success, data: { id } } */
export const createRecruitClient = (data: { name: string; legal_name?: string }) =>
  recruitPost('recruitCreateClient', data) as Promise<{ success: boolean; data?: { id: number }; message_key?: string; errorMessage?: string }>;
