<?php
/**
 * 招聘模块三语校验：后端的每个枚举值、每个错误 message_key、每个系统事件码，
 * 在 zh-CN / en-US / id-ID 三份 locale 里都必须有对应 key（§6.2）。
 * 前端是动态拼 key（t(`pages.recruit.status.${s}`)），缺了 build 不报错，页面直接露出 key 原文。
 *   php tests/recruit_i18n_enum_test.php
 */

$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_match.php';

$want = [];
$add = function (string $grp, array $vals) use (&$want) { foreach ($vals as $v) $want[] = "pages.recruit.$grp.$v"; };
$add('status', RECRUIT_CAND_STATUSES);
$add('stage', RECRUIT_STAGES);
$add('channel', RECRUIT_CHANNELS);
$add('edu', array_merge(RECRUIT_EDU_LEVELS, ['other']));
$add('industry', RECRUIT_INDUSTRIES);
$add('doc', RECRUIT_DOC_TYPES);
$add('parse', ['pending', 'processing', 'retry', 'parsed', 'failed', 'unsupported']);
$add('met', ['yes', 'partial', 'no', 'unknown']);
$add('flag', ['phone_conflict', 'email_ambiguous', 'no_contact']);
$add('emp', ['full_time', 'contract', 'internship', 'freelance']);
$add('origin', ['email', 'import', 'upload', 'manual']);   // 文件来源 + 匹配来源
$add('origin', ['apply']);                                                   // 职位投递链接（recruit_apply.php）
$add('event', ['applied_via_link', 'uploaded_to_job']);
$add('err', ['linkInvalid', 'applyPending']);
$add('err.apply', ['spam', 'nameRequired', 'phoneInvalid', 'emailInvalid', 'consentRequired', 'fileType', 'fileSize', 'tooMany', 'fileRequired', 'failed']);
$add('upload', ['added', 'duplicate']);
$add('warm', ['due', 'active_stage', 'never', 'strong_idle']);
$add('mailbox.failStatus', ['pending', 'gave_up']);
$add('run.status', ['queued', 'running', 'done', 'aborted', 'failed', 'skipped', 'stale']);   // AI 流水线运行记录                          // 邮件拉取失败清单
$add('pe.sec', RECRUIT_EDITABLE_SECTIONS);                                   // 档案可修正的段
$add('facet', RECRUIT_FACETS);                                              // 语义相似度条
$add('resume.mode', ['text', 'vision', 'dedupe', 'label']);                 // parse_mode 取值
$add('resume.card', array_merge(['all'], array_keys(recruitResumeViewSql())));   // 简历解析页统计卡，与后端 view 同源
// 企业库（recruit_company.php）
require_once $root . '/includes/recruit_company.php';
$add('func', RECRUIT_JOB_FUNCTIONS);
$add('seniority', RECRUIT_SENIORITY);
$add('tier', RECRUIT_TIERS);
$add('region', RECRUIT_REGIONS);
$add('co.bucket', RECRUIT_COMPANY_BUCKETS);
$add('co.conf', ['high', 'medium', 'low']);
$add('co.enrichStatus', ['none', 'queued', 'running', 'done', 'failed']);
$add('co.enrichErr', ['ambiguous', 'not_found', 'no_results', 'other']);
$add('co.src', ['ai', 'manual', 'pending']);
$add('co.cls', array_keys(array_diff_key(RECRUIT_CLASS_RANK, ['' => 1])));
$add('co.stage', ['queued', 'search', 'scrape', 'analyze', 'done']);
require_once $root . '/includes/recruit_network.php';
$add('net.type', ['candidate', 'company', 'industry', 'segment', 'school', 'project']);
$add('net.with', ['school', 'project', 'flow']);
$add('prompt.scene', array_keys(RECRUIT_PROMPT_SCENES));
$add('prompt.metric', array_keys(RECRUIT_EVAL_TOL));
$add('err', ['aliasTaken']);
// 招聘全生命周期（recruit_lifecycle.php）
$add('svc', RECRUIT_SERVICE_TYPES);
$add('svcHint', RECRUIT_SERVICE_TYPES);
$add('feeType', RECRUIT_FEE_TYPES);
$add('remedy', RECRUIT_REMEDIES);
$add('milestone', RECRUIT_BILL_MILESTONES);
$add('contract', RECRUIT_CONTRACT_STATUSES);
$add('plStatus', RECRUIT_PL_STATUSES);
$add('plAction', array_keys(RECRUIT_PL_TRANSITIONS));
$add('plActionHint', array_keys(RECRUIT_PL_TRANSITIONS));
$add('fuKind', RECRUIT_FU_KINDS);
$add('ivType', RECRUIT_INTERVIEW_TYPES);
$add('ivResult', RECRUIT_INTERVIEW_RESULTS);
$add('offerStatus', RECRUIT_OFFER_STATUSES);
$add('intent', RECRUIT_INTENTS);
$add('docType', RECRUIT_LC_DOC_TYPES);
$add('reasonSide', array_keys(RECRUIT_REASON_CODES));
foreach (RECRUIT_REASON_CODES as $g => $codes) $add("reason.$g", $codes);
$kpis = array_merge(array_keys(RECRUIT_KPI_LINK), array_keys(RECRUIT_KPI_PL));
$add('kpi', $kpis);
$add('kpi', array_map(fn($b) => "{$b}Tip", $kpis));
$add('event', array_merge(RECRUIT_PL_EVENTS, ['interview_result', 'offer_result', 'status_fields', 'doc_uploaded']));

// 后端实际抛出的 message_key 与写入的 event_code / error_kind：直接扫源码，新增了就自动纳入
$src = file_get_contents("$root/includes/handlers/recruit.php") . file_get_contents("$root/includes/recruit_parse.php")
     . file_get_contents("$root/includes/recruit_match.php") . file_get_contents("$root/includes/handlers/recruit_lifecycle.php") . file_get_contents("$root/includes/handlers/recruit_company.php");
preg_match_all("/recruit(?:Err|LcFail)\\('([a-zA-Z]+)'/", $src, $m);               $add('err', array_unique($m[1]));
preg_match_all("/'system', '([a-z_]+)'/", $src, $m1);
preg_match_all("/recruitAddSystemFollowup\\(\\\$pdo, [^,]+, '([a-z_]+)'/", $src, $m2);
preg_match_all("/'(owner_changed|owner_assigned)'/", $src, $m3);
$add('event', array_unique(array_merge($m1[1], $m2[1], $m3[1])));
preg_match_all("/(?:recruitMarkParseFailure\\([^)]*?, '|parse_error_kind'\\] ?=== ?')([a-z_]+)'/", $src, $m4);
$add('errKind', array_unique(array_merge($m4[1], ['timeout', 'network', 'server_busy', 'bad_output', 'rejected', 'not_configured'])));
$want = array_values(array_unique($want));

$fails = 0;
foreach (['zh-CN', 'en-US', 'id-ID'] as $loc) {
    $txt = file_get_contents("$root/../frontend/src/locales/$loc.ts");
    $miss = array_values(array_filter($want, fn($k) => strpos($txt, "'$k':") === false));
    echo ($miss ? '  ✗ ' : '  ✓ ') . "{$loc}：" . count($want) . ' 个 key' . ($miss ? '，缺 ' . implode(', ', $miss) : '') . "\n";
    if ($miss) $fails++;
}
echo $fails === 0 ? "全部通过\n" : "失败 $fails 项\n";
exit($fails === 0 ? 0 : 1);
