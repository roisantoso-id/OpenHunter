<?php
/**
 * 招聘解析 worker 集成测试：**测试库**上跑真实的认领 / 合并 / 归属 / 重试 / 附属文件逻辑，
 * 只有 LLM 输出是剧本化的（假 LLM）。跑完删除本次造的全部数据。
 *
 *   php tests/recruit_worker_test.php
 *
 * ⛔ 需要 .env 指向测试库且已跑过 create_recruit_tables_20260923.php。
 *    造的数据都带本次运行的随机标记，手机号用 +62899 号段（不会撞真实候选人），只按 only_ids 处理自己的简历。
 */

$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/openai_vision.php';
require_once $root . '/includes/recruit_parse.php';

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$fails = 0;
function ok($cond, string $name) {
    global $fails;
    echo ($cond ? "  ✓ " : "  ✗ ") . $name . "\n";
    if (!$cond) $fails++;
}

$run = bin2hex(random_bytes(3));
$seq = 0;
$made = ['msg' => [], 'res' => []];
$phone = fn(int $n) => sprintf('0899%04d%04d', hexdec($run) % 10000, $n);   // 每次运行不同、不撞真实号码

function mkMsg(PDO $pdo, string $run): int {
    global $made;
    static $uid = 0;
    $pdo->prepare("INSERT INTO recruit_messages (mailbox_id, imap_uid, message_id, subject, created_at)
                   VALUES (0, ?, ?, 'worker-test', " . dbNow() . ")")
        ->execute([900000000 + (crc32($run) % 90000000) + (++$uid), "test-$run-$uid"]);
    return $made['msg'][] = (int)$pdo->lastInsertId();
}
/** 造一份待解析简历；$src = [source_id, user_id, user_name] */
function mkRes(PDO $pdo, string $run, int $mid, string $recv, array $src, string $sha = ''): int {
    global $made, $seq;
    $seq++;
    $pdo->prepare("INSERT INTO recruit_resumes (origin, message_id, dedupe_key, source_id, source_user_id, source_user_name,
                     received_at, file_name, file_ext, file_sha256, raw_text, text_chars, parse_status, created_at, updated_at)
                   VALUES ('import', ?, ?, ?, ?, ?, ?, 'x.pdf', 'pdf', ?, ?, 500, 'pending', " . dbNow() . ", " . dbNow() . ")")
        ->execute([$mid, "test:$run:$seq", $src[0], $src[1], $src[2], $recv, $sha !== '' ? $sha : hash('sha256', "$run-$seq"),
                   str_repeat('resume text ', 50)]);
    return $made['res'][] = (int)$pdo->lastInsertId();
}
function cv(string $name, string $phone, string $doc = 'cv', string $email = ''): array {
    return ['doc_type' => $doc, 'person' => ['full_name' => $name],
            'contacts' => ['phones' => $phone !== '' ? [$phone] : [], 'emails' => $email !== '' ? [$email] : []],
            'experience' => [['title' => 'NOC', 'company' => 'PT X', 'start' => '2021-01', 'end' => 'present']]];
}
/** 假 LLM：按简历 id 返回剧本里的结果，并记录被调用了哪些 id */
$script = []; $called = [];
$llm = function (array $req) use (&$script, &$called) {
    $out = [];
    foreach (array_keys($req) as $id) {
        $called[] = $id;
        $s = $script[$id] ?? ['ok' => false, 'error_kind' => 'network', 'error' => 'no script'];
        $out[$id] = isset($s['ok']) ? $s : ['ok' => true, 'data' => $s, 'usage' => ['prompt_tokens' => 1], 'elapsed' => 0.1];
    }
    return $out;
};
$parse = fn(array $ids) => recruitParseBatch($pdo, $llm, ['only_ids' => $ids, 'limit' => 50]);
$cand  = function (int $resumeId) use ($pdo) {
    return $pdo->query("SELECT c.* FROM recruit_candidates c JOIN recruit_resumes r ON r.candidate_id=c.id WHERE r.id=$resumeId")->fetch(PDO::FETCH_ASSOC) ?: null;
};
$res = fn(int $id) => $pdo->query("SELECT * FROM recruit_resumes WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
$CL = [1, 19, 'alice']; $FR = [2, 33, 'bob']; $NONE = [0, 0, ''];

try {
    echo "一、同号同名 → 同一个人\n";
    $a = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-01 10:00:00', $CL);
    $b = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-02 10:00:00', $CL);
    $script[$a] = cv('Budi Santoso', $phone(1));
    $script[$b] = cv('Budi Santoso, S.Kom', '+62 ' . substr($phone(1), 1));   // 同号不同写法
    $parse([$a, $b]);
    $ca = $cand($a); $cb = $cand($b);
    ok($ca && $cb && $ca['id'] === $cb['id'], '两份简历并入同一人');
    ok((int)$cb['resume_count'] === 2, '简历份数 = 2');
    ok($ca['phone_key'] === '+62' . substr($phone(1), 1), 'phone_key 为 E.164');

    echo "\n二、同号异名 → 不合并，双方标冲突\n";
    $c = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-03 10:00:00', $CL);
    $script[$c] = cv('Siti Rahma', $phone(1));
    $parse([$c]);
    $cc = $cand($c); $orig = $cand($a);
    ok($cc && (int)$cc['id'] !== (int)$orig['id'], '新建了另一个人');
    ok($cc['phone_key'] === null && $cc['phone_display'] === $phone(1), '新人不占 phone_key，号码放 phone_display');
    ok(strpos($cc['review_flags'], ',phone_conflict,') !== false && strpos($orig['review_flags'], ',phone_conflict,') !== false, '双方都标 phone_conflict');

    echo "\n三、归属先到先得：按收件时间，不按解析先后\n";
    $late  = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-10 10:00:00', $CL);   // cl 后收到
    $early = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-05 10:00:00', $FR);   // fr 先收到
    $script[$late] = cv('Andi Wijaya', $phone(2));
    $script[$early] = cv('Andi Wijaya', $phone(2));
    $parse([$late]);                                   // cl 那份先解析完
    ok($cand($late)['owner_user_name'] === 'alice', '只有 cl 那份时归 alice');
    $parse([$early]);                                  // 更早到达的 fr 那份后解析完
    $x = $cand($late);
    ok($x['owner_user_name'] === 'bob' && (int)$x['owner_resume_id'] === $early, '更早收到的 fr 那份解析完后 → 改归 bob');
    $ev = $pdo->query("SELECT event_code, content FROM recruit_followups WHERE candidate_id={$x['id']} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    ok(array_column($ev, 'event_code') === ['owner_assigned', 'owner_changed'], '留了 owner_assigned → owner_changed 两条系统记录');

    echo "\n四、手动指定过的归属不再自动改\n";
    $pdo->prepare("UPDATE recruit_candidates SET owner_user_id=76, owner_user_name='ellen', owner_set_by='manual' WHERE id=?")->execute([$x['id']]);
    $earliest = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-01 08:00:00', $CL);
    $script[$earliest] = cv('Andi Wijaya', $phone(2));
    $parse([$earliest]);
    ok($cand($earliest)['owner_user_name'] === 'ellen', '更早的 cl 简历进来，手动归属 ellen 不变');

    echo "\n五、同一份文件（sha256）只花一次钱\n";
    $sha = hash('sha256', "same-file-$run");
    $d1 = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-11 10:00:00', $CL, $sha);
    $script[$d1] = cv('Rudi Hartono', $phone(3));
    $parse([$d1]);
    $d2 = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-12 10:00:00', $FR, $sha);   // 同一份 PDF 投给了 fr
    $called = [];
    $r = $parse([$d2]);
    ok($called === [] && $r['dedupe'] === 1, '第二份没调 LLM，复用解析结果');
    ok($res($d2)['parse_mode'] === 'dedupe' && $cand($d2)['id'] === $cand($d1)['id'], '标 dedupe 且并到同一人');
    ok($cand($d2)['owner_user_name'] === 'alice', '归属仍是先收到的 alice');

    echo "\n六、失败重试与退避\n";
    $t = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-13 10:00:00', $NONE);
    $script[$t] = ['ok' => false, 'error_kind' => 'timeout', 'error' => 'AI 服务响应超时'];
    $parse([$t]);
    $rt = $res($t);
    ok($rt['parse_status'] === 'retry' && (int)$rt['attempts'] === 1 && $rt['next_retry_at'] !== null, '第 1 次超时 → retry，排了退避时间');
    ok($parse([$t])['claimed'] === 0, '退避时间没到不会被认领');
    for ($i = 2; $i <= RECRUIT_PARSE_MAX_ATTEMPTS; $i++) {
        $pdo->exec("UPDATE recruit_resumes SET next_retry_at=NULL WHERE id=$t");   // 测试里跳过等待
        $parse([$t]);
    }
    $rt = $res($t);
    ok($rt['parse_status'] === 'failed' && (int)$rt['attempts'] === RECRUIT_PARSE_MAX_ATTEMPTS, '第 5 次失败 → failed，不再排队');
    ok($rt['parse_error_kind'] === 'timeout', '记下错误类型');

    $bo = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-13 11:00:00', $NONE);
    $script[$bo] = ['foo' => 'bar'];                                             // 不合格输出
    $parse([$bo]);
    ok($res($bo)['parse_status'] === 'retry' && $res($bo)['parse_error_kind'] === 'bad_output', '输出不合格 → bad_output 重试');

    $rj = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-13 12:00:00', $NONE);
    $script[$rj] = ['ok' => false, 'error_kind' => 'rejected', 'error' => 'LLM HTTP 429'];
    $r = $parse([$rj]);
    $rr = $res($rj);
    ok($r['aborted'] && $r['abort_reason'] === 'rejected', '429 → 本轮中止');
    ok((int)$rr['attempts'] === 0 && $rr['next_retry_at'] !== null, '不扣次数，1 小时后再试');

    echo "\n七、附属文件挂到同一封邮件的那个人（先后顺序都行）\n";
    $m = mkMsg($pdo, $run);
    $cert = mkRes($pdo, $run, $m, '2026-09-14 10:00:00', $CL);
    $cvr  = mkRes($pdo, $run, $m, '2026-09-14 10:00:00', $CL);
    $script[$cert] = cv('', '', 'certificate');
    $script[$cvr]  = cv('Sari Wulandari', $phone(4));
    $parse([$cert]);                                   // 证书先解析完：这时还不知道是谁的
    ok((int)$res($cert)['candidate_id'] === 0, '证书先到：暂不挂');
    $parse([$cvr]);
    ok((int)$res($cert)['candidate_id'] === (int)$res($cvr)['candidate_id'] && $res($cert)['attach_mode'] === 'sibling', '简历解析完后证书挂上（sibling）');
    ok((int)$cand($cvr)['resume_count'] === 2, '这个人名下 2 份文件');

    echo "\n八、没有号码的人不会互相撞唯一键\n";
    $n1 = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-15 10:00:00', $NONE);
    $n2 = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-15 11:00:00', $NONE);
    $script[$n1] = cv('Tanpa Nomor Satu', '');
    $script[$n2] = cv('Tanpa Nomor Dua', '');
    $parse([$n1, $n2]);
    $k1 = $cand($n1); $k2 = $cand($n2);
    ok($k1 && $k2 && $k1['id'] !== $k2['id'] && $k1['phone_key'] === null && $k2['phone_key'] === null, '两个无号码的人各自建档，phone_key 都是 NULL');
    ok(strpos($k1['review_flags'], ',no_contact,') !== false, '标 no_contact 提示人工补联系方式');

    echo "\n九、只有邮箱的人后来留了号码 → 补号码而不是新建\n";
    $e1 = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-16 10:00:00', $CL);
    $e2 = mkRes($pdo, $run, mkMsg($pdo, $run), '2026-09-17 10:00:00', $CL);
    $script[$e1] = cv('Eko Prasetyo', '', 'cv', "eko.$run@example.com");
    $script[$e2] = cv('Eko Prasetyo', $phone(5), 'cv', "eko.$run@example.com");
    $parse([$e1]); $parse([$e2]);
    ok($cand($e1)['id'] === $cand($e2)['id'] && $cand($e2)['phone_key'] !== null, '按邮箱认出同一人并补上 phone_key');
} finally {
    // 清理：本次造的邮件、简历、由它们建出来的人、跟进记录、记账
    $rid = implode(',', $made['res'] ?: [0]);
    $mid = implode(',', $made['msg'] ?: [0]);
    $cids = $pdo->query("SELECT DISTINCT candidate_id FROM recruit_resumes WHERE id IN ($rid) AND candidate_id>0")->fetchAll(PDO::FETCH_COLUMN);
    // 同号冲突时被标记的原有人也在 cids 里（都是本测试建的）
    $cid = implode(',', array_map('intval', $cids ?: [0]));
    $pdo->exec("DELETE FROM recruit_followups WHERE candidate_id IN ($cid)");
    $pdo->exec("DELETE FROM recruit_candidates WHERE id IN ($cid)");
    $pdo->exec("DELETE FROM recruit_resumes WHERE id IN ($rid)");
    $pdo->exec("DELETE FROM recruit_messages WHERE id IN ($mid)");
    $pdo->exec("DELETE FROM ai_api_usage WHERE biz_type='recruit_resume' AND biz_id IN ($rid)");
    $left = (int)$pdo->query("SELECT COUNT(*) FROM recruit_resumes WHERE dedupe_key LIKE 'test:$run:%'")->fetchColumn();
    echo "\n清理完成（残留 $left 条）\n";
}

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
