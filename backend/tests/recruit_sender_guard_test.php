<?php
/**
 * 发件人自动拉黑（recruit_sender_guard.php）：php tests/recruit_sender_guard_test.php
 * 测试库集成，整段包在事务里、跑完回滚；模型是假的。
 * 要守住的：只看解析失败、且同封邮件没有成功 / 在路上的附件；投过真简历的、我们自己人、被人放出来过的不自动拉黑；
 * AI 判广告 / 垃圾才拉黑，只拉黑单个邮箱，带三语原因与触发邮件；AI 判求职者不拉黑；
 * AI 不可用时保守规则：非公共邮箱失败 ≥2 封才拉黑，Gmail 不行；查过的邮件不再查。
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_sender_guard.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->beginTransaction();
try {
    // 只让本测试造的邮件进队列：把库里现有待查的先标掉（事务回滚后恢复）
    $pdo->exec("UPDATE recruit_messages SET sender_check='x-test' WHERE sender_check=''");
    $uid = 920000;
    $msg = function (string $from, string $subject, array $statuses, string $body = '') use ($pdo, &$uid) {
        $uid++;
        $pdo->prepare("INSERT INTO recruit_messages (mailbox_id, imap_uid, message_id, subject, from_addr, body_text, received_at, created_at)
                       VALUES (-9, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$uid, "t$uid@test", $subject, $from, $body]);
        $mid = (int)$pdo->lastInsertId();
        foreach ($statuses as $i => $st) {
            $pdo->prepare("INSERT INTO recruit_resumes (origin, message_id, file_name, parse_status, parse_error_kind, doc_type, dedupe_key, received_at, created_at)
                           VALUES ('email', ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
                ->execute([$mid, "f$i.jpg", $st, $st === 'failed' ? 'no_text' : '', $st === 'parsed' ? 'cv' : '', "test:$mid:$i"]);
        }
        return $mid;
    };
    $ad    = $msg('Medi <medi@jobseeker-test.example>', 'HR kamu sibuk screening ratusan CV?', ['failed', 'failed'], 'Coba tools AI kami');
    $appl  = $msg('Budi <budi.test@gmail.com>', 'Application - HR Service Manager', ['failed']);
    $mixed = $msg('Ani <ani.test@gmail.com>', 'Application - Visa Staff', ['failed', 'parsed']);
    $queue = $msg('Eko <eko.test@gmail.com>', 'Application', ['retry']);
    $cvGuy = $msg('Sari <sari@pt-test.example>', 'Lamaran', ['parsed']);
    $cvBad = $msg('Sari <sari@pt-test.example>', 'Lamaran (foto)', ['failed']);

    echo "一、队列\n";
    $q = array_map(fn($r) => (int)$r['id'], recruitSenderCheckQueue($pdo, 50));
    ok(in_array($ad, $q) && in_array($appl, $q) && in_array($cvBad, $q), '解析失败的邮件进队列');
    ok(!in_array($mixed, $q) && !in_array($queue, $q), '同封有成功附件的 / 还在重试的不进（多半是真候选人）');

    echo "\n二、AI 判定\n";
    $calls = 0;
    $fake = function (array $req) use (&$calls) {
        $out = [];
        foreach ($req as $k => $r) {
            $calls++;
            $ad = str_contains($r['content'][0]['text'], 'jobseeker-test');
            $out[$k] = ['ok' => true, 'data' => ['kind' => $ad ? 'advertisement' : 'application',
                'reason' => ['zh' => $ad ? '推销 AI 筛选 CV 工具' : '应聘', 'en' => 'x', 'id' => 'x']]];
        }
        return $out;
    };
    $r = recruitAutoBlockSenders($pdo, $fake, 50);
    ok($r['blocked'] === 1 && $r['list'][0]['email'] === 'medi@jobseeker-test.example', 'AI 判广告 → 拉黑该邮箱（只拉黑单个邮箱，不拉整个域名）');
    $b = $pdo->query("SELECT source, reason_key, reason_json, sample_subject, sample_message_id FROM recruit_sender_blocks WHERE pattern='medi@jobseeker-test.example'")->fetch(PDO::FETCH_ASSOC);
    ok($b['source'] === 'auto' && $b['reason_key'] === 'ad' && json_decode($b['reason_json'], true)['zh'] === '推销 AI 筛选 CV 工具', '记来源 auto、原因（三语）');
    ok($b['sample_subject'] === 'HR kamu sibuk screening ratusan CV?' && (int)$b['sample_message_id'] === $ad, '记触发的那封邮件');
    ok((int)$pdo->query("SELECT COUNT(*) FROM recruit_resumes WHERE message_id=$ad AND parse_status='blocked'")->fetchColumn() === 2, '那封邮件的失败简历标为已拉黑');
    ok(recruitSenderBlocked($pdo, 'budi.test@gmail.com') === null, 'AI 判求职者 → 不拉黑');
    ok($pdo->query("SELECT sender_check FROM recruit_messages WHERE id=$appl")->fetchColumn() === 'application', '判定结果记在邮件上');
    ok($pdo->query("SELECT sender_check FROM recruit_messages WHERE id=$cvBad")->fetchColumn() === 'has_cv' && recruitSenderBlocked($pdo, 'sari@pt-test.example') === null,
       '投过解析成功简历的发件人：不问 AI、不拉黑');
    $before = $calls;
    recruitAutoBlockSenders($pdo, $fake, 50);
    ok($calls === $before, '查过的邮件不再查（不重复花钱）');

    echo "\n三、AI 不可用 → 保守规则\n";
    $pdo->exec("UPDATE recruit_messages SET sender_check='x-test' WHERE sender_check=''");
    $h1 = $msg('Ads <ads@hunter-test.example>', 'Partnership', ['failed']);
    $down = fn(array $req) => array_map(fn() => ['ok' => false, 'error_kind' => 'not_configured'], $req);
    $r = recruitAutoBlockSenders($pdo, $down, 50);
    ok($r['blocked'] === 0 && $pdo->query("SELECT sender_check FROM recruit_messages WHERE id=$h1")->fetchColumn() === '', '只失败 1 封：不拉黑，也不记已查（AI 恢复后再判）');
    $h2 = $msg('Ads <ads@hunter-test.example>', 'Partnership 2', ['unsupported']);
    $g1 = $msg('Tono <tono.test@gmail.com>', 'CV', ['failed']);
    $g2 = $msg('Tono <tono.test@gmail.com>', 'CV lagi', ['failed']);
    $r = recruitAutoBlockSenders($pdo, $down, 50);
    ok(recruitSenderBlocked($pdo, 'ads@hunter-test.example') !== null
       && $pdo->query("SELECT reason_key FROM recruit_sender_blocks WHERE pattern='ads@hunter-test.example'")->fetchColumn() === 'repeat_fail',
       '非公共邮箱失败 ≥2 封、从没成功 → 拉黑（repeat_fail）');
    ok(recruitSenderBlocked($pdo, 'tono.test@gmail.com') === null, 'Gmail 失败再多也不按规则拉黑（交给 AI 或人）');

    echo "\n四、人放出来过的不再自动拉黑\n";
    $pdo->exec("UPDATE recruit_messages SET sender_check='whitelisted' WHERE id=$appl");
    $pdo->exec("UPDATE recruit_messages SET sender_check='x-test' WHERE sender_check=''");
    $again = $msg('Budi <budi.test@gmail.com>', 'HR kamu sibuk screening jobseeker-test', ['failed']);
    recruitAutoBlockSenders($pdo, $fake, 50);
    ok(recruitSenderBlocked($pdo, 'budi.test@gmail.com') === null && $pdo->query("SELECT sender_check FROM recruit_messages WHERE id=$again")->fetchColumn() === 'whitelisted',
       '即使 AI 会判广告，被人解除过的发件人也不自动拉黑');
    ok(recruitSenderIsInternal($pdo, strtolower((string)$pdo->query("SELECT email FROM users WHERE email LIKE '%@%' LIMIT 1")->fetchColumn())), '同事邮箱算自己人');
} finally {
    $pdo->rollBack();
}

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
