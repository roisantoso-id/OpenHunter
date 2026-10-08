<?php
/**
 * 招聘项目全生命周期（2026-09-24）：委托条款 · 入职记录（placement）· 保证期 · 结构化跟进 · 合同与文件 · 项目总览。
 *
 * 模型：项目 = 一份客户委托（条款在 recruit_projects 上）；每次入职一行 recruit_placements，状态机：
 *   offer_accepted ─start→ started ─pass_guarantee→ guarantee_passed ─leave→ ended
 *        │                    └─leave（保证期内）→ left_in_guarantee ─replace→ replaced（同事务建替补行，费用 0）
 *        ├─no_show / backed_out                                        ├─refund_recorded→ refunded（admin）
 *        └──────────────────────────────────────────────────────────── └─close→ closed（admin）
 * 「保证期内」= status='started'（过保要人确认或 P3 cron 自动推）。
 *
 * 口径单点：
 *   费用公式 recruitPlacementFee() ⇔ 前端 common.tsx calcPlacementFee()，改一处必须改另一处。
 *   项目 KPI 与下钻共用 recruitProjectKpiSql()（§6.7.1），数字 = 点开的行数。
 * ⛔ 文档 file_path 永不下发前端（serveFile 不鉴权），只经 recruitGetDocumentFile 鉴权后取。
 * 表结构：scripts/data-fixes/add_recruit_lifecycle_20260924.php；没跑脚本前 recruitLcReady()=false，新功能报 migrationPending，老功能照常。
 */

const RECRUIT_SERVICE_TYPES = ['contingency', 'retained', 'rpo_per_hire', 'rpo_monthly', 'eor', 'outsourcing'];
const RECRUIT_MONTHLY_SERVICES = ['rpo_monthly', 'eor', 'outsourcing'];
const RECRUIT_FEE_TYPES = ['percent_of_salary', 'flat_per_hire', 'monthly'];
const RECRUIT_REMEDIES = ['replacement', 'refund', 'prorata', 'none'];
const RECRUIT_BILL_MILESTONES = ['offer_accepted', 'start', 'guarantee_end'];
const RECRUIT_CONTRACT_STATUSES = ['draft', 'sent', 'signed', 'expired', 'terminated'];
const RECRUIT_CURRENCIES = ['IDR', 'CNY', 'USD', 'SGD'];

const RECRUIT_PL_STATUSES = ['offer_accepted', 'started', 'guarantee_passed', 'left_in_guarantee', 'replaced', 'refunded', 'closed', 'ended', 'no_show', 'backed_out'];
/** 仍「占着」这个人 × 职位的状态：同一 link 只能有一条 */
const RECRUIT_PL_ACTIVE = ['offer_accepted', 'started', 'guarantee_passed'];
/** action => [允许的起始状态, 目标状态（null = 由 action 自己算）, 是否仅 admin] */
const RECRUIT_PL_TRANSITIONS = [
    'start'           => [['offer_accepted'], 'started', false],
    'pass_guarantee'  => [['started'], 'guarantee_passed', false],
    'leave'           => [['started', 'guarantee_passed'], null, false],
    'no_show'         => [['offer_accepted'], 'no_show', false],
    'backed_out'      => [['offer_accepted'], 'backed_out', false],
    'replace'         => [['left_in_guarantee'], 'replaced', false],
    'refund_recorded' => [['left_in_guarantee'], 'refunded', true],
    'close'           => [['left_in_guarantee', 'no_show', 'backed_out'], 'closed', true],
];
/** 系统事件码（时间线 / i18n）：placement_<action>，另加 create / edit */
const RECRUIT_PL_EVENTS = ['placement_create', 'placement_edit', 'placement_start', 'placement_pass_guarantee', 'placement_leave',
    'placement_no_show', 'placement_backed_out', 'placement_replace', 'placement_refund_recorded', 'placement_close'];

const RECRUIT_FU_KINDS = ['note', 'interview', 'offer', 'reject'];   // 人写的；system 是系统事件。「人工跟进」口径一律写 kind<>'system'（recruit.php / recruit_warm.php）
const RECRUIT_INTERVIEW_TYPES = ['phone', 'video', 'onsite', 'test'];
const RECRUIT_INTERVIEW_RESULTS = ['pending', 'passed', 'failed', 'cancelled', 'no_show'];
const RECRUIT_OFFER_STATUSES = ['awaiting', 'accepted', 'rejected'];
/** 原因码（淘汰 / 撤回 / 离职）按责任方分组 */
const RECRUIT_REASON_CODES = [
    'client'    => ['skill_gap', 'experience_gap', 'language', 'culture_fit', 'salary_too_high', 'position_closed', 'other_candidate', 'no_feedback'],
    'candidate' => ['salary_low', 'counter_offer', 'other_offer', 'location', 'family', 'role_mismatch', 'no_response', 'not_interested'],
    'agency'    => ['not_qualified', 'fake_resume', 'unreachable', 'duplicate'],
    'leave'     => ['resigned', 'dismissed', 'probation_failed', 'performance', 'relocation', 'health', 'contract_end', 'other'],
];
const RECRUIT_LC_DOC_TYPES = ['client_agreement', 'nda', 'guarantee_addendum', 'offer_letter', 'employment_contract', 'id_doc', 'other'];
const RECRUIT_LC_DOC_BY_ENTITY = [
    'project'   => ['client_agreement', 'nda', 'guarantee_addendum', 'other'],
    'placement' => ['offer_letter', 'employment_contract', 'id_doc', 'other'],
    'candidate' => ['offer_letter', 'employment_contract', 'id_doc', 'other'],
];
/** 项目级合同类文件只有 recruit_admin 能传 / 删（条款同权限） */
const RECRUIT_LC_DOC_ADMIN = ['client_agreement', 'nda', 'guarantee_addendum'];
const RECRUIT_LC_DOC_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
const RECRUIT_INTENTS = ['hot', 'warm', 'cold'];

/** 业务错误：纯函数抛它，handler 接住转 recruitErr（测试可以直接断言 key） */
class RecruitLcError extends RuntimeException {
    public string $key;
    public array $extra;
    public function __construct(string $key, array $extra = []) { parent::__construct($key); $this->key = $key; $this->extra = $extra; }
}
function recruitLcFail(string $key, array $extra = []): never { throw new RecruitLcError($key, $extra); }
function recruitLcRun(callable $fn) {
    try { return $fn(); }
    catch (RecruitLcError $e) { recruitErr($e->key, $e->key, 200, $e->extra); }
}

/** 迁移脚本跑过没有（代码先上线、脚本后跑的窗口期不能 500） */
function recruitLcReady(PDO $pdo): bool {
    static $memo = null;
    if ($memo !== null) return $memo;
    try { $pdo->query("SELECT detail_json FROM recruit_followups WHERE 1=0"); $pdo->query("SELECT id FROM recruit_placements WHERE 1=0"); $memo = true; }
    catch (PDOException $e) { $memo = false; }
    return $memo;
}
function recruitLcRequire(PDO $pdo): void { if (!recruitLcReady($pdo)) recruitLcFail('migrationPending'); }

function recruitLcDate($v): ?string {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
}
function recruitLcDateTime($v): ?string {
    $v = trim((string)$v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $v)) return null;
    return str_replace('T', ' ', strlen($v) === 16 ? "$v:00" : $v);
}
function recruitLcMoney($v): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    $f = round((float)$v, 2);
    return $f < 0 ? null : $f;
}
function recruitLcToday(): string { return date('Y-m-d'); }
/** 币种归一：老商机存的是 RMB，招聘这边统一叫 CNY（不归一的话「与商机币种一致」校验永远过不了） */
function recruitCcy($v, string $default = 'IDR'): string {
    $c = strtoupper(trim((string)$v));
    if ($c === 'RMB') $c = 'CNY';
    return $c === '' ? $default : $c;
}

// =====================================================================
// 条款
// =====================================================================

/** 各合作模式的默认计费方式与里程碑（新项目 / 老项目回填 / 切换模式时用） */
function recruitDefaultBillingTerms(string $svc): array {
    if (in_array($svc, RECRUIT_MONTHLY_SERVICES, true)) return ['milestones' => [], 'bill_day' => 1];
    if ($svc === 'retained') return ['milestones' => [['code' => 'offer_accepted', 'percent' => 30], ['code' => 'start', 'percent' => 70]]];
    return ['milestones' => [['code' => 'start', 'percent' => 100]]];
}
function recruitDefaultFeeType(string $svc): string {
    return in_array($svc, RECRUIT_MONTHLY_SERVICES, true) ? 'monthly' : 'percent_of_salary';
}

/**
 * 里程碑归一：code 去重、% 合计必须 = 100 或全 0（0 = 不自动开单，财务手工）。
 * ⛔ 合计 <100 时，最后一个任务完成会被 payments.php maybeGenerateBalanceInvoice 自动补尾款 → 与里程碑重复开单（P2 风险 R4）
 */
function recruitNormalizeBillingTerms(string $svc, $in): array {
    $in = is_string($in) ? (json_decode($in, true) ?: []) : (is_array($in) ? $in : []);
    if (in_array($svc, RECRUIT_MONTHLY_SERVICES, true)) {
        $d = (int)($in['bill_day'] ?? 1);
        return ['milestones' => [], 'bill_day' => $d >= 1 && $d <= 28 ? $d : 1];
    }
    $ms = []; $sum = 0;
    foreach ((array)($in['milestones'] ?? []) as $m) {
        $code = (string)($m['code'] ?? '');
        $pct = (int)($m['percent'] ?? 0);
        if (!in_array($code, RECRUIT_BILL_MILESTONES, true) || isset($ms[$code])) continue;
        if ($pct < 0 || $pct > 100) recruitLcFail('milestonePercent');
        $ms[$code] = $pct; $sum += $pct;
    }
    if ($sum !== 0 && $sum !== 100) recruitLcFail('milestoneSum', ['sum' => $sum]);
    $out = [];
    foreach (RECRUIT_BILL_MILESTONES as $c) if (isset($ms[$c])) $out[] = ['code' => $c, 'percent' => $ms[$c]];
    return ['milestones' => $out];
}

/** 商业条款列（项目默认 + 职位覆盖共用这一组；币种 / 合同 / 主体只在项目上） */
const RECRUIT_TERM_COLS = ['service_type', 'fee_type', 'fee_percent', 'fee_basis_months', 'flat_fee', 'monthly_fee',
                           'guarantee_days', 'guarantee_remedy', 'replacement_count', 'billing_terms'];

/**
 * 校验并归一一组商业条款（项目默认条款、职位条款共用，规则只写这一份）。
 * @return array RECRUIT_TERM_COLS => 值（billing_terms 已 json_encode）
 */
function recruitNormalizeTerms(array $in): array {
    $svc = (string)($in['service_type'] ?? '');
    if (!in_array($svc, RECRUIT_SERVICE_TYPES, true)) recruitLcFail('badServiceType');
    $monthly = in_array($svc, RECRUIT_MONTHLY_SERVICES, true);
    $feeType = (string)($in['fee_type'] ?? recruitDefaultFeeType($svc));
    if (!in_array($feeType, RECRUIT_FEE_TYPES, true) || ($monthly !== ($feeType === 'monthly'))) recruitLcFail('badFeeType');
    $pct = recruitLcMoney($in['fee_percent'] ?? null);
    if ($pct !== null && $pct > 100) recruitLcFail('feePercentRange');
    $basis = (int)($in['fee_basis_months'] ?? 12);
    if ($basis < 1 || $basis > 24) recruitLcFail('feeBasisRange');
    $flat = recruitLcMoney($in['flat_fee'] ?? null);
    $mfee = recruitLcMoney($in['monthly_fee'] ?? null);
    if ($feeType === 'percent_of_salary' && $pct === null) recruitLcFail('feePercentRequired');
    if ($feeType === 'flat_per_hire' && $flat === null) recruitLcFail('flatFeeRequired');
    if ($feeType === 'monthly' && $mfee === null) recruitLcFail('monthlyFeeRequired');
    $gd = (int)($in['guarantee_days'] ?? 90);
    if ($gd < 0 || $gd > 365) recruitLcFail('guaranteeRange');
    $remedy = (string)($in['guarantee_remedy'] ?? 'replacement');
    if (!in_array($remedy, RECRUIT_REMEDIES, true)) recruitLcFail('badRemedy');
    $repl = (int)($in['replacement_count'] ?? 1);
    if ($repl < 0 || $repl > 5) recruitLcFail('replacementRange');
    if ($remedy !== 'replacement') $repl = 0;
    return ['service_type' => $svc, 'fee_type' => $feeType, 'fee_percent' => $pct, 'fee_basis_months' => $basis, 'flat_fee' => $flat,
            'monthly_fee' => $mfee, 'guarantee_days' => $gd, 'guarantee_remedy' => $remedy, 'replacement_count' => $repl,
            'billing_terms' => json_encode(recruitNormalizeBillingTerms($svc, $in['billing_terms'] ?? []))];
}

/** 项目的合作模式（多选，逗号分隔存 service_types）归一：只留合法值、去重、按枚举顺序 */
function recruitNormalizeServiceTypes($v): string {
    $arr = is_array($v) ? $v : explode(',', (string)$v);
    $arr = array_map(fn($x) => trim((string)$x), $arr);
    return implode(',', array_values(array_filter(RECRUIT_SERVICE_TYPES, fn($t) => in_array($t, $arr, true))));
}

/** 保存项目默认条款 + 合同信息（recruit_admin）。@return array 保存后的项目行 */
function recruitSaveProjectTermsCore(PDO $pdo, int $pid, array $in, int $uid, string $uname): array {
    recruitLcRequire($pdo);
    $st = $pdo->prepare("SELECT p.* FROM recruit_projects p WHERE p.id=?");
    $st->execute([$pid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) recruitLcFail('notFound');
    $t = recruitNormalizeTerms($in + ['service_type' => $p['service_type']]);

    $ccy = recruitCcy($in['currency'] ?? $p['currency']);
    if (!in_array($ccy, RECRUIT_CURRENCIES, true)) recruitLcFail('badCurrency');
    $cs = (string)($in['contract_status'] ?? 'draft');
    if (!in_array($cs, RECRUIT_CONTRACT_STATUSES, true)) recruitLcFail('badContractStatus');
    $signed = recruitLcDate($in['contract_signed_at'] ?? '');
    if ($cs === 'signed' && $signed === null) $signed = recruitLcToday();
    $cStart = recruitLcDate($in['contract_start'] ?? '');
    $cEnd = recruitLcDate($in['contract_end'] ?? '');
    if ($cStart && $cEnd && $cEnd < $cStart) recruitLcFail('contractDates');
    $ent = 0;   // 签约主体（客户名下多个法人）不在 OpenHunter 里，列保留恒为 0
    $deposit = recruitLcMoney($in['deposit_amount'] ?? null);
    $note = mb_substr(trim((string)($in['terms_note'] ?? '')), 0, 2000);
    // 默认模式一定在项目的合作模式标签里
    $types = recruitNormalizeServiceTypes(array_merge(explode(',', (string)($p['service_types'] ?? '')), [$t['service_type']]));

    $pdo->prepare("UPDATE recruit_projects SET " . implode(', ', array_map(fn($c) => "$c=?", RECRUIT_TERM_COLS)) . ", service_types=?, currency=?,
                          deposit_amount=?, contract_status=?, contract_signed_at=?, contract_start=?, contract_end=?, client_entity_id=?, terms_note=?,
                          terms_set_at=(" . dbNow() . "), updated_at=(" . dbNow() . ") WHERE id=?")
        ->execute(array_merge(array_values($t), [$types, $ccy, $deposit, $cs, $signed, $cStart, $cEnd, $ent, $note, $pid]));
    if (function_exists('logOperation')) {
        logOperation($pdo, $uid, $uname, 'recruit_project_terms', 'recruit_project', $pid,
            json_encode(['service_type' => $t['service_type'], 'fee_type' => $t['fee_type'], 'fee_percent' => $t['fee_percent'],
                         'guarantee_days' => $t['guarantee_days'], 'remedy' => $t['guarantee_remedy'], 'contract_status' => $cs], JSON_UNESCAPED_UNICODE));
    }
    $st->execute([$pid]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

/**
 * 职位条款（2026-09-25「一个项目可能是混合的：EOR、RPO、猎头」）：职位 service_type='' = 沿用项目默认条款；
 * 否则用职位自己的整组条款（不做逐字段继承，避免「模式是 EOR、费率却继承猎头 20%」这种拼出来的组合）。
 * $in['inherit']=1 清回沿用。模式自动加进项目的合作模式标签。
 */
function recruitSaveJobTermsCore(PDO $pdo, int $jobId, array $in, int $uid, string $uname): array {
    recruitLcRequire($pdo);
    $st = $pdo->prepare("SELECT j.id, j.project_id, p.service_types FROM recruit_jobs j JOIN recruit_projects p ON p.id=j.project_id WHERE j.id=?");
    $st->execute([$jobId]);
    $j = $st->fetch(PDO::FETCH_ASSOC);
    if (!$j) recruitLcFail('notFound');
    if (!empty($in['inherit'])) {
        $pdo->prepare("UPDATE recruit_jobs SET service_type='', fee_type='', fee_percent=NULL, fee_basis_months=NULL, flat_fee=NULL, monthly_fee=NULL,
                              guarantee_days=NULL, guarantee_remedy='', replacement_count=NULL, billing_terms=NULL, updated_at=(" . dbNow() . ") WHERE id=?")
            ->execute([$jobId]);
    } else {
        $t = recruitNormalizeTerms($in);
        $pdo->prepare("UPDATE recruit_jobs SET " . implode(', ', array_map(fn($c) => "$c=?", RECRUIT_TERM_COLS)) . ", updated_at=(" . dbNow() . ") WHERE id=?")
            ->execute(array_merge(array_values($t), [$jobId]));
        $pdo->prepare("UPDATE recruit_projects SET service_types=? WHERE id=?")
            ->execute([recruitNormalizeServiceTypes(array_merge(explode(',', (string)$j['service_types']), [$t['service_type']])), (int)$j['project_id']]);
    }
    if (function_exists('logOperation')) {
        logOperation($pdo, $uid, $uname, 'recruit_job_terms', 'recruit_job', $jobId, json_encode($in, JSON_UNESCAPED_UNICODE));
    }
    return recruitJobTerms($pdo, $jobId);
}

/**
 * 某职位实际生效的条款：职位自己定了就用职位的，否则用项目默认。币种永远取项目（一个项目一个商机、一个币种）。
 * @return array RECRUIT_TERM_COLS + currency, kind, own(bool), billing_terms(数组)
 */
function recruitJobTerms(PDO $pdo, int $jobId): array {
    $st = $pdo->prepare("SELECT j.service_type j_svc, " . implode(', ', array_map(fn($c) => "j.$c j_$c, p.$c p_$c", array_slice(RECRUIT_TERM_COLS, 1))) . ",
                                p.service_type p_service_type, p.currency, p.kind FROM recruit_jobs j JOIN recruit_projects p ON p.id=j.project_id WHERE j.id=?");
    $st->execute([$jobId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) recruitLcFail('notFound');
    $own = (string)$r['j_svc'] !== '';
    $out = ['own' => $own, 'currency' => recruitCcy($r['currency'] ?: 'IDR'), 'kind' => $r['kind']];
    foreach (RECRUIT_TERM_COLS as $c) $out[$c] = $c === 'service_type' ? ($own ? $r['j_svc'] : $r['p_service_type']) : ($own ? $r["j_$c"] : $r["p_$c"]);
    $out['billing_terms'] = json_decode((string)$out['billing_terms'], true) ?: recruitDefaultBillingTerms((string)$out['service_type']);
    return $out;
}

/** 新项目写默认条款（recruitSaveProject 新建时调；币种默认 OPENHUNTER_DEFAULT_CURRENCY，未配为 IDR） */
function recruitApplyDefaultTerms(PDO $pdo, int $pid): void {
    if (!recruitLcReady($pdo)) return;
    $ccy = recruitCcy(function_exists('ohEnv') ? ohEnv('OPENHUNTER_DEFAULT_CURRENCY', 'IDR') : 'IDR');
    if (!in_array($ccy, RECRUIT_CURRENCIES, true)) $ccy = 'IDR';
    // 默认模式 = 新建时勾的第一个合作模式（没勾就是猎头）
    $ts = $pdo->prepare("SELECT COALESCE(service_types,'') FROM recruit_projects WHERE id=?");
    $ts->execute([$pid]);
    $types = recruitNormalizeServiceTypes((string)$ts->fetchColumn()) ?: 'contingency';
    $svc = explode(',', $types)[0];
    $pdo->prepare("UPDATE recruit_projects SET service_type=?, fee_type=?, fee_basis_months=12, currency=?, guarantee_days=90,
                          guarantee_remedy='replacement', replacement_count=1, billing_terms=?, contract_status='draft', terms_set_at=(" . dbNow() . "),
                          service_types=? WHERE id=? AND terms_set_at IS NULL")
        ->execute([$svc, recruitDefaultFeeType($svc), $ccy, json_encode(recruitDefaultBillingTerms($svc)), $types, $pid]);
}

// =====================================================================
// 入职记录（placement）
// =====================================================================

/**
 * 费用：按月薪百分比 = 月薪 × 基数月数 × 费率%；固定 = flat_fee；月费模式 = null（按人按月另算，P2）。
 * ⛔ 与前端 common.tsx calcPlacementFee() 同公式，改一处必须改另一处。
 */
function recruitPlacementFee(array $project, ?float $salaryMonthly): ?float {
    switch ((string)($project['fee_type'] ?? 'percent_of_salary')) {
        case 'flat_per_hire': return $project['flat_fee'] !== null && $project['flat_fee'] !== '' ? round((float)$project['flat_fee'], 2) : null;
        case 'monthly': return null;
        default:
            if ($salaryMonthly === null || $project['fee_percent'] === null || $project['fee_percent'] === '') return null;
            return round($salaryMonthly * max(1, (int)($project['fee_basis_months'] ?? 12)) * (float)$project['fee_percent'] / 100, 2);
    }
}
function recruitGuaranteeEnd(?string $start, int $days): ?string {
    return $start ? date('Y-m-d', strtotime("$start +$days days")) : null;
}

/** 时间线事件（带职位）。placement 的每一步都在候选人时间线上留痕 */
function recruitLcEvent(PDO $pdo, int $cid, int $linkId, string $event, string $content, int $uid, string $uname, string $stageBefore = '', string $stageAfter = ''): void {
    // 顺带改了职位阶段的，stage_before / stage_after 一起写：看板「录用 / Offer / 撤回」数按 stage_after 统计
    $pdo->prepare("INSERT INTO recruit_followups (candidate_id, candidate_job_id, kind, event_code, content, stage_before, stage_after, created_by, created_by_name, created_at)
                   VALUES (?, ?, 'system', ?, ?, ?, ?, ?, ?, (" . dbNow() . "))")
        ->execute([$cid, $linkId, $event, mb_substr($content, 0, 2000), $stageBefore, $stageAfter, $uid, $uname]);
}

function recruitPlacementLoad(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT pl.*, p.kind proj_kind
                         FROM recruit_placements pl JOIN recruit_projects p ON p.id=pl.project_id WHERE pl.id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) recruitLcFail('notFound');
    return $r;
}

/** 这个 人 × 职位 仍在进行中的 placement id（没有 = 0） */
function recruitActivePlacementId(PDO $pdo, int $linkId): int {
    $st = $pdo->prepare("SELECT id FROM recruit_placements WHERE candidate_job_id=? AND status IN ('" . implode("','", RECRUIT_PL_ACTIVE) . "') ORDER BY id DESC LIMIT 1");
    $st->execute([$linkId]);
    return (int)$st->fetchColumn();
}

/**
 * 登记入职（建一条 offer_accepted）。**不开事务**：由调用方包（跟进里推到 hired / 补人都在各自事务里调它）。
 * $draft=true：跟进推 hired 时自动建的草稿，薪资 / 入职日可空，页面提示补全。
 * @return array{id:int, link_id:int, stage_before:string, candidate_id:int}
 */
function recruitPlacementCreate(PDO $pdo, int $linkId, array $in, int $uid, string $uname, bool $admin = false, bool $draft = false, ?array $replacing = null): array {
    recruitLcRequire($pdo);
    // 锁住这条 人×职位（MySQL 行锁，调用方已开事务）：双击 / 两人同时登记不会建出两条进行中的记录
    if (dbIsMysql()) $pdo->prepare("SELECT id FROM recruit_candidate_jobs WHERE id=? FOR UPDATE")->execute([$linkId]);
    $st = $pdo->prepare("SELECT cj.id, cj.stage, cj.candidate_id, cj.job_id, j.project_id, j.title, p.* FROM recruit_candidate_jobs cj
                         JOIN recruit_jobs j ON j.id=cj.job_id JOIN recruit_projects p ON p.id=j.project_id WHERE cj.id=?");
    $st->execute([$linkId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) recruitLcFail('matchNotFound');
    if (!in_array($l['stage'], array_merge(RECRUIT_ACTIVE_STAGES, ['hired']), true)) recruitLcFail('plBadStage');
    if ($ex = recruitActivePlacementId($pdo, $linkId)) recruitLcFail('plExists', ['placement_id' => $ex]);
    if ($replacing && (int)$replacing['job_id'] !== (int)$l['job_id']) recruitLcFail('plReplaceJob');
    // 条款取职位（职位没定就是项目默认），建行时快照：之后改条款不影响已登记的人
    $terms = recruitJobTerms($pdo, (int)$l['job_id']);

    $salary = recruitLcMoney($in['offer_salary_monthly'] ?? null);
    // 费用 = 月薪 × 费率，所以薪资币种必须 = 项目（合同）币种，否则费用合计混币种。
    // 自动草稿（跟进推 hired）遇到 Offer 币种不同：薪资留空待人工补，不猜汇率
    $projCcy = recruitCcy($l['currency'] ?: 'IDR');
    $ccy = recruitCcy($in['currency'] ?? '', $projCcy);
    if (!in_array($ccy, RECRUIT_CURRENCIES, true)) recruitLcFail('badCurrency');
    if ($ccy !== $projCcy) {
        if (!$draft) recruitLcFail('currencyMismatch', ['opp_currency' => $projCcy]);
        $ccy = $projCcy; $salary = null;
    }
    $expStart = recruitLcDate($in['expected_start_date'] ?? '');
    if (!$draft && !$replacing) {
        if ($expStart === null) recruitLcFail('plStartRequired');
        if ($terms['fee_type'] === 'percent_of_salary' && $l['kind'] !== 'internal' && $salary === null) recruitLcFail('plSalaryRequired');
    }
    $fee = $replacing ? 0.0 : ($l['kind'] === 'internal' ? null : recruitPlacementFee($terms, $salary));
    $feeNote = $replacing ? 'replacement' : '';
    if ($admin && array_key_exists('fee_amount', $in) && $in['fee_amount'] !== '' && $in['fee_amount'] !== null && !$replacing) {
        $fee = recruitLcMoney($in['fee_amount']);
        $feeNote = mb_substr(trim((string)($in['fee_note'] ?? 'manual')), 0, 255);
    }
    $gd = (int)$terms['guarantee_days'];
    // 替补行沿用原行的模式 / 保证期处理 / 补人额度，整条替补链按同一份合同算
    $svc = $replacing ? (string)$replacing['service_type'] : (string)$terms['service_type'];
    $remedy = $replacing ? (string)$replacing['guarantee_remedy'] : (string)$terms['guarantee_remedy'];
    $quota = $replacing ? (int)$replacing['replacement_quota'] : (int)$terms['replacement_count'];
    $pdo->prepare("INSERT INTO recruit_placements (project_id, job_id, candidate_id, candidate_job_id, replacement_of_placement_id, replacement_seq, status,
                          offer_date, offer_accepted_at, expected_start_date, offer_salary_monthly, offer_salary_annual, currency, allowances_text,
                          guarantee_days, guarantee_remedy, fee_amount, fee_note, notes, status_changed_at, status_changed_by,
                          created_by, created_by_name, created_at, updated_at, service_type, replacement_quota)
                   VALUES (?, ?, ?, ?, ?, ?, 'offer_accepted', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (" . dbNow() . "), ?, ?, ?, (" . dbNow() . "), (" . dbNow() . "), ?, ?)")
        ->execute([(int)$l['project_id'], (int)$l['job_id'], (int)$l['candidate_id'], $linkId,
                   $replacing ? (int)($replacing['replacement_of_placement_id'] ?: $replacing['id']) : 0,
                   $replacing ? (int)$replacing['replacement_seq'] + 1 : 0,
                   recruitLcDate($in['offer_date'] ?? ''), recruitLcDate($in['offer_accepted_at'] ?? '') ?? recruitLcToday(), $expStart,
                   $salary, $salary !== null ? round($salary * 12, 2) : null, $ccy, mb_substr(trim((string)($in['allowances_text'] ?? '')), 0, 255),
                   $gd, $remedy, $fee, $feeNote, mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000), $uid, $uid, $uname, $svc, $quota]);
    $id = (int)$pdo->lastInsertId();
    // 联动：职位阶段 → hired、候选人 → placed
    $before = (string)$l['stage'];
    if ($before !== 'hired') {
        $pdo->prepare("UPDATE recruit_candidate_jobs SET stage='hired', stage_changed_at=(" . dbNow() . "), stage_changed_by=?, updated_at=(" . dbNow() . ") WHERE id=?")
            ->execute([$uid, $linkId]);
    }
    $pdo->prepare("UPDATE recruit_candidates SET status='placed', status_changed_at=(" . dbNow() . "), updated_at=(" . dbNow() . ") WHERE id=? AND status<>'placed'")
        ->execute([(int)$l['candidate_id']]);
    $sum = $l['title'] . ($salary !== null ? " · $ccy " . number_format($salary, 0, '.', ',') : '') . ($expStart ? " · $expStart" : '')
         . ($replacing ? ' · #' . $replacing['id'] : '');
    recruitLcEvent($pdo, (int)$l['candidate_id'], $linkId, 'placement_create', $sum, $uid, $uname, $before !== 'hired' ? $before : '', $before !== 'hired' ? 'hired' : '');
    return ['id' => $id, 'link_id' => $linkId, 'stage_before' => $before, 'candidate_id' => (int)$l['candidate_id']];
}

/**
 * placement 状态流转的唯一写入口。乐观锁：UPDATE … WHERE status=旧状态，0 行 = 被别人先改了 → plStateChanged。
 * @return array{id:int, status:string, new_id?:int, notify?:array}
 */
function recruitPlacementTransition(PDO $pdo, int $plId, string $action, array $p, int $uid, string $uname, bool $admin): array {
    recruitLcRequire($pdo);
    if (!isset(RECRUIT_PL_TRANSITIONS[$action])) recruitLcFail('plBadAction');
    [$from, $to, $adminOnly] = RECRUIT_PL_TRANSITIONS[$action];
    if ($adminOnly && !$admin) recruitLcFail('forbidden');
    $pl = recruitPlacementLoad($pdo, $plId);
    if (!in_array($pl['status'], $from, true)) recruitLcFail('plBadTransition', ['status' => $pl['status']]);
    $set = []; $args = []; $content = ''; $linkStage = null; $newPl = null; $notify = null;
    $note = mb_substr(trim((string)($p['notes'] ?? $p['note'] ?? '')), 0, 2000);
    $today = recruitLcToday();

    switch ($action) {
        case 'start':
            $d = recruitLcDate($p['actual_start_date'] ?? '') ?? $today;
            $gd = (int)$pl['guarantee_days'];
            $set = ['actual_start_date=?', 'guarantee_end_date=?']; $args = [$d, recruitGuaranteeEnd($d, $gd)];
            if ($gd === 0) $to = 'guarantee_passed';
            $content = $d . ($gd ? ' → ' . recruitGuaranteeEnd($d, $gd) : '');
            break;
        case 'pass_guarantee':
            if ($pl['guarantee_end_date'] && $pl['guarantee_end_date'] > $today && !$admin) recruitLcFail('plGuaranteeNotDue', ['end' => $pl['guarantee_end_date']]);
            $content = (string)$pl['guarantee_end_date'];
            break;
        case 'leave':
            $d = recruitLcDate($p['left_at'] ?? '');
            if ($d === null) recruitLcFail('plLeftAtRequired');
            if ($pl['actual_start_date'] && $d < $pl['actual_start_date']) recruitLcFail('plLeftBeforeStart');
            $rc = (string)($p['left_reason_code'] ?? '');
            if (!in_array($rc, RECRUIT_REASON_CODES['leave'], true)) recruitLcFail('reasonRequired');
            $inG = $pl['status'] === 'started' && $pl['guarantee_end_date'] && $d <= $pl['guarantee_end_date'];
            $to = $inG ? 'left_in_guarantee' : 'ended';
            $set = ['left_at=?', 'left_reason_code=?', 'left_note=?']; $args = [$d, $rc, $note];
            if ($inG) {
                $set[] = 'remedy_outcome=?'; $args[] = 'pending';
                $fee = $pl['fee_amount'] !== null ? (float)$pl['fee_amount'] : null;
                $refund = null;
                if ($fee !== null && $pl['guarantee_remedy'] === 'refund') $refund = $fee;
                if ($fee !== null && $pl['guarantee_remedy'] === 'prorata' && (int)$pl['guarantee_days'] > 0) {
                    $left = max(0, (int)round((strtotime($pl['guarantee_end_date']) - strtotime($d)) / 86400));
                    $refund = round($fee * $left / (int)$pl['guarantee_days'], 2);
                }
                $set[] = 'expected_refund_amount=?'; $args[] = $refund;
            }
            $content = "$d · $rc" . ($note !== '' ? " · $note" : '');
            break;
        case 'no_show':
        case 'backed_out':
            $rc = (string)($p['left_reason_code'] ?? $p['reason_code'] ?? '');
            if (!in_array($rc, RECRUIT_REASON_CODES['candidate'], true)) recruitLcFail('reasonRequired');
            $set = ['left_reason_code=?', 'left_note=?']; $args = [$rc, $note];
            $linkStage = 'withdrawn';
            $content = $rc . ($note !== '' ? " · $note" : '');
            break;
        case 'replace':
            $newLink = (int)($p['candidate_job_id'] ?? 0);
            if ($newLink <= 0) recruitLcFail('plReplaceLinkRequired');
            $quota = (int)$pl['replacement_quota'];   // 登记时快照的补人额度（事后改条款不影响已登记的人）
            if ($pl['guarantee_remedy'] !== 'replacement' || (int)$pl['replacement_seq'] + 1 > $quota) recruitLcFail('plReplaceQuota', ['quota' => $quota]);
            $set = ['remedy_outcome=?']; $args = ['replaced'];
            break;
        case 'refund_recorded':
            $set = ['remedy_outcome=?', 'refund_payment_id=?', 'notes=?']; $args = ['refunded', (int)($p['refund_payment_id'] ?? 0), $note !== '' ? $note : (string)$pl['notes']];
            $content = $note;
            break;
        case 'close':
            $set = ['remedy_outcome=?', 'notes=?']; $args = [$pl['remedy_outcome'] !== '' && $pl['remedy_outcome'] !== 'pending' ? $pl['remedy_outcome'] : 'waived', $note !== '' ? $note : (string)$pl['notes']];
            $content = $note;
            break;
    }

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $sql = "UPDATE recruit_placements SET status=?, " . ($set ? implode(', ', $set) . ', ' : '')
             . "status_changed_at=(" . dbNow() . "), status_changed_by=?, updated_at=(" . dbNow() . ") WHERE id=? AND status=?";
        $u = $pdo->prepare($sql);
        $u->execute(array_merge([$to], $args, [$uid, $plId, $pl['status']]));
        if ($u->rowCount() === 0) recruitLcFail('plStateChanged');
        if ($linkStage !== null) {
            $ls = $pdo->prepare("SELECT stage FROM recruit_candidate_jobs WHERE id=?");
            $ls->execute([(int)$pl['candidate_job_id']]);
            $sb = (string)$ls->fetchColumn();
            if ($sb !== $linkStage) {
                $pdo->prepare("UPDATE recruit_candidate_jobs SET stage=?, stage_changed_at=(" . dbNow() . "), stage_changed_by=?, updated_at=(" . dbNow() . ") WHERE id=?")
                    ->execute([$linkStage, $uid, (int)$pl['candidate_job_id']]);
                $notify = ['link' => (int)$pl['candidate_job_id'], 'before' => $sb, 'after' => $linkStage];
            }
            $pdo->prepare("UPDATE recruit_candidates SET status='in_process', status_changed_at=(" . dbNow() . "), updated_at=(" . dbNow() . ")
                           WHERE id=? AND status='placed' AND NOT EXISTS (SELECT 1 FROM recruit_placements x WHERE x.candidate_id=? AND x.id<>?
                             AND x.status IN ('" . implode("','", RECRUIT_PL_ACTIVE) . "'))")
                ->execute([(int)$pl['candidate_id'], (int)$pl['candidate_id'], $plId]);
        }
        if ($action === 'replace') {
            $newPl = recruitPlacementCreate($pdo, (int)$p['candidate_job_id'], $p, $uid, $uname, $admin, true, $pl);
            $content = '#' . $newPl['id'];
            if ($newPl['stage_before'] !== 'hired') $notify = ['link' => $newPl['link_id'], 'before' => $newPl['stage_before'], 'after' => 'hired'];
        }
        $ev = $notify && $notify['link'] === (int)$pl['candidate_job_id'] ? $notify : null;
        recruitLcEvent($pdo, (int)$pl['candidate_id'], (int)$pl['candidate_job_id'], "placement_$action", $content, $uid, $uname,
            $ev['before'] ?? '', $ev['after'] ?? '');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['id' => $plId, 'status' => $to] + ($newPl ? ['new_id' => $newPl['id']] : []) + ($notify ? ['notify' => $notify] : []);
}

/** 改入职记录的信息（不改状态）。终态只能改备注；费用只有 admin 能改 */
function recruitPlacementEdit(PDO $pdo, int $plId, array $in, int $uid, string $uname, bool $admin): void {
    recruitLcRequire($pdo);
    $pl = recruitPlacementLoad($pdo, $plId);
    $set = []; $args = [];
    $active = in_array($pl['status'], RECRUIT_PL_ACTIVE, true);
    if (array_key_exists('notes', $in)) { $set[] = 'notes=?'; $args[] = mb_substr(trim((string)$in['notes']), 0, 2000); }
    if ($active) {
        foreach (['offer_date', 'offer_accepted_at', 'expected_start_date'] as $k) {
            if (array_key_exists($k, $in)) { $set[] = "$k=?"; $args[] = recruitLcDate($in[$k]); }
        }
        if (array_key_exists('allowances_text', $in)) { $set[] = 'allowances_text=?'; $args[] = mb_substr(trim((string)$in['allowances_text']), 0, 255); }
        if (array_key_exists('currency', $in) && (string)$in['currency'] !== '') {
            $c = recruitCcy($in['currency']);
            $pc = $pdo->prepare("SELECT currency FROM recruit_projects WHERE id=?");
            $pc->execute([(int)$pl['project_id']]);
            $projCcy = recruitCcy($pc->fetchColumn() ?: 'IDR');
            if ($c !== $projCcy) recruitLcFail('currencyMismatch', ['opp_currency' => $projCcy]);   // 币种跟合同走，不单独改
        }
        if (array_key_exists('offer_salary_monthly', $in)) {
            $s = recruitLcMoney($in['offer_salary_monthly']);
            $set[] = 'offer_salary_monthly=?'; $args[] = $s;
            $set[] = 'offer_salary_annual=?'; $args[] = $s !== null ? round($s * 12, 2) : null;
            // 薪资改了：没人工定过费用的，按条款重算
            if ($pl['fee_note'] === '' && (int)$pl['replacement_of_placement_id'] === 0) {
                $terms = recruitJobTerms($pdo, (int)$pl['job_id']);
                $set[] = 'fee_amount=?'; $args[] = $terms['kind'] === 'internal' ? null : recruitPlacementFee($terms, $s);
            }
        }
        if ($pl['status'] !== 'offer_accepted' && array_key_exists('actual_start_date', $in)) {
            $d = recruitLcDate($in['actual_start_date']);
            if ($d === null) recruitLcFail('plStartRequired');
            $set[] = 'actual_start_date=?'; $args[] = $d;
            $set[] = 'guarantee_end_date=?'; $args[] = recruitGuaranteeEnd($d, (int)$pl['guarantee_days']);
        }
    }
    if (array_key_exists('fee_amount', $in)) {
        if (!$admin) recruitLcFail('forbidden');
        $set[] = 'fee_amount=?'; $args[] = recruitLcMoney($in['fee_amount']);
        $set[] = 'fee_note=?'; $args[] = mb_substr(trim((string)($in['fee_note'] ?? 'manual')), 0, 255) ?: 'manual';
    }
    if (!$set) return;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE recruit_placements SET " . implode(', ', $set) . ", updated_at=(" . dbNow() . ") WHERE id=?")->execute(array_merge($args, [$plId]));
        recruitLcEvent($pdo, (int)$pl['candidate_id'], (int)$pl['candidate_job_id'], 'placement_edit',
            implode(', ', array_map(fn($s) => explode('=', $s)[0], $set)), $uid, $uname);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** 入职记录列表行（项目页 / 候选人抽屉共用） */
function recruitPlacementRows(PDO $pdo, string $where, array $args): array {
    $st = $pdo->prepare("SELECT pl.*, c.name cand_name, c.owner_user_name, j.title job_title, p.name project_name, COALESCE(cu.name,'') customer_group_name
                         FROM recruit_placements pl JOIN recruit_candidates c ON c.id=pl.candidate_id JOIN recruit_jobs j ON j.id=pl.job_id
                         JOIN recruit_projects p ON p.id=pl.project_id LEFT JOIN recruit_clients cu ON cu.id=p.customer_id
                         WHERE $where ORDER BY pl.status IN ('" . implode("','", RECRUIT_PL_ACTIVE) . "') DESC, pl.id DESC");
    $st->execute($args);
    $today = strtotime(recruitLcToday());
    return array_map(function ($r) use ($today) {
        $r['cand_code'] = recruitCandCode((int)$r['candidate_id']);
        $r['guarantee_left'] = $r['status'] === 'started' && $r['guarantee_end_date'] ? (int)round((strtotime($r['guarantee_end_date']) - $today) / 86400) : null;
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

// =====================================================================
// 结构化跟进（面试 / Offer / 淘汰）
// =====================================================================

/**
 * 按 kind 归一 detail，并推导阶段：
 *   interview：必填 scheduled_at；阶段没到面试 → 推到 interviewing
 *   offer    ：必填月薪 + 状态；accepted → 推到 offered；rejected → 必填候选人侧原因，推到 withdrawn
 *   reject   ：必填 side + reason_code；client/agency → rejected，candidate → withdrawn
 * @return array{detail:array, reason:string, stage:string, scheduled:?string}
 */
function recruitFollowupDetail(string $kind, array $d, string $stageBefore, string $stageAfter, string $reason): array {
    $rank = RECRUIT_STAGE_RANK[$stageBefore] ?? 0;
    $sched = null; $out = [];
    if ($kind === 'interview') {
        $sched = recruitLcDateTime($d['scheduled_at'] ?? '');
        if ($sched === null) recruitLcFail('ivTimeRequired');
        $type = (string)($d['type'] ?? 'video');
        $res = (string)($d['result'] ?? 'pending');
        $out = ['round' => max(1, min(10, (int)($d['round'] ?? 1))), 'type' => in_array($type, RECRUIT_INTERVIEW_TYPES, true) ? $type : 'video',
                'scheduled_at' => $sched, 'interviewer' => mb_substr(trim((string)($d['interviewer'] ?? '')), 0, 191),
                'location' => mb_substr(trim((string)($d['location'] ?? '')), 0, 255),
                'result' => in_array($res, RECRUIT_INTERVIEW_RESULTS, true) ? $res : 'pending', 'feedback' => mb_substr(trim((string)($d['feedback'] ?? '')), 0, 2000)];
        if ($stageAfter === '' && $rank > 0 && $rank < RECRUIT_STAGE_RANK['interviewing']) $stageAfter = 'interviewing';
    } elseif ($kind === 'offer') {
        $sal = recruitLcMoney($d['salary_monthly'] ?? null);
        if ($sal === null) recruitLcFail('offerSalaryRequired');
        $status = (string)($d['status'] ?? 'awaiting');
        if (!in_array($status, RECRUIT_OFFER_STATUSES, true)) $status = 'awaiting';
        $ccy = strtoupper((string)($d['currency'] ?? 'IDR'));
        $out = ['salary_monthly' => $sal, 'currency' => in_array($ccy, RECRUIT_CURRENCIES, true) ? $ccy : 'IDR',
                'allowances' => mb_substr(trim((string)($d['allowances'] ?? '')), 0, 255), 'start_date' => recruitLcDate($d['start_date'] ?? ''),
                'status' => $status];
        if ($status === 'rejected') {
            if (!in_array($reason, RECRUIT_REASON_CODES['candidate'], true)) recruitLcFail('reasonRequired');
            if ($stageAfter === '') $stageAfter = 'withdrawn';
        } else {
            $reason = '';
            if ($stageAfter === '' && $rank > 0 && $rank < RECRUIT_STAGE_RANK['offered']) $stageAfter = 'offered';
        }
    } elseif ($kind === 'reject') {
        $side = (string)($d['side'] ?? '');
        if (!in_array($side, ['client', 'candidate', 'agency'], true)) recruitLcFail('reasonRequired');
        if (!in_array($reason, RECRUIT_REASON_CODES[$side], true)) recruitLcFail('reasonRequired');
        $out = ['side' => $side];
        $stageAfter = $side === 'candidate' ? 'withdrawn' : 'rejected';
    }
    // 任何「淘汰 / 撤回」都要原因码，报表才统计得出流失原因
    if (in_array($stageAfter, ['rejected', 'withdrawn'], true)) {
        $all = array_merge(RECRUIT_REASON_CODES['client'], RECRUIT_REASON_CODES['candidate'], RECRUIT_REASON_CODES['agency']);
        if (!in_array($reason, $all, true)) recruitLcFail('reasonRequired');
    } elseif ($kind !== 'offer' || ($out['status'] ?? '') !== 'rejected') {
        $reason = '';
    }
    return ['detail' => $out, 'reason' => $reason, 'stage' => $stageAfter, 'scheduled' => $sched];
}

/**
 * 写一条跟进（人工）。recruit.php handleRecruitAddFollowup 调它；测试直接调。
 * @return array{id:int, stage_before:string, stage_after:string, link_id:int, placement_id?:int, placement_draft?:bool}
 */
function recruitAddFollowupCore(PDO $pdo, array $in, int $uid, string $uname, bool $admin = false): array {
    $cid = (int)($in['candidate_id'] ?? 0);
    $kind = (string)($in['kind'] ?? 'note');
    if (!in_array($kind, RECRUIT_FU_KINDS, true)) $kind = 'note';
    $ready = recruitLcReady($pdo);
    if ($kind !== 'note' && !$ready) recruitLcFail('migrationPending');
    $content = mb_substr(trim((string)($in['content'] ?? '')), 0, 2000);
    $channel = in_array($in['channel'] ?? '', RECRUIT_CHANNELS, true) ? (string)$in['channel'] : '';
    $statusAfter = in_array($in['status_after'] ?? '', RECRUIT_CAND_STATUSES, true) ? (string)$in['status_after'] : '';
    $linkId = (int)($in['candidate_job_id'] ?? 0);
    $stageAfter = in_array($in['stage_after'] ?? '', RECRUIT_STAGES, true) && ($in['stage_after'] ?? '') !== 'removed' ? (string)$in['stage_after'] : '';
    $next = recruitLcDateTime($in['next_follow_at'] ?? '') ?? (recruitLcDate($in['next_follow_at'] ?? '') ? $in['next_follow_at'] . ' 09:00:00' : null);
    $reason = trim((string)($in['reason_code'] ?? ''));

    $st = $pdo->prepare("SELECT status FROM recruit_candidates WHERE id=?");
    $st->execute([$cid]);
    $before = $st->fetchColumn();
    if ($before === false) recruitLcFail('notFound');
    $stageBefore = '';
    if ($linkId > 0) {
        $ls = $pdo->prepare("SELECT stage FROM recruit_candidate_jobs WHERE id=? AND candidate_id=?");
        $ls->execute([$linkId, $cid]);
        $stageBefore = $ls->fetchColumn();
        if ($stageBefore === false) recruitLcFail('matchNotFound');
        $stageBefore = (string)$stageBefore;
    } elseif ($stageAfter !== '' || $kind !== 'note') recruitLcFail('matchRequired');

    $detail = null; $sched = null;
    if ($ready) {
        $norm = recruitFollowupDetail($kind, is_array($in['detail'] ?? null) ? $in['detail'] : [], $stageBefore, $stageAfter, $reason);
        $detail = $norm['detail'] ?: null; $reason = $norm['reason']; $stageAfter = $norm['stage']; $sched = $norm['scheduled'];
        if ($kind === 'interview' && $next === null && ($detail['result'] ?? '') === 'pending') $next = $sched;
    }
    if ($kind === 'note' && $content === '' && $statusAfter === '' && $stageAfter === '') recruitLcFail('contentRequired');
    // 已录用且有进行中的入职记录：阶段不能直接改走，要在入职记录上走「未到岗 / 反悔 / 离职」，两边才对得上
    if ($ready && $stageBefore === 'hired' && $stageAfter !== '' && $stageAfter !== 'hired' && recruitActivePlacementId($pdo, $linkId)) recruitLcFail('plActiveBlocks');

    $out = ['stage_before' => $stageBefore, 'stage_after' => $stageAfter, 'link_id' => $linkId];
    $pdo->beginTransaction();
    try {
        if ($ready) {
            $pdo->prepare("INSERT INTO recruit_followups (candidate_id, candidate_job_id, kind, channel, content, status_before, status_after,
                                  stage_before, stage_after, next_follow_at, detail_json, reason_code, scheduled_at, created_by, created_by_name, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (" . dbNow() . "))")
                ->execute([$cid, $linkId, $kind, $channel, $content, $statusAfter !== '' ? $before : '', $statusAfter,
                           $stageAfter !== '' ? $stageBefore : '', $stageAfter, $next, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                           $reason, $sched, $uid, $uname]);
        } else {
            $pdo->prepare("INSERT INTO recruit_followups (candidate_id, candidate_job_id, kind, channel, content, status_before, status_after,
                                  stage_before, stage_after, next_follow_at, created_by, created_by_name, created_at)
                           VALUES (?, ?, 'note', ?, ?, ?, ?, ?, ?, ?, ?, ?, (" . dbNow() . "))")
                ->execute([$cid, $linkId, $channel, $content, $statusAfter !== '' ? $before : '', $statusAfter,
                           $stageAfter !== '' ? $stageBefore : '', $stageAfter, $next, $uid, $uname]);
        }
        $out['id'] = (int)$pdo->lastInsertId();
        $set = "last_followup_at=(" . dbNow() . "), next_followup_at=?, updated_at=(" . dbNow() . ")";
        $args = [$next];
        if ($statusAfter !== '' && $statusAfter !== $before) { $set .= ", status=?, status_changed_at=(" . dbNow() . ")"; $args[] = $statusAfter; }
        $pdo->prepare("UPDATE recruit_candidates SET $set WHERE id=?")->execute(array_merge($args, [$cid]));
        if ($stageAfter !== '' && $stageAfter !== $stageBefore) {
            $pdo->prepare("UPDATE recruit_candidate_jobs SET stage=?, stage_changed_at=(" . dbNow() . "), stage_changed_by=?, updated_at=(" . dbNow() . ") WHERE id=?")
                ->execute([$stageAfter, $uid, $linkId]);
        }
        // 推到「已录用」→ 同事务建入职记录草稿（薪资 / 入职日从最近一条 Offer 预取），页面随即提示补全
        if ($ready && $stageAfter === 'hired' && $linkId > 0 && !recruitActivePlacementId($pdo, $linkId)) {
            $of = $pdo->prepare("SELECT detail_json FROM recruit_followups WHERE candidate_job_id=? AND kind='offer' ORDER BY id DESC LIMIT 1");
            $of->execute([$linkId]);
            $od = json_decode((string)$of->fetchColumn(), true) ?: [];
            $pl = recruitPlacementCreate($pdo, $linkId, ['offer_salary_monthly' => $od['salary_monthly'] ?? null, 'currency' => $od['currency'] ?? '',
                'expected_start_date' => $od['start_date'] ?? '', 'allowances_text' => $od['allowances'] ?? ''], $uid, $uname, $admin, true);
            $out['placement_id'] = $pl['id'];
            $out['placement_draft'] = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $out;
}

/** 改面试结果 / Offer 状态（跟进卡片上直接点）。追加系统事件，原记录保留创建人 */
function recruitUpdateFollowupCore(PDO $pdo, int $id, array $in, int $uid, string $uname): array {
    recruitLcRequire($pdo);
    $st = $pdo->prepare("SELECT * FROM recruit_followups WHERE id=?");
    $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f || !in_array($f['kind'], ['interview', 'offer'], true)) recruitLcFail('notFound');
    $d = json_decode((string)$f['detail_json'], true) ?: [];
    $reason = (string)$f['reason_code'];
    $stage = '';
    if ($f['kind'] === 'interview') {
        $res = (string)($in['result'] ?? $d['result'] ?? 'pending');
        if (!in_array($res, RECRUIT_INTERVIEW_RESULTS, true)) recruitLcFail('badResult');
        $d['result'] = $res;
        if (array_key_exists('feedback', $in)) $d['feedback'] = mb_substr(trim((string)$in['feedback']), 0, 2000);
        $event = 'interview_result'; $content = $res . (($d['feedback'] ?? '') !== '' ? ' · ' . $d['feedback'] : '');
    } else {
        $s = (string)($in['status'] ?? $d['status'] ?? 'awaiting');
        if (!in_array($s, RECRUIT_OFFER_STATUSES, true)) recruitLcFail('badResult');
        if ($s === 'rejected') {
            $reason = (string)($in['reason_code'] ?? $reason);
            if (!in_array($reason, RECRUIT_REASON_CODES['candidate'], true)) recruitLcFail('reasonRequired');
            $stage = 'withdrawn';
        } elseif ($s === 'accepted') { $reason = ''; $stage = 'offered'; }
        $d['status'] = $s;
        $event = 'offer_result'; $content = $s . ($reason !== '' ? " · $reason" : '');
    }
    $link = (int)$f['candidate_job_id'];
    $notify = null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE recruit_followups SET detail_json=?, reason_code=?, updated_at=(" . dbNow() . ") WHERE id=?")
            ->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $reason, $id]);
        if ($stage !== '' && $link > 0) {
            $ls = $pdo->prepare("SELECT stage FROM recruit_candidate_jobs WHERE id=?");
            $ls->execute([$link]);
            $sb = (string)$ls->fetchColumn();
            $move = $stage === 'withdrawn' ? !in_array($sb, ['withdrawn', 'hired', 'removed'], true)
                                           : (RECRUIT_STAGE_RANK[$sb] ?? 0) > 0 && (RECRUIT_STAGE_RANK[$sb] ?? 0) < RECRUIT_STAGE_RANK['offered'];
            if ($move) {
                $pdo->prepare("UPDATE recruit_candidate_jobs SET stage=?, stage_changed_at=(" . dbNow() . "), stage_changed_by=?, updated_at=(" . dbNow() . ") WHERE id=?")
                    ->execute([$stage, $uid, $link]);
                $notify = ['link' => $link, 'before' => $sb, 'after' => $stage];
            }
        }
        recruitLcEvent($pdo, (int)$f['candidate_id'], $link, $event, $content, $uid, $uname, $notify['before'] ?? '', $notify['after'] ?? '');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['detail' => $d] + ($notify ? ['notify' => $notify] : []);
}

// =====================================================================
// 候选人现状（人工维护，不进 locked_fields、不触发重建）
// =====================================================================

function recruitUpdateCandidateStatusCore(PDO $pdo, int $cid, array $in, int $uid, string $uname): void {
    recruitLcRequire($pdo);
    $set = []; $args = [];
    foreach (['current_salary', 'expected_salary_amt'] as $k) if (array_key_exists($k, $in)) { $set[] = "$k=?"; $args[] = recruitLcMoney($in[$k]); }
    if (array_key_exists('salary_currency', $in)) {
        $c = strtoupper((string)$in['salary_currency']);
        $set[] = 'salary_currency=?'; $args[] = in_array($c, RECRUIT_CURRENCIES, true) ? $c : '';
    }
    if (array_key_exists('notice_period_days', $in)) {
        $n = $in['notice_period_days'];
        $set[] = 'notice_period_days=?'; $args[] = $n === '' || $n === null ? null : max(0, min(365, (int)$n));
    }
    if (array_key_exists('is_employed', $in)) {
        $e = $in['is_employed'];
        $set[] = 'is_employed=?'; $args[] = $e === '' || $e === null ? null : ((int)$e ? 1 : 0);
    }
    if (array_key_exists('intent', $in)) { $set[] = 'intent=?'; $args[] = in_array($in['intent'], RECRUIT_INTENTS, true) ? $in['intent'] : ''; }
    if (array_key_exists('availability_date', $in)) { $set[] = 'availability_date=?'; $args[] = recruitLcDate($in['availability_date']); }
    if (!$set) return;
    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare("UPDATE recruit_candidates SET " . implode(', ', $set) . ", status_fields_at=(" . dbNow() . "), updated_at=(" . dbNow() . ") WHERE id=?");
        $u->execute(array_merge($args, [$cid]));
        if ($u->rowCount() === 0) {
            $ck = $pdo->prepare("SELECT 1 FROM recruit_candidates WHERE id=?");
            $ck->execute([$cid]);
            if (!$ck->fetchColumn()) recruitLcFail('notFound');
        }
        recruitLcEvent($pdo, $cid, 0, 'status_fields', implode(', ', array_map(fn($s) => explode('=', $s)[0], $set)), $uid, $uname);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

// =====================================================================
// 项目总览：KPI 与下钻同一份 SQL（§6.7.1）
// =====================================================================

const RECRUIT_KPI_LINK = [
    'candidates'   => "cj.stage<>'removed'",
    'matched'      => "cj.stage<>'removed' AND COALESCE(cj.human_score, cj.ai_score) >= 3",
    'submitted'    => "cj.stage IN ('submitted','interviewing','offered','hired')",
    'interviewing' => "cj.stage='interviewing'",
    'offered'      => "cj.stage='offered'",
    'hired'        => "cj.stage='hired'",
    'dropped'      => "cj.stage IN ('rejected','withdrawn')",
];
const RECRUIT_KPI_PL = [
    'pending_start' => "pl.status='offer_accepted'",
    'in_guarantee'  => "pl.status='started'",
    'passed'        => "pl.status IN ('guarantee_passed','ended')",
    'lost'          => "pl.status IN ('left_in_guarantee','replaced','refunded','closed','no_show','backed_out')",
];

/**
 * 一个 KPI 桶的 FROM+WHERE。汇总（COUNT）与下钻（行）都从这里拿，数字 = 点开的行数。
 * 看不到全部的招聘专员（recruitScopeOwner 非 null）两边同样只算自己名下的人。
 * @return array{0:string,1:array,2:string} [sql, args, 'link'|'pl']
 */
function recruitProjectKpiSql(int $pid, string $bucket, ?int $scope): array {
    if (isset(RECRUIT_KPI_LINK[$bucket])) {
        $sql = "FROM recruit_candidate_jobs cj JOIN recruit_jobs j ON j.id=cj.job_id JOIN recruit_candidates c ON c.id=cj.candidate_id
                WHERE j.project_id=? AND " . RECRUIT_KPI_LINK[$bucket];
        $type = 'link';
    } elseif (isset(RECRUIT_KPI_PL[$bucket])) {
        $sql = "FROM recruit_placements pl JOIN recruit_jobs j ON j.id=pl.job_id JOIN recruit_candidates c ON c.id=pl.candidate_id
                WHERE pl.project_id=? AND " . RECRUIT_KPI_PL[$bucket];
        $type = 'pl';
    } else recruitLcFail('badBucket');
    $args = [$pid];
    if ($scope !== null) $sql .= " AND " . recruitVisibleSql($scope);
    return [$sql, $args, $type];
}

function recruitProjectKpis(PDO $pdo, int $pid, ?int $scope): array {
    $out = [];
    $buckets = array_keys(RECRUIT_KPI_LINK);
    if (recruitLcReady($pdo)) $buckets = array_merge($buckets, array_keys(RECRUIT_KPI_PL));
    foreach ($buckets as $b) {
        [$sql, $args] = recruitProjectKpiSql($pid, $b, $scope);
        $st = $pdo->prepare("SELECT COUNT(*) $sql");
        $st->execute($args);
        $out[$b] = (int)$st->fetchColumn();
    }
    return $out;
}

function recruitProjectPipelineRows(PDO $pdo, int $pid, string $bucket, ?int $scope): array {
    [$sql, $args, $type] = recruitProjectKpiSql($pid, $bucket, $scope);
    if ($type === 'pl') {
        $st = $pdo->prepare("SELECT pl.id $sql");
        $st->execute($args);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        return $ids ? recruitPlacementRows($pdo, 'pl.id IN (' . implode(',', $ids) . ')', []) : [];
    }
    $st = $pdo->prepare("SELECT cj.id, cj.candidate_id, cj.job_id, cj.stage, cj.stage_changed_at, cj.ai_score, cj.human_score, cj.origin,
                                j.title job_title, c.name cand_name, c.owner_user_name, c.status cand_status, c.last_followup_at, c.next_followup_at
                         $sql ORDER BY cj.stage_changed_at DESC, cj.id DESC LIMIT 1000");
    $st->execute($args);
    return array_map(function ($r) { $r['cand_code'] = recruitCandCode((int)$r['candidate_id']); return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/** 费用合计（按币种）：作废的（未到岗 / 反悔 / 已退款）不算 */
function recruitProjectFeeTotals(PDO $pdo, int $pid): array {
    if (!recruitLcReady($pdo)) return [];
    $st = $pdo->prepare("SELECT currency, SUM(fee_amount) s, COUNT(*) n FROM recruit_placements
                         WHERE project_id=? AND fee_amount IS NOT NULL AND status NOT IN ('no_show','backed_out','refunded') GROUP BY currency");
    $st->execute([$pid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['currency']] = round((float)$r['s'], 2);
    return $out;
}
/** 按合作模式分开的费用 / 人数（混合项目：猎头多少、EOR 多少）。口径同 recruitProjectFeeTotals */
function recruitProjectFeeByType(PDO $pdo, int $pid): array {
    if (!recruitLcReady($pdo)) return [];
    $st = $pdo->prepare("SELECT service_type, currency, SUM(COALESCE(fee_amount,0)) s, COUNT(*) n FROM recruit_placements
                         WHERE project_id=? AND status NOT IN ('no_show','backed_out','refunded') GROUP BY service_type, currency ORDER BY service_type");
    $st->execute([$pid]);
    return array_map(fn($r) => ['service_type' => $r['service_type'], 'currency' => $r['currency'], 'fee' => round((float)$r['s'], 2), 'count' => (int)$r['n']],
        $st->fetchAll(PDO::FETCH_ASSOC));
}

// =====================================================================
// 合同与文件
// =====================================================================

/** 文件属于哪个项目 / 候选人（权限判定用） @return array{project_id:int, candidate_id:int} */
function recruitDocOwner(PDO $pdo, string $type, int $id): array {
    if ($type === 'project') {
        $st = $pdo->prepare("SELECT id FROM recruit_projects WHERE id=?");
        $st->execute([$id]);
        if (!$st->fetchColumn()) recruitLcFail('notFound');
        return ['project_id' => $id, 'candidate_id' => 0];
    }
    if ($type === 'placement') {
        $st = $pdo->prepare("SELECT project_id, candidate_id FROM recruit_placements WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) recruitLcFail('notFound');
        return ['project_id' => (int)$r['project_id'], 'candidate_id' => (int)$r['candidate_id']];
    }
    if ($type === 'candidate') {
        $st = $pdo->prepare("SELECT id FROM recruit_candidates WHERE id=?");
        $st->execute([$id]);
        if (!$st->fetchColumn()) recruitLcFail('notFound');
        return ['project_id' => 0, 'candidate_id' => $id];
    }
    recruitLcFail('badEntity');
}

/**
 * 记一份文件（文件已存好，$path 是 uploadAttachment 的返回）。
 * 客户协议 → 项目合同状态置 signed。
 */
function recruitDocSave(PDO $pdo, string $type, int $entityId, string $docType, array $in, string $path, string $name, int $size, int $uid, string $uname): int {
    recruitLcRequire($pdo);
    if (!in_array($docType, RECRUIT_LC_DOC_BY_ENTITY[$type] ?? [], true)) recruitLcFail('badDocType');
    $own = recruitDocOwner($pdo, $type, $entityId);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $signed = recruitLcDate($in['signed_at'] ?? '');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO recruit_documents (entity_type, entity_id, project_id, doc_type, title, file_name, file_path, file_ext, file_size,
                              signed_at, valid_until, notes, uploaded_by, uploaded_by_name, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (" . dbNow() . "))")
            ->execute([$type, $entityId, $own['project_id'], $docType, mb_substr(trim((string)($in['title'] ?? '')), 0, 191), mb_substr($name, 0, 255), $path,
                       mb_substr($ext, 0, 8), $size, $signed, recruitLcDate($in['valid_until'] ?? ''), mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000), $uid, $uname]);
        $docId = (int)$pdo->lastInsertId();
        if ($type === 'project' && $docType === 'client_agreement') {
            $pdo->prepare("UPDATE recruit_projects SET contract_status='signed', contract_signed_at=COALESCE(contract_signed_at, ?), updated_at=(" . dbNow() . ") WHERE id=?")
                ->execute([$signed ?? recruitLcToday(), $entityId]);
        }
        if ($own['candidate_id'] > 0) recruitLcEvent($pdo, $own['candidate_id'], 0, 'doc_uploaded', $docType . ' · ' . $name, $uid, $uname);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $docId;
}

/** 软删 */
function recruitDocDelete(PDO $pdo, int $id): void {
    $st = $pdo->prepare("SELECT id FROM recruit_documents WHERE id=? AND deleted_at IS NULL");
    $st->execute([$id]);
    if ($st->fetchColumn() === false) recruitLcFail('notFound');
    $pdo->prepare("UPDATE recruit_documents SET deleted_at=(" . dbNow() . ") WHERE id=?")->execute([$id]);
}

/** 文件列表（⛔ 不含 file_path） */
function recruitDocRows(PDO $pdo, string $where, array $args): array {
    $st = $pdo->prepare("SELECT id, entity_type, entity_id, project_id, doc_type, title, file_name, file_ext, file_size, signed_at, valid_until,
                                opportunity_file_id, notes, uploaded_by_name, created_at FROM recruit_documents WHERE deleted_at IS NULL AND ($where) ORDER BY id DESC");
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 读 / 写一份文件的权限：项目级合同类写 = admin；挂在人身上的按候选人可见范围 */
function recruitDocGuard(PDO $pdo, int $uid, string $type, int $entityId, string $docType, bool $write): void {
    $own = recruitDocOwner($pdo, $type, $entityId);
    if ($write && in_array($docType, RECRUIT_LC_DOC_ADMIN, true) && !userHasModule($pdo, $uid, 'recruit_admin')) recruitLcFail('forbidden');
    if ($own['candidate_id'] > 0) recruitGuardCandidate($pdo, $uid, $own['candidate_id']);
}

// =====================================================================
// HTTP handlers（api/handler.php 分发）
// =====================================================================

function handleRecruitSaveProjectTerms(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo, true);
    $row = recruitLcRun(fn() => recruitSaveProjectTermsCore($pdo, (int)($in['id'] ?? 0), $in, $uid, $uname));
    jsonResponse(['success' => true, 'data' => $row]);
}

function handleRecruitSaveJobTerms(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo, true);
    $row = recruitLcRun(fn() => recruitSaveJobTermsCore($pdo, (int)($in['id'] ?? 0), $in, $uid, $uname));
    jsonResponse(['success' => true, 'data' => $row]);
}

function handleRecruitProjectOverview(PDO $pdo): void {
    [$uid] = recruitAuth($pdo);
    $id = (int)($_GET['id'] ?? 0);
    $scope = recruitScopeOwner($pdo, $uid);
    $st = $pdo->prepare("SELECT p.*, COALESCE(c.name,'') customer_group_name, COALESCE(u.name,'') manager_name
                         FROM recruit_projects p LEFT JOIN recruit_clients c ON c.id=p.customer_id LEFT JOIN users u ON u.id=p.manager_user_id WHERE p.id=?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) recruitErr('notFound', 'not found');
    $ready = recruitLcReady($pdo);
    $p['lc_ready'] = $ready;
    $p['billing_terms'] = json_decode((string)($p['billing_terms'] ?? ''), true) ?: ($ready ? recruitDefaultBillingTerms((string)$p['service_type']) : null);
    // 签约主体：取客户的法定名称（recruit_clients.legal_name），没填就空
    $p['entities'] = [];
    $client = (int)$p['customer_id'] > 0 ? recruitHostClient($pdo, (int)$p['customer_id']) : null;
    $p['client_entity_name'] = (string)($client['legal_name'] ?? '');
    $p['kpis'] = recruitProjectKpis($pdo, $id, $scope);
    // 钱（费用合计 / 商机收款）只给看全部的人（recruit_all / recruit_admin）；只看自己名下的招聘专员不下发
    $p['can_see_money'] = $scope === null;
    $p['fee_totals'] = $scope === null ? recruitProjectFeeTotals($pdo, $id) : [];
    $p['fee_by_type'] = $scope === null ? recruitProjectFeeByType($pdo, $id) : [];
    $p['service_types'] = array_values(array_filter(explode(',', (string)($p['service_types'] ?? ''))));
    // 各职位条款（职位定了用职位的，否则沿用项目默认；own 标明）
    $p['job_terms'] = [];
    if ($ready) {
        $js = $pdo->prepare("SELECT id, title, status, headcount FROM recruit_jobs WHERE project_id=? ORDER BY status='open' DESC, id");
        $js->execute([$id]);
        foreach ($js->fetchAll(PDO::FETCH_ASSOC) as $j) $p['job_terms'][] = $j + recruitJobTerms($pdo, (int)$j['id']);
    }
    $p['opportunity'] = null;   // 商机 / 收款不在 OpenHunter 里
    $p['placements'] = $ready ? recruitPlacementRows($pdo, 'pl.project_id=?' . ($scope !== null ? ' AND ' . recruitVisibleSql($scope) : ''), [$id]) : [];
    $p['documents'] = $ready ? recruitDocRows($pdo, "project_id=? AND entity_type IN ('project','placement')", [$id]) : [];
    if ($scope !== null) {   // 只看自己名下的：入职记录上的文件也只留自己的人
        $mine = array_flip(array_map(fn($r) => (int)$r['id'], $p['placements']));
        $p['documents'] = array_values(array_filter($p['documents'], fn($d) => $d['entity_type'] === 'project' || isset($mine[(int)$d['entity_id']])));
    }
    $p['can_admin'] = userHasModule($pdo, $uid, 'recruit_admin');
    jsonResponse(['success' => true, 'data' => $p]);
}

function handleRecruitProjectPipeline(PDO $pdo): void {
    [$uid] = recruitAuth($pdo);
    $rows = recruitLcRun(fn() => recruitProjectPipelineRows($pdo, (int)($_GET['id'] ?? 0), (string)($_GET['bucket'] ?? 'candidates'), recruitScopeOwner($pdo, $uid)));
    jsonResponse(['success' => true, 'data' => $rows]);
}

function handleRecruitListPlacements(PDO $pdo): void {
    [$uid] = recruitAuth($pdo);
    if (!recruitLcReady($pdo)) jsonResponse(['success' => true, 'data' => []]);
    $cid = (int)($_GET['candidate_id'] ?? 0);
    $pid = (int)($_GET['project_id'] ?? 0);
    if ($cid > 0) {
        recruitGuardCandidate($pdo, $uid, $cid);
        $rows = recruitPlacementRows($pdo, 'pl.candidate_id=?', [$cid]);
    } else {
        $scope = recruitScopeOwner($pdo, $uid);
        $rows = recruitPlacementRows($pdo, 'pl.project_id=?' . ($scope !== null ? ' AND ' . recruitVisibleSql($scope) : ''), [$pid]);
    }
    jsonResponse(['success' => true, 'data' => $rows]);
}

function recruitLcNotify(PDO $pdo, ?array $n, int $uid, string $uname): void {
    if ($n) recruitNotifySafe(fn() => recruitNotifyStageChange($pdo, $n['link'], $n['before'], $n['after'], $uid, $uname));
}

/** 新建（candidate_job_id）或改信息（id） */
function handleRecruitSavePlacement(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo);
    $admin = userHasModule($pdo, $uid, 'recruit_admin');
    $id = (int)($in['id'] ?? 0);
    if ($id > 0) {
        recruitLcRun(function () use ($pdo, $uid, $id, $in, $uname, $admin) {
            $pl = recruitPlacementLoad($pdo, $id);
            recruitGuardCandidate($pdo, $uid, (int)$pl['candidate_id']);
            recruitPlacementEdit($pdo, $id, $in, $uid, $uname, $admin);
        });
        jsonResponse(['success' => true, 'data' => ['id' => $id]]);
    }
    $link = (int)($in['candidate_job_id'] ?? 0);
    recruitGuardLink($pdo, $uid, $link);
    $r = recruitLcRun(function () use ($pdo, $link, $in, $uid, $uname, $admin) {
        $pdo->beginTransaction();
        try { $r = recruitPlacementCreate($pdo, $link, $in, $uid, $uname, $admin); $pdo->commit(); return $r; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    });
    if ($r['stage_before'] !== 'hired') recruitLcNotify($pdo, ['link' => $link, 'before' => $r['stage_before'], 'after' => 'hired'], $uid, $uname);
    jsonResponse(['success' => true, 'data' => ['id' => $r['id']]]);
}

function handleRecruitPlacementAction(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo);
    $id = (int)($in['id'] ?? 0);
    $r = recruitLcRun(function () use ($pdo, $uid, $uname, $id, $in) {
        $pl = recruitPlacementLoad($pdo, $id);
        recruitGuardCandidate($pdo, $uid, (int)$pl['candidate_id']);
        if (($in['action'] ?? '') === 'replace') recruitGuardLink($pdo, $uid, (int)($in['payload']['candidate_job_id'] ?? 0));
        return recruitPlacementTransition($pdo, $id, (string)($in['action'] ?? ''), is_array($in['payload'] ?? null) ? $in['payload'] : [],
            $uid, $uname, userHasModule($pdo, $uid, 'recruit_admin'));
    });
    recruitLcNotify($pdo, $r['notify'] ?? null, $uid, $uname);
    unset($r['notify']);
    jsonResponse(['success' => true, 'data' => $r]);
}

function handleRecruitUpdateFollowup(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo);
    $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare("SELECT candidate_id FROM recruit_followups WHERE id=?");
    $st->execute([$id]);
    recruitGuardCandidate($pdo, $uid, (int)$st->fetchColumn());
    $r = recruitLcRun(fn() => recruitUpdateFollowupCore($pdo, $id, $in, $uid, $uname));
    recruitLcNotify($pdo, $r['notify'] ?? null, $uid, $uname);
    jsonResponse(['success' => true]);
}

function handleRecruitUpdateCandidateStatus(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo);
    $cid = (int)($in['id'] ?? 0);
    recruitGuardCandidate($pdo, $uid, $cid);
    recruitLcRun(fn() => recruitUpdateCandidateStatusCore($pdo, $cid, $in, $uid, $uname));
    jsonResponse(['success' => true]);
}

function handleRecruitListDocuments(PDO $pdo): void {
    [$uid] = recruitAuth($pdo);
    if (!recruitLcReady($pdo)) jsonResponse(['success' => true, 'data' => []]);
    $type = (string)($_GET['entity_type'] ?? '');
    $eid = (int)($_GET['entity_id'] ?? 0);
    recruitLcRun(fn() => recruitDocGuard($pdo, $uid, $type, $eid, '', false));
    $rows = $type === 'candidate'   // 人的文件 = 挂在人身上的 + 他各次入职记录上的
        ? recruitDocRows($pdo, "(entity_type='candidate' AND entity_id=?) OR (entity_type='placement' AND entity_id IN (SELECT id FROM recruit_placements WHERE candidate_id=?))", [$eid, $eid])
        : recruitDocRows($pdo, 'entity_type=? AND entity_id=?', [$type, $eid]);
    jsonResponse(['success' => true, 'data' => $rows]);
}

function handleRecruitUploadDocument(PDO $pdo, array $in): void {
    [$uid, $uname] = recruitAuth($pdo);
    $g = fn(string $k) => $_POST[$k] ?? $in[$k] ?? '';
    $type = (string)$g('entity_type');
    $eid = (int)$g('entity_id');
    $docType = (string)$g('doc_type');
    recruitLcRun(fn() => recruitDocGuard($pdo, $uid, $type, $eid, $docType, true));
    $f = $_FILES['file'] ?? null;
    if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) recruitErr('uploadFailed', 'upload failed');
    $name = basename((string)$f['name']);
    if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), RECRUIT_LC_DOC_EXT, true)) recruitErr('uploadType', 'unsupported file type');
    if ((int)$f['size'] > RECRUIT_UPLOAD_MAX_BYTES) recruitErr('uploadTooBig', 'file too large');
    require_once __DIR__ . '/../oss.php';
    try { $up = uploadAttachment($pdo, (string)$f['tmp_name'], $name, 'recruit/docs', "{$type}_{$eid}"); }
    catch (Throwable $e) { recruitErr('ossFailed', $e->getMessage()); }
    $meta = ['title' => $g('title'), 'signed_at' => $g('signed_at'), 'valid_until' => $g('valid_until'), 'notes' => $g('notes')];
    $id = recruitLcRun(fn() => recruitDocSave($pdo, $type, $eid, $docType, $meta, $up['path'], $up['name'], (int)$f['size'], $uid, $uname));
    jsonResponse(['success' => true, 'data' => ['id' => $id]]);
}

function handleRecruitDeleteDocument(PDO $pdo, array $in): void {
    [$uid] = recruitAuth($pdo);
    $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare("SELECT entity_type, entity_id, doc_type, uploaded_by FROM recruit_documents WHERE id=? AND deleted_at IS NULL");
    $st->execute([$id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) recruitErr('notFound', 'not found');
    // 项目级文件：上传人自己或招聘管理员才能删（别人传的合同附件不能被随手删掉）
    if ($d['entity_type'] === 'project' && (int)$d['uploaded_by'] !== $uid && !userHasModule($pdo, $uid, 'recruit_admin')) recruitErr('forbidden', 'forbidden', 403);
    recruitLcRun(function () use ($pdo, $uid, $d, $id) {
        recruitDocGuard($pdo, $uid, $d['entity_type'], (int)$d['entity_id'], $d['doc_type'], true);
        recruitDocDelete($pdo, $id);
    });
    jsonResponse(['success' => true]);
}

/** 文件：鉴权后输出（照 handleRecruitGetResumeFile） */
function handleRecruitGetDocumentFile(PDO $pdo): void {
    [$uid] = recruitAuth($pdo);
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT entity_type, entity_id, doc_type, file_path, file_name, file_ext FROM recruit_documents WHERE id=? AND deleted_at IS NULL");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['file_path'] === '') recruitErr('notFound', 'not found');
    recruitLcRun(fn() => recruitDocGuard($pdo, $uid, $r['entity_type'], (int)$r['entity_id'], $r['doc_type'], false));
    require_once __DIR__ . '/../oss.php';
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header_remove('Content-Type');
    $dl = ($_GET['download'] ?? '') === '1';
    if (strpos((string)$r['file_path'], 'oss://') === 0) { ossStreamToClient($pdo, (string)$r['file_path'], (string)$r['file_name'], $dl); exit; }
    require_once __DIR__ . '/../openai_vision.php';
    $bin = readFileBinaryByPath($pdo, (string)$r['file_path']);
    if ($bin === null) { http_response_code(404); exit; }
    $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
             'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword'][$r['file_ext']] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bin));
    header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode((string)$r['file_name']));
    header('Cache-Control: private, max-age=600');
    echo $bin;
    exit;
}

/** meta 里下发的生命周期枚举（前端下拉 / 配色都按它，别在前端再抄一份取值） */
function recruitLcEnums(): array {
    return ['service_type' => RECRUIT_SERVICE_TYPES, 'monthly_services' => RECRUIT_MONTHLY_SERVICES, 'fee_type' => RECRUIT_FEE_TYPES,
            'remedy' => RECRUIT_REMEDIES, 'milestone' => RECRUIT_BILL_MILESTONES, 'contract_status' => RECRUIT_CONTRACT_STATUSES,
            'currency' => RECRUIT_CURRENCIES, 'pl_status' => RECRUIT_PL_STATUSES, 'pl_active' => RECRUIT_PL_ACTIVE,
            'pl_actions' => array_map(fn($t) => ['from' => $t[0], 'admin' => $t[2]], RECRUIT_PL_TRANSITIONS),
            'fu_kind' => RECRUIT_FU_KINDS, 'iv_type' => RECRUIT_INTERVIEW_TYPES, 'iv_result' => RECRUIT_INTERVIEW_RESULTS,
            'offer_status' => RECRUIT_OFFER_STATUSES, 'reason' => RECRUIT_REASON_CODES, 'doc_type' => RECRUIT_LC_DOC_BY_ENTITY,
            'doc_admin' => RECRUIT_LC_DOC_ADMIN, 'intent' => RECRUIT_INTENTS];
}
