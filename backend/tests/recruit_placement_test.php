<?php
/**
 * 招聘全生命周期（测试库集成，造 __ 前缀数据，finally 清理）：php tests/recruit_placement_test.php
 * 要守住的：
 *   条款：里程碑 % 合计 = 100 或全 0；模式与计费方式配对；币种与商机一致
 *   费用：月薪 × 基数月数 × 费率%（与前端 calcPlacementFee 同公式）；固定费；内部项目无费用
 *   入职记录：登记后职位 → hired、人 → placed；同一 人×职位 只能一条进行中；每条合法流转通过、非法流转拒；
 *            入职算保证期截止；保证期内离职按处理方式算应退；补人受次数限制、替补行费用 0；乐观锁
 *   跟进：面试写 scheduled_at 并推到面试中；Offer 接受推到 Offer；淘汰必填原因码并推到 rejected / withdrawn；
 *         推到 hired 自动建入职记录草稿；结构化跟进算「人工跟进」
 *   文件：客户协议镜像到商机合同 + 写签约时间 + 项目合同状态 signed；软删同步删镜像
 *   总览：每个 KPI 数字 = 下钻行数
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_parse.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }
function errKey(callable $fn): string { try { $fn(); return ''; } catch (RecruitLcError $e) { return $e->key; } }

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!recruitLcReady($pdo)) { echo "✗ 先跑 scripts/data-fixes/add_recruit_lifecycle_20260924.php --apply\n"; exit(1); }
$now = dbNow();
$uid = (int)$pdo->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
$made = ['proj' => [], 'job' => [], 'cand' => [], 'opp' => []];
$cust = recruitHostCreateClient($pdo, '__lc_client', '', 0);
$made['client'] = $cust;

try {
    $opp = 0;
    $pdo->prepare("INSERT INTO recruit_projects (name, kind, customer_id, opportunity_id, status, created_at, updated_at) VALUES ('__lc_proj', 'client', ?, ?, 'open', $now, $now)")
        ->execute([$cust, $opp]);
    $pid = $made['proj'][] = (int)$pdo->lastInsertId();
    recruitApplyDefaultTerms($pdo, $pid);
    $job = function (string $t) use ($pdo, $pid, $now, &$made) {
        $pdo->prepare("INSERT INTO recruit_jobs (project_id, title, jd_text, status, jd_rev, created_at, updated_at) VALUES (?, ?, 'x', 'open', 1, $now, $now)")->execute([$pid, $t]);
        return $made['job'][] = (int)$pdo->lastInsertId();
    };
    $cand = function (string $n) use ($pdo, $now, $uid, &$made) {
        $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, status, owner_user_id, owner_user_name, created_at, updated_at) VALUES (?, '{}', 1, 'new', ?, 'T', $now, $now)")
            ->execute([$n, $uid]);
        return $made['cand'][] = (int)$pdo->lastInsertId();
    };
    $link = function (int $cid, int $jid, string $stage) use ($pdo, $now) {
        $pdo->prepare("INSERT INTO recruit_candidate_jobs (candidate_id, job_id, origin, stage, ai_score, created_at, updated_at) VALUES (?, ?, 'manual', ?, 4, $now, $now)")->execute([$cid, $jid, $stage]);
        return (int)$pdo->lastInsertId();
    };
    $pl = fn(int $id) => recruitPlacementLoad($pdo, $id);
    $tx = function (callable $f) use ($pdo) { $pdo->beginTransaction(); try { $r = $f(); $pdo->commit(); return $r; } catch (Throwable $e) { $pdo->rollBack(); throw $e; } };

    echo "一、条款\n";
    $p0 = $pdo->query("SELECT * FROM recruit_projects WHERE id=$pid")->fetch(PDO::FETCH_ASSOC);
    ok($p0['service_type'] === 'contingency' && (int)$p0['guarantee_days'] === 90 && $p0['guarantee_remedy'] === 'replacement' && (int)$p0['replacement_count'] === 1,
       '新项目默认：猎头 · 90 天 · 免费补人 1 次');
    ok(errKey(fn() => recruitSaveProjectTermsCore($pdo, $pid, ['service_type' => 'retained', 'fee_type' => 'percent_of_salary', 'fee_percent' => 20,
        'billing_terms' => ['milestones' => [['code' => 'offer_accepted', 'percent' => 30], ['code' => 'start', 'percent' => 60]]]], $uid, 'T')) === 'milestoneSum', '里程碑合计 90 拒');
    ok(errKey(fn() => recruitSaveProjectTermsCore($pdo, $pid, ['service_type' => 'eor', 'fee_type' => 'percent_of_salary', 'fee_percent' => 20], $uid, 'T')) === 'badFeeType', 'EOR 配按月薪% 拒');
    ok(errKey(fn() => recruitSaveProjectTermsCore($pdo, $pid, ['service_type' => 'contingency'], $uid, 'T')) === 'feePercentRequired', '按月薪% 不填费率拒');
    $p1 = recruitSaveProjectTermsCore($pdo, $pid, ['service_type' => 'retained', 'fee_type' => 'percent_of_salary', 'fee_percent' => 20, 'fee_basis_months' => 12,
        'guarantee_days' => 60, 'guarantee_remedy' => 'replacement', 'replacement_count' => 1, 'currency' => 'IDR', 'contract_status' => 'signed',
        'billing_terms' => ['milestones' => [['code' => 'offer_accepted', 'percent' => 30], ['code' => 'start', 'percent' => 70]]]], $uid, 'T');
    ok($p1['service_type'] === 'retained' && (int)$p1['guarantee_days'] === 60 && $p1['contract_signed_at'] !== null, '合法条款保存；签约无日期自动填今天');
    ok(recruitPlacementFee($p1, 10000000.0) === 24000000.0, '费用 = 1000 万 × 12 × 20% = 2400 万');
    ok(recruitPlacementFee(['fee_type' => 'flat_per_hire', 'flat_fee' => 5000000], null) === 5000000.0 && recruitPlacementFee(['fee_type' => 'monthly'], 1.0) === null, '固定费 / 月费模式');

    echo "\n二、登记入职与流转\n";
    $j1 = $job('__NOC'); $c1 = $cand('__Budi'); $l1 = $link($c1, $j1, 'offered');
    ok(errKey(fn() => $tx(fn() => recruitPlacementCreate($pdo, $l1, ['offer_salary_monthly' => 10000000], $uid, 'T'))) === 'plStartRequired', '不填预计入职日拒');
    $r = $tx(fn() => recruitPlacementCreate($pdo, $l1, ['offer_salary_monthly' => 10000000, 'expected_start_date' => date('Y-m-d')], $uid, 'T'));
    $a = $pl($r['id']);
    ok($a['status'] === 'offer_accepted' && (float)$a['fee_amount'] === 24000000.0 && (int)$a['guarantee_days'] === 60, '建成 offer_accepted，费用与保证期按条款快照');
    ok($pdo->query("SELECT stage FROM recruit_candidate_jobs WHERE id=$l1")->fetchColumn() === 'hired'
       && $pdo->query("SELECT status FROM recruit_candidates WHERE id=$c1")->fetchColumn() === 'placed', '职位 → hired、人 → placed');
    ok(errKey(fn() => $tx(fn() => recruitPlacementCreate($pdo, $l1, ['offer_salary_monthly' => 1, 'expected_start_date' => date('Y-m-d')], $uid, 'T'))) === 'plExists', '同一 人×职位 不能两条进行中');
    ok(errKey(fn() => recruitPlacementTransition($pdo, $r['id'], 'pass_guarantee', [], $uid, 'T', false)) === 'plBadTransition', '未入职不能过保');
    ok(errKey(fn() => recruitPlacementTransition($pdo, $r['id'], 'refund_recorded', [], $uid, 'T', false)) === 'forbidden', '登记退款只限 admin');
    $start = date('Y-m-d', strtotime('-10 days'));
    recruitPlacementTransition($pdo, $r['id'], 'start', ['actual_start_date' => $start], $uid, 'T', false);
    $a = $pl($r['id']);
    ok($a['status'] === 'started' && $a['guarantee_end_date'] === date('Y-m-d', strtotime("$start +60 days")), '入职：保证期截止 = 入职日 + 60 天');
    ok(errKey(fn() => recruitPlacementTransition($pdo, $r['id'], 'pass_guarantee', [], $uid, 'T', false)) === 'plGuaranteeNotDue', '没到期普通人不能标过保');
    ok(errKey(fn() => recruitPlacementTransition($pdo, $r['id'], 'leave', ['left_at' => date('Y-m-d')], $uid, 'T', false)) === 'reasonRequired', '离职不填原因拒');
    recruitPlacementTransition($pdo, $r['id'], 'leave', ['left_at' => date('Y-m-d'), 'left_reason_code' => 'resigned'], $uid, 'T', false);
    $a = $pl($r['id']);
    ok($a['status'] === 'left_in_guarantee' && $a['remedy_outcome'] === 'pending' && $a['expected_refund_amount'] === null, '保证期内离职 → left_in_guarantee，补人模式不算应退');

    $c2 = $cand('__Sari'); $l2 = $link($c2, $j1, 'interviewing');
    $rr = recruitPlacementTransition($pdo, $r['id'], 'replace', ['candidate_job_id' => $l2], $uid, 'T', false);
    $b = $pl($rr['new_id']);
    ok($pl($r['id'])['status'] === 'replaced' && (float)$b['fee_amount'] === 0.0 && (int)$b['replacement_of_placement_id'] === $r['id'] && (int)$b['replacement_seq'] === 1,
       '补人：原行 replaced、替补行费用 0、链到原行');
    recruitPlacementTransition($pdo, $b['id'], 'start', ['actual_start_date' => $start], $uid, 'T', false);
    recruitPlacementTransition($pdo, $b['id'], 'leave', ['left_at' => date('Y-m-d'), 'left_reason_code' => 'performance'], $uid, 'T', false);
    $c3 = $cand('__Adi'); $l3 = $link($c3, $j1, 'offered');
    ok(errKey(fn() => recruitPlacementTransition($pdo, $b['id'], 'replace', ['candidate_job_id' => $l3], $uid, 'T', false)) === 'plReplaceQuota', '补人次数用完拒');
    recruitPlacementTransition($pdo, $b['id'], 'close', ['notes' => 'x'], $uid, 'T', true);
    ok($pl($b['id'])['status'] === 'closed', 'admin 关闭');

    // 按天折算退款
    recruitSaveProjectTermsCore($pdo, $pid, ['service_type' => 'contingency', 'fee_type' => 'flat_per_hire', 'flat_fee' => 9000000, 'guarantee_days' => 90,
        'guarantee_remedy' => 'prorata', 'currency' => 'IDR', 'billing_terms' => ['milestones' => [['code' => 'start', 'percent' => 100]]]], $uid, 'T');
    $r3 = $tx(fn() => recruitPlacementCreate($pdo, $l3, ['expected_start_date' => date('Y-m-d')], $uid, 'T'));
    $s3 = date('Y-m-d', strtotime('-30 days'));
    recruitPlacementTransition($pdo, $r3['id'], 'start', ['actual_start_date' => $s3], $uid, 'T', false);
    recruitPlacementTransition($pdo, $r3['id'], 'leave', ['left_at' => date('Y-m-d'), 'left_reason_code' => 'resigned'], $uid, 'T', false);
    ok((float)$pl($r3['id'])['expected_refund_amount'] === 6000000.0, '按天折算：固定 900 万，90 天保证期剩 60 天 → 应退 600 万');

    // 乐观锁：状态被别人先改
    $c4 = $cand('__Eko'); $l4 = $link($c4, $j1, 'offered');
    $r4 = $tx(fn() => recruitPlacementCreate($pdo, $l4, ['expected_start_date' => date('Y-m-d')], $uid, 'T'));
    $pdo->exec("UPDATE recruit_placements SET status='started' WHERE id={$r4['id']}");
    ok(errKey(fn() => recruitPlacementTransition($pdo, $r4['id'], 'no_show', ['reason_code' => 'other_offer'], $uid, 'T', false)) === 'plBadTransition', '已入职的不能标未到岗');
    $pdo->exec("UPDATE recruit_placements SET status='offer_accepted' WHERE id={$r4['id']}");
    recruitPlacementTransition($pdo, $r4['id'], 'no_show', ['reason_code' => 'other_offer'], $uid, 'T', false);
    ok($pdo->query("SELECT stage FROM recruit_candidate_jobs WHERE id=$l4")->fetchColumn() === 'withdrawn'
       && $pdo->query("SELECT status FROM recruit_candidates WHERE id=$c4")->fetchColumn() === 'in_process', '未到岗：职位 → withdrawn、人 placed → in_process');

    echo "\n二b、职位条款（混合项目）\n";
    $jE = $job('__Driver'); $jR = $job('__Recruiter');
    ok(recruitJobTerms($pdo, $jE)['own'] === false && recruitJobTerms($pdo, $jE)['service_type'] === 'contingency', '职位没定条款 → 沿用项目默认');
    ok(errKey(fn() => recruitSaveJobTermsCore($pdo, $jE, ['service_type' => 'eor', 'fee_type' => 'monthly'], $uid, 'T')) === 'monthlyFeeRequired', 'EOR 不填月费拒');
    $te = recruitSaveJobTermsCore($pdo, $jE, ['service_type' => 'eor', 'fee_type' => 'monthly', 'monthly_fee' => 1500000, 'guarantee_days' => 0, 'guarantee_remedy' => 'none'], $uid, 'T');
    ok($te['own'] && $te['service_type'] === 'eor' && (float)$te['monthly_fee'] === 1500000.0 && $te['currency'] === 'IDR', '职位定 EOR 每人每月 150 万，币种仍取项目');
    recruitSaveJobTermsCore($pdo, $jR, ['service_type' => 'rpo_per_hire', 'fee_type' => 'flat_per_hire', 'flat_fee' => 3000000, 'guarantee_days' => 30,
        'guarantee_remedy' => 'replacement', 'replacement_count' => 2, 'billing_terms' => ['milestones' => [['code' => 'start', 'percent' => 100]]]], $uid, 'T');
    $types = $pdo->query("SELECT service_types FROM recruit_projects WHERE id=$pid")->fetchColumn();
    ok(strpos($types, 'eor') !== false && strpos($types, 'rpo_per_hire') !== false && strpos($types, 'contingency') !== false, "项目合作模式标签自动带上：$types");
    $cE = $cand('__Joko'); $lE = $link($cE, $jE, 'offered');
    $pE = $pl($tx(fn() => recruitPlacementCreate($pdo, $lE, ['offer_salary_monthly' => 6000000, 'expected_start_date' => date('Y-m-d')], $uid, 'T'))['id']);
    ok($pE['service_type'] === 'eor' && $pE['fee_amount'] === null && (int)$pE['guarantee_days'] === 0, 'EOR 职位入职：模式快照 eor、按人按月不算一次性费用、无保证期');
    $cR = $cand('__Wati'); $lR = $link($cR, $jR, 'offered');
    $pR = $pl($tx(fn() => recruitPlacementCreate($pdo, $lR, ['expected_start_date' => date('Y-m-d')], $uid, 'T'))['id']);
    ok($pR['service_type'] === 'rpo_per_hire' && (float)$pR['fee_amount'] === 3000000.0 && (int)$pR['guarantee_days'] === 30 && (int)$pR['replacement_quota'] === 2,
       'RPO 职位入职：固定 300 万、保证期 30 天、补人额度 2 次（按职位条款快照）');
    recruitSaveJobTermsCore($pdo, $jR, ['inherit' => 1], $uid, 'T');
    ok(recruitJobTerms($pdo, $jR)['own'] === false && (int)$pl($pR['id'])['replacement_quota'] === 2, '职位改回沿用项目：已登记的人快照不变');
    $byType = array_column(recruitProjectFeeByType($pdo, $pid), 'service_type');
    ok(in_array('eor', $byType, true) && in_array('rpo_per_hire', $byType, true), '费用按模式分开统计');

    echo "\n三、结构化跟进\n";
    $c5 = $cand('__Rina'); $l5 = $link($c5, $j1, 'submitted');
    ok(errKey(fn() => recruitAddFollowupCore($pdo, ['candidate_id' => $c5, 'candidate_job_id' => $l5, 'kind' => 'interview', 'detail' => []], $uid, 'T')) === 'ivTimeRequired', '面试不填时间拒');
    $at = date('Y-m-d', strtotime('+1 day')) . ' 10:00';
    $f = recruitAddFollowupCore($pdo, ['candidate_id' => $c5, 'candidate_job_id' => $l5, 'kind' => 'interview', 'detail' => ['scheduled_at' => $at, 'round' => 1, 'type' => 'video']], $uid, 'T');
    $row = $pdo->query("SELECT * FROM recruit_followups WHERE id={$f['id']}")->fetch(PDO::FETCH_ASSOC);
    ok(substr((string)$row['scheduled_at'], 0, 16) === $at && $f['stage_after'] === 'interviewing' && substr((string)$row['next_follow_at'], 0, 16) === $at, '面试：写 scheduled_at、推到面试中、下次跟进 = 面试时间');
    recruitUpdateFollowupCore($pdo, $f['id'], ['result' => 'passed', 'feedback' => 'good'], $uid, 'T');
    ok((json_decode($pdo->query("SELECT detail_json FROM recruit_followups WHERE id={$f['id']}")->fetchColumn(), true)['result'] ?? '') === 'passed', '面试结果改为通过');
    ok(errKey(fn() => recruitAddFollowupCore($pdo, ['candidate_id' => $c5, 'candidate_job_id' => $l5, 'kind' => 'offer', 'detail' => ['status' => 'accepted']], $uid, 'T')) === 'offerSalaryRequired', 'Offer 不填薪资拒');
    $f2 = recruitAddFollowupCore($pdo, ['candidate_id' => $c5, 'candidate_job_id' => $l5, 'kind' => 'offer',
        'detail' => ['salary_monthly' => 12000000, 'currency' => 'IDR', 'start_date' => date('Y-m-d', strtotime('+14 days')), 'status' => 'accepted']], $uid, 'T');
    ok($f2['stage_after'] === 'offered', 'Offer 接受 → 推到 Offer');
    $f3 = recruitAddFollowupCore($pdo, ['candidate_id' => $c5, 'candidate_job_id' => $l5, 'stage_after' => 'hired', 'content' => 'ok'], $uid, 'T');
    $d = $pl((int)($f3['placement_id'] ?? 0));
    ok(!empty($f3['placement_draft']) && (float)$d['offer_salary_monthly'] === 12000000.0 && $d['expected_start_date'] === date('Y-m-d', strtotime('+14 days')),
       '推到 hired 自动建入职记录，薪资 / 入职日取自最近的 Offer');
    $c6 = $cand('__Tono'); $l6 = $link($c6, $j1, 'submitted');
    ok(errKey(fn() => recruitAddFollowupCore($pdo, ['candidate_id' => $c6, 'candidate_job_id' => $l6, 'stage_after' => 'rejected', 'content' => 'x'], $uid, 'T')) === 'reasonRequired', '改成淘汰不填原因拒');
    ok(errKey(fn() => recruitAddFollowupCore($pdo, ['candidate_id' => $c6, 'candidate_job_id' => $l6, 'kind' => 'reject', 'reason_code' => 'salary_low', 'detail' => ['side' => 'client']], $uid, 'T')) === 'reasonRequired', '原因与责任方不符拒');
    $f4 = recruitAddFollowupCore($pdo, ['candidate_id' => $c6, 'candidate_job_id' => $l6, 'kind' => 'reject', 'reason_code' => 'salary_low', 'detail' => ['side' => 'candidate']], $uid, 'T');
    ok($f4['stage_after'] === 'withdrawn' && $pdo->query("SELECT reason_code FROM recruit_followups WHERE id={$f4['id']}")->fetchColumn() === 'salary_low', '候选人侧淘汰 → withdrawn + 原因码');
    [$w, $args] = recruitCandidateFilterSql(['follow' => 'human']);
    $st = $pdo->prepare("SELECT COUNT(*) FROM recruit_candidates c WHERE $w AND c.id=?");
    $st->execute(array_merge($args, [$c6]));
    ok((int)$st->fetchColumn() === 1, '结构化跟进算人工跟进');

    echo "\n四、候选人现状\n";
    recruitUpdateCandidateStatusCore($pdo, $c6, ['current_salary' => 8000000, 'expected_salary_amt' => 10000000, 'salary_currency' => 'IDR', 'notice_period_days' => 30,
        'is_employed' => 1, 'intent' => 'warm', 'availability_date' => '2026-11-01'], $uid, 'T');
    $cr = $pdo->query("SELECT * FROM recruit_candidates WHERE id=$c6")->fetch(PDO::FETCH_ASSOC);
    ok((float)$cr['expected_salary_amt'] === 10000000.0 && (int)$cr['notice_period_days'] === 30 && $cr['intent'] === 'warm' && $cr['status_fields_at'] !== null, '现状字段写入');

    echo "\n五、合同与文件\n";
    $docId = recruitDocSave($pdo, 'project', $pid, 'client_agreement', ['signed_at' => date('Y-m-d')], 'data/uploads/recruit/docs/__x.pdf', '__协议.pdf', 100, $uid, 'T');
    $doc = $pdo->query("SELECT * FROM recruit_documents WHERE id=$docId")->fetch(PDO::FETCH_ASSOC);
    ok($doc['doc_type'] === 'client_agreement' && $pdo->query("SELECT contract_status FROM recruit_projects WHERE id=$pid")->fetchColumn() === 'signed', '客户协议 → 项目合同状态置 signed');
    ok(errKey(fn() => recruitDocSave($pdo, 'project', $pid, 'offer_letter', [], 'x', 'x.pdf', 1, $uid, 'T')) === 'badDocType', '项目上不能挂 Offer 函');
    ok(count(recruitDocRows($pdo, 'project_id=?', [$pid])) === 1 && !array_key_exists('file_path', recruitDocRows($pdo, 'project_id=?', [$pid])[0]), '列表不带 file_path');
    recruitDocDelete($pdo, $docId);
    ok(!recruitDocRows($pdo, 'project_id=?', [$pid]), '软删后列表为空');

    echo "\n六、总览 KPI = 下钻行数\n";
    $k = recruitProjectKpis($pdo, $pid, null);
    $bad = [];
    foreach ($k as $b => $n) if (count(recruitProjectPipelineRows($pdo, $pid, $b, null)) !== $n) $bad[] = $b;
    ok(!$bad, '全部 ' . count($k) . ' 个 KPI 与下钻一致' . ($bad ? '，不一致：' . implode(',', $bad) : ''));
    ok($k['in_guarantee'] === 0 && $k['pending_start'] === 3 && $k['lost'] === 4, "入职桶：待入职 {$k['pending_start']} / 保证期内 {$k['in_guarantee']} / 流失 {$k['lost']}");
} finally {
    foreach ($made['cand'] as $c) {
        $pdo->exec("DELETE FROM recruit_followups WHERE candidate_id=$c");
        $pdo->exec("DELETE FROM recruit_candidate_jobs WHERE candidate_id=$c");
        $pdo->exec("DELETE FROM recruit_placements WHERE candidate_id=$c");
        $pdo->exec("DELETE FROM recruit_candidates WHERE id=$c");
    }
    foreach ($made['proj'] as $p) {
        $pdo->exec("DELETE FROM recruit_documents WHERE project_id=$p");
        $pdo->exec("DELETE FROM recruit_jobs WHERE project_id=$p");
        $pdo->exec("DELETE FROM recruit_projects WHERE id=$p");
    }
    if (!empty($made['client'])) $pdo->exec("DELETE FROM recruit_clients WHERE id=" . (int)$made['client']);
}
echo $fails === 0 ? "\n全部通过\n" : "\n失败 $fails 项\n";
exit($fails === 0 ? 0 : 1);
