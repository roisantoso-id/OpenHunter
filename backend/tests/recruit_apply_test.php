<?php
/**
 * 职位投递链接（测试库集成，__ 前缀造数，finally 清理；假存储不碰 OSS）：php tests/recruit_apply_test.php
 * 要守住的：每人每职位一条链接、撤销后失效；职位 / 项目关了链接失效；对外只给职位信息；
 * 校验（姓名 / 手机 / 邮箱 / 授权 / 文件 / 隐藏字段）、IP 限流、同一份简历同一职位不重复收；
 * 投进来的简历 origin=apply、归属链接主人、带目标职位；解析时补联系方式；挂职位 origin=apply。
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_apply.php';
require_once $root . '/includes/recruit_parse.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }
$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!recruitApplyReady($pdo)) { echo "⛔ 先跑 scripts/data-fixes/add_recruit_apply_links_20260926.php --apply\n"; exit(1); }
$now = dbNow();
$made = ['proj' => 0, 'job' => 0, 'resumes' => [], 'cand' => []];
$store = fn(string $name, string $bin) => ['path' => 'test://' . $name, 'name' => $name, 'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
    'size' => strlen($bin), 'sha256' => hash('sha256', $bin), 'text' => '', 'status' => 'pending'];
$code = function (callable $f): string { try { $f(); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
$owner = 91501;

try {
    $pdo->exec("INSERT INTO recruit_projects (name, kind, status, created_at, updated_at) VALUES ('__apl_proj', 'client', 'open', $now, $now)");
    $made['proj'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recruit_jobs (project_id, title, location, salary_text, jd_text, status, posting_json, created_at, updated_at)
                   VALUES (?, '__Staff HR', 'Jakarta', '8-10jt', 'JD 原文', 'open', ?, $now, $now)")
        ->execute([$made['proj'], json_encode(['id' => 'Lowongan HR Staff', 'en' => 'HR Staff opening'])]);
    $made['job'] = $jid = (int)$pdo->lastInsertId();

    echo "一、链接\n";
    $l1 = recruitApplyLinkFor($pdo, $jid, $owner);
    ok($l1['id'] > 0, '新建链接返回正确的 id');
    $l2 = recruitApplyLinkFor($pdo, $jid, $owner);
    ok(strlen($l1['token']) === 8 && $l1['token'] === $l2['token'] && $l1['slug'] === 'staff-hr', '每人每职位一条链接：8 位码 + 好看的职位名（__Staff HR → staff-hr），重复取是同一条');
    ok(recruitApplyResolve($pdo, 'staff-hr-' . $l1['token']) && recruitApplyResolve($pdo, 'renamed-job-' . $l1['token']) && recruitApplyResolve($pdo, $l1['token']), '只认最后一段码：职位名部分随便变都能打开');
    ok(recruitApplySlug('环境文件顾问') === '' && recruitApplySlug('HR Service Manager (Labor Compliance)') === 'hr-service-manager-labor-compliance', '中文标题没有职位名部分；英文标题转成短横连接');
    ok(recruitApplyLinkFor($pdo, $jid, $owner + 1)['token'] !== $l1['token'], '不同招聘专员各有各的链接（投进来的人各归各）');
    $link = recruitApplyResolve($pdo, $l1['token']);
    ok($link && (int)$link['job_id'] === $jid && (int)$link['owner_user_id'] === $owner, 'token 能解析到职位与主人');
    ok(recruitApplyResolve($pdo, 'x-ZZZZZZZZ') === null && recruitApplyResolve($pdo, "' OR 1=1 --") === null, '乱写 / 注入的码不认');
    $pub = recruitApplyPublicJob($link, 'id');
    ok($pub['description'] === 'Lowongan HR Staff' && recruitApplyPublicJob($link, 'zh')['description'] === 'JD 原文', '职位描述：有这个语言的招聘文案用文案；没有（译文未到）用 JD 原文');
    ok(!array_intersect(array_keys($pub), ['owner_user_id', 'project_id', 'customer', 'link_id']), '对外不给归属人、项目、客户等内部字段');

    // 切语言：职位内容走 AI 译文（假模型）
    require_once $root . '/includes/recruit_translate.php';
    $src = recruitTransSource($pdo, 'job', $jid);
    $pdo->prepare("DELETE FROM recruit_translations WHERE owner_type='job' AND owner_id=?")->execute([$jid]);
    $pdo->prepare("INSERT INTO recruit_translations (owner_type, owner_id, lang, src_hash, status, created_by, created_at, updated_at) VALUES ('job', ?, 'zh', ?, 'generating', 0, $now, $now)")
        ->execute([$jid, sha1(json_encode($src, JSON_UNESCAPED_UNICODE))]);
    $tid = (int)$pdo->lastInsertId();
    $fake = fn(string $sys, array $c) => ['ok' => true, 'usage' => [], 'data' => ['t' => ['title' => '人事专员', 'jd_text' => '职位描述中文版', 'salary_text' => '800-1000万印尼盾']]];
    ok(recruitTranslateRun($pdo, $tid, $fake) === 'ready', '职位翻译 worker：标题 / JD / 薪资三段翻好');
    $tr = recruitApplyTranslation($pdo, $jid, 'zh', false);
    $pz = recruitApplyPublicJob($link, 'zh', $tr);
    ok($tr['status'] === 'ready' && $pz['title'] === '人事专员' && $pz['salary'] === '800-1000万印尼盾' && $pz['description'] === '职位描述中文版' && $pz['translated'], '中文页：标题 / 薪资 / 描述都换成译文，标「AI 翻译」');
    $pid2 = recruitApplyPublicJob($link, 'id', $tr);
    ok($pid2['description'] === 'Lowongan HR Staff' && !$pid2['translated'], '这个语言有人工招聘文案：描述用文案，不标 AI 翻译');
    $pdo->exec("UPDATE recruit_jobs SET jd_text='JD 改过了' WHERE id=$jid");
    ok(recruitApplyTranslation($pdo, $jid, 'zh', false)['status'] === 'none', 'JD 改过：旧译文不再用（等重翻）');
    $pdo->exec("UPDATE recruit_jobs SET jd_text='JD 原文' WHERE id=$jid");

    echo "\n二、校验与限流\n";
    $f = ['name' => 'cv.pdf', 'bin' => '%PDF-1.4 __apl test ' . microtime(true)];
    $ok = ['name' => '__Budi', 'phone' => '0812 3456 7890', 'email' => 'budi@example.com', 'consent' => '1', 'lang' => 'id'];
    ok($code(fn() => recruitApplySubmit($pdo, $link, ['name' => ''] + $ok, $f, '10.0.0.1', 'ua', $store)) === 'nameRequired', '没填姓名拒');
    ok($code(fn() => recruitApplySubmit($pdo, $link, ['phone' => '123'] + $ok, $f, '10.0.0.1', 'ua', $store)) === 'phoneInvalid', '手机号太短拒');
    ok($code(fn() => recruitApplySubmit($pdo, $link, ['email' => 'x@'] + $ok, $f, '10.0.0.1', 'ua', $store)) === 'emailInvalid', '邮箱格式不对拒');
    ok($code(fn() => recruitApplySubmit($pdo, $link, ['consent' => ''] + $ok, $f, '10.0.0.1', 'ua', $store)) === 'consentRequired', '没勾授权拒');
    ok($code(fn() => recruitApplySubmit($pdo, $link, $ok, ['name' => 'cv.exe', 'bin' => 'x'], '10.0.0.1', 'ua', $store)) === 'fileType', '非简历文件类型拒');
    ok($code(fn() => recruitApplySubmit($pdo, $link, ['website' => 'http://spam'] + $ok, $f, '10.0.0.1', 'ua', $store)) === 'spam', '隐藏字段被填 = 机器人，拒');
    ok(recruitApplyPhone('0812-3456-7890') === '6281234567890' && recruitApplyPhone('+62 812 3456 7890') === '6281234567890', '手机号归一成 62 开头');

    $r = recruitApplySubmit($pdo, $link, $ok, $f, '10.0.0.1', 'ua', $store);
    $made['resumes'][] = (int)$r['resume_id'];
    ok($r['status'] === 'added', '合法投递收下');
    $res = $pdo->query("SELECT origin, source_user_id, target_job_id, parse_status FROM recruit_resumes WHERE id=" . (int)$r['resume_id'])->fetch(PDO::FETCH_ASSOC);
    ok($res['origin'] === 'apply' && (int)$res['source_user_id'] === $owner && (int)$res['target_job_id'] === $jid && $res['parse_status'] === 'pending',
       '简历 origin=apply、归属链接主人、目标职位、待解析');
    $app = $pdo->query("SELECT phone, consent_at, ip FROM recruit_job_applications WHERE resume_id=" . (int)$r['resume_id'])->fetch(PDO::FETCH_ASSOC);
    ok($app && $app['phone'] === '6281234567890' && $app['consent_at'] !== null && $app['ip'] === '10.0.0.1', '投递记录：归一手机号、同意时间、IP');
    ok(recruitApplySubmit($pdo, $link, $ok, $f, '10.0.0.2', 'ua', $store)['status'] === 'duplicate', '同一份简历投同一职位不重复收');
    ok(recruitApplyLinkFor($pdo, $jid, $owner)['applies'] === 1, '链接投递数 = 1');

    for ($i = 0; $i < RECRUIT_APPLY_IP_HOURLY; $i++) {
        $pdo->prepare("INSERT INTO recruit_job_applications (link_id, job_id, resume_id, ip, created_at) VALUES (?, ?, 0, '10.9.9.9', $now)")->execute([(int)$link['link_id'], $jid]);
    }
    ok($code(fn() => recruitApplySubmit($pdo, $link, $ok, ['name' => 'b.pdf', 'bin' => 'other ' . microtime(true)], '10.9.9.9', 'ua', $store)) === 'tooMany', '同一 IP 一小时超过上限拒');

    echo "\n三、解析时补联系方式、挂职位\n";
    $parsed = ['person' => ['full_name' => ''], 'contacts' => ['phones' => [], 'emails' => []]];
    $m = recruitApplyMergeContacts($pdo, (int)$r['resume_id'], $parsed);
    ok($m['contacts']['phones'] === ['+6281234567890'] && $m['contacts']['emails'] === ['budi@example.com'] && $m['person']['full_name'] === '__Budi', '简历里没解析出的电话 / 邮箱 / 姓名用候选人填的补');
    $keep = recruitApplyMergeContacts($pdo, (int)$r['resume_id'], ['person' => ['full_name' => 'Real Name'], 'contacts' => ['phones' => ['+62 811'], 'emails' => ['a@b.co']]]);
    ok($keep['contacts']['phones'] === ['+62 811'] && $keep['person']['full_name'] === 'Real Name', '简历里有的以简历为准');
    $pdo->exec("INSERT INTO recruit_candidates (name, status, owner_user_id, created_at, updated_at) VALUES ('__apl_cand', 'new', $owner, $now, $now)");
    $made['cand'][] = $cid = (int)$pdo->lastInsertId();
    recruitLinkTargetJob($pdo, $cid, $jid, $owner, true);
    $cj = $pdo->query("SELECT id, origin, stage FROM recruit_candidate_jobs WHERE candidate_id=$cid AND job_id=$jid")->fetch(PDO::FETCH_ASSOC);
    $ev = $pdo->query("SELECT event_code FROM recruit_followups WHERE candidate_job_id=" . (int)$cj['id'])->fetchColumn();
    ok($cj['origin'] === 'apply' && $cj['stage'] === 'shortlisted' && $ev === 'applied_via_link', '挂到职位：origin=apply、初筛、跟进记「通过链接投递」');

    echo "\n四、链接主人也能看到（归属不变）\n";
    // __apl_cand 归别人（91599），但他的这份简历是通过 $owner 的链接投的 → $owner 能看到；另一个归别人、没走链接的看不到
    $pdo->exec("UPDATE recruit_candidates SET owner_user_id=91599 WHERE id=$cid");
    $pdo->exec("UPDATE recruit_resumes SET candidate_id=$cid WHERE id=" . (int)$r['resume_id']);
    $pdo->exec("INSERT INTO recruit_candidates (name, status, owner_user_id, created_at, updated_at) VALUES ('__apl_other', 'new', 91599, $now, $now)");
    $made['cand'][] = $other = (int)$pdo->lastInsertId();
    $vis = fn(int $id) => (bool)$pdo->query("SELECT 1 FROM recruit_candidates c WHERE c.id=$id AND " . recruitVisibleSql($owner))->fetchColumn();
    ok($vis($cid), '链接主人能看到「通过自己链接投进来、但归别人」的候选人');
    ok(!$vis($other), '别人名下、没走自己链接的候选人仍看不到');
    [$w, $a2] = recruitCandidateFilterSql([], $owner);
    $st = $pdo->prepare("SELECT c.id FROM recruit_candidates c WHERE $w AND c.id IN ($cid, $other)");
    $st->execute($a2);
    ok(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) === [$cid], '人才库列表：同一口径（只多出链接带来的这一个）');
    recruitGuardCandidate($pdo, $owner, $cid);   // 看不到会 exit；走到下一行就是通过
    ok(true, '候选人详情权限：链接主人能打开');
    ok((int)$pdo->query("SELECT owner_user_id FROM recruit_candidates WHERE id=$cid")->fetchColumn() === 91599, '归属不变：仍是最早收到的人');

    echo "\n五、失效\n";
    $pdo->exec("UPDATE recruit_jobs SET status='closed' WHERE id=$jid");
    ok(recruitApplyResolve($pdo, $l1['token']) === null, '职位关了：链接失效');
    $pdo->exec("UPDATE recruit_jobs SET status='open' WHERE id=$jid");
    $pdo->exec("UPDATE recruit_projects SET status='closed' WHERE id=" . $made['proj']);
    ok(recruitApplyResolve($pdo, $l1['token']) === null, '项目关了：链接失效');
    $pdo->exec("UPDATE recruit_projects SET status='open' WHERE id=" . $made['proj']);
    ok(recruitApplyRevoke($pdo, $jid, $owner) === 1 && recruitApplyResolve($pdo, $l1['token']) === null, '撤销后旧链接失效');
    $l3 = recruitApplyLinkFor($pdo, $jid, $owner);
    ok($l3['token'] !== $l1['token'] && $l3['id'] > 0, '撤销后再取生成新链接（id 正确，不是 0）');
} finally {
    $jid = (int)$made['job'];
    if ($jid) {
        $pdo->exec("DELETE FROM recruit_followups WHERE candidate_job_id IN (SELECT id FROM recruit_candidate_jobs WHERE job_id=$jid)");
        $pdo->exec("DELETE FROM recruit_candidate_jobs WHERE job_id=$jid");
        $pdo->exec("DELETE FROM recruit_job_applications WHERE job_id=$jid");
        $pdo->exec("DELETE FROM recruit_job_links WHERE job_id=$jid");
        $pdo->exec("DELETE FROM recruit_resumes WHERE target_job_id=$jid AND origin='apply'");
        $pdo->exec("DELETE FROM recruit_translations WHERE owner_type='job' AND owner_id=$jid");
        $pdo->exec("DELETE FROM recruit_jobs WHERE id=$jid");
    }
    if ($made['proj']) $pdo->exec("DELETE FROM recruit_projects WHERE id=" . (int)$made['proj']);
    foreach ($made['cand'] as $c) $pdo->exec("DELETE FROM recruit_candidates WHERE id=" . (int)$c);
}
echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
