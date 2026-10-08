<?php
/**
 * 招聘邮箱同步的纯函数测试（不连 IMAP、不连库）。
 *
 * 覆盖三件出错就直接影响「绩效算给谁」或「简历丢没丢」的事：
 *   一、plus-code 归属判定（recruitPlusCode）
 *   二、DOCX 抽文本（recruitDocxText）
 *   三、附件筛选：招聘要 PDF+DOCX，签证默认仍只要 PDF（extractPdfAttachments 加参数后的回归）
 *
 *   php tests/recruit_mailsync_test.php
 */

$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_mailsync.php';

$fails = 0;
function ok($cond, string $name) {
    global $fails;
    echo ($cond ? "  ✓ " : "  ✗ ") . $name . "\n";
    if (!$cond) $fails++;
}

const BOX = 'hr.box@gmail.com';

echo "一、plus-code 归属\n";
$r = recruitPlusCode("From: a@x.com\r\nDelivered-To: hr.box+cl@gmail.com\r\nTo: hr.box+cl@gmail.com\r\n", BOX);
ok($r === ['code' => 'cl', 'ambiguous' => false], 'Delivered-To 与 To 同一个 code → cl');

$r = recruitPlusCode("To: \"HR\" <hr.box+FR@Gmail.com>\r\n", BOX);
ok($r['code'] === 'fr', '大小写不敏感，code 归一成小写');

$r = recruitPlusCode("To: someone@x.com,\r\n hr.box+el@gmail.com\r\n", BOX);
ok($r['code'] === 'el', '折行（续行）里的地址也能认出');

$r = recruitPlusCode("To: hr.box+cl@gmail.com\r\nCc: hr.box+fr@gmail.com\r\n", BOX);
ok($r === ['code' => '', 'ambiguous' => true], '两个不同 code → 歧义，不猜');

$r = recruitPlusCode("To: hr.box@gmail.com\r\n", BOX);
ok($r === ['code' => '', 'ambiguous' => false], '直接发到 base 地址 → 无 code（待认领）');

$r = recruitPlusCode("To: other.person+cl@gmail.com\r\n", BOX);
ok($r['code'] === '', '别人邮箱的 plus 地址不算');

$r = recruitPlusCode("To: hr.box+cl@yahoo.com\r\n", BOX);
ok($r['code'] === '', '同 base 不同域名不算');

$r = recruitPlusCode("Subject: 投递 hr.box+fr@gmail.com\r\nTo: hr.box+cl@gmail.com\r\n", BOX);
ok($r['code'] === 'cl', '主题里出现的地址不参与判定');

echo "\n二、DOCX 抽文本\n";
$tmp = tempnam(sys_get_temp_dir(), 't_docx_');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
$zip->addFromString('word/document.xml',
    "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:document xmlns:w=\"$w\"><w:body>"
  . "<w:p><w:r><w:t>Budi </w:t></w:r><w:r><w:t>Santoso</w:t></w:r></w:p>"
  . "<w:p></w:p>"
  . "<w:p><w:r><w:t>CCNA &amp; MTCNA</w:t></w:r></w:p>"
  . "<w:p><w:r><w:t>雅加达</w:t></w:r></w:p>"
  . "</w:body></w:document>");
$zip->close();
$txt = recruitDocxText(file_get_contents($tmp));
@unlink($tmp);
ok($txt === "Budi Santoso\nCCNA & MTCNA\n雅加达", '同段多 run 拼接、空段跳过、实体解码、中文不乱码');
ok(recruitDocxText('not a zip') === '', '非 zip 返回空串不抛异常');

echo "\n三、附件筛选\n";
$b = 'BOUNDARY42';
$mime = "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n"
      . "--$b\r\nContent-Type: text/plain\r\n\r\nHello\r\n"
      . "--$b\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"cv.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('%PDF-1.4 x') . "\r\n"
      . "--$b\r\nContent-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document\r\nContent-Disposition: attachment; filename=\"cv.docx\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('PK docx') . "\r\n"
      . "--$b\r\nContent-Type: image/png\r\nContent-Disposition: attachment; filename=\"photo.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('png') . "\r\n"
      . "--$b\r\nContent-Type: application/zip\r\nContent-Disposition: attachment; filename=\"all.zip\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('zip') . "\r\n"
      . "--$b--\r\n";
$names = fn(array $a) => array_column($a, 'filename');
ok($names(extractPdfAttachments($mime)) === ['cv.pdf'], '签证默认调用：仍只取 PDF（回归）');
ok($names(extractPdfAttachments($mime, RECRUIT_RESUME_PATTERN)) === ['cv.pdf', 'cv.docx', 'photo.png'], '招聘：PDF + DOCX + 图片（手机拍的简历），zip 不取');
$att = extractPdfAttachments($mime, RECRUIT_RESUME_PATTERN);
ok(($att[0]['data'] ?? '') === '%PDF-1.4 x', 'base64 正文正确解码');

echo "\n四、邮件日期（决定归属先后）\n";
ok(recruitParseMailDate('Tue, 23 Sep 2026 10:03:00 +0700') === '2026-09-23 10:03:00', '星期与日期对不上时不跳到下个星期二');
ok(recruitParseMailDate('Wed, 23 Sep 2026 03:03:00 +0000') === '2026-09-23 10:03:00', 'UTC 时间转成雅加达时间');
ok(recruitParseMailDate('23 Sep 2026 10:03:00 +0700') === '2026-09-23 10:03:00', '无星期前缀照常解析');
ok(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', recruitParseMailDate('garbage')) === 1, '解析不了回落当前时间，不返回 1970');

echo "\n拉取失败清单（跨轮次重试，测试库，自清理）\n";
$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bid = -9; $uid = 777777;   // 不存在的邮箱 id，不碰真实数据
try {
    ok(recruitMailFailureRecord($pdo, $bid, $uid, 'timeout') === 'pending', '第 1 轮失败 → pending（下一轮先重试）');
    for ($n = 2; $n < RECRUIT_MAIL_MAX_ROUNDS; $n++) recruitMailFailureRecord($pdo, $bid, $uid, "err $n");
    $row = $pdo->query("SELECT attempts, status, last_error FROM recruit_mail_failures WHERE mailbox_id=$bid AND imap_uid=$uid")->fetch(PDO::FETCH_ASSOC);
    ok((int)$row['attempts'] === RECRUIT_MAIL_MAX_ROUNDS - 1 && $row['status'] === 'pending', '同一封只一行，累计轮数、记最后一次错误');
    ok(recruitMailFailureRecord($pdo, $bid, $uid, 'last') === 'gave_up', '累计 ' . RECRUIT_MAIL_MAX_ROUNDS . ' 轮 → gave_up');
} finally {
    $pdo->exec("DELETE FROM recruit_mail_failures WHERE mailbox_id=$bid");
}

echo "\n发件人黑名单（猎头广告邮件拉黑后不再解析）\n";
ok(recruitSenderEmail('Ads Team <Ads@Hunter.co.id>') === 'ads@hunter.co.id', '「名字 <邮箱>」取邮箱、转小写');
ok(recruitSenderEmail('hr@pt-abc.com') === 'hr@pt-abc.com' && recruitSenderEmail('"x" (no mail)') === '', '裸地址认得；没有地址 → 空');
ok(in_array('gmail.com', RECRUIT_PUBLIC_MAIL_DOMAINS, true), '公共邮箱域名在禁止整域拉黑名单里');
$bid = -9; $mids = [];
$raw = fn(string $from, string $mid) => "From: $from\r\nTo: recruit+cl@example.com\r\nSubject: Hiring\r\nMessage-ID: <$mid>\r\nDate: Wed, 24 Sep 2026 10:00:00 +0700\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n\r\n" . str_repeat("Experienced HR generalist with payroll and BPJS knowledge. ", 20);
try {
    $pdo->exec("DELETE FROM recruit_sender_blocks WHERE pattern IN ('@hunter-test.example','a_b@x-test.example')");
    $pdo->exec("INSERT INTO recruit_sender_blocks (pattern, hits) VALUES ('@hunter-test.example', 0), ('a_b@x-test.example', 0)");
    ok(recruitSenderBlocked($pdo, 'ads@hunter-test.example') === '@hunter-test.example', '整域拉黑命中该域任意邮箱');
    ok(recruitSenderBlocked($pdo, 'ads@hunter-test.example.org') === null && recruitSenderBlocked($pdo, 'ads@sub.hunter-test.example') === null, '别的域 / 子域不误伤');
    ok(recruitSenderBlocked($pdo, 'axb@x-test.example') === null, '邮箱里的 _ 不当通配符');

    $r1 = recruitIngestRawMessage($pdo, ['id' => $bid, 'username' => 'recruit@example.com'], 910001, $raw('Hunter Ads <ads@hunter-test.example>', 'blk-1@test'));
    $mids[] = $r1['message_id'];
    ok($r1['status'] === 'new' && $r1['resumes'] === 0 && $r1['blocked'] === '@hunter-test.example', '黑名单发件人：记邮件行（去重用），不建简历');
    ok((int)$pdo->query("SELECT hits FROM recruit_sender_blocks WHERE pattern='@hunter-test.example'")->fetchColumn() === 1, '黑名单命中次数 +1');
    $r2 = recruitIngestRawMessage($pdo, ['id' => $bid, 'username' => 'recruit@example.com'], 910002, $raw('Budi <budi@pt-normal-test.example>', 'blk-2@test'));
    $mids[] = $r2['message_id'];
    ok($r2['resumes'] === 1 && $r2['blocked'] === null, '正常发件人照常建简历');

    [$w, $a] = recruitSenderBlockSql('@hunter-test.example');
    $st = $pdo->prepare("SELECT m.id FROM recruit_messages m WHERE m.mailbox_id=$bid AND $w");
    $st->execute($a);
    ok(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) === [$r1['message_id']], '按黑名单查已收邮件：只命中该域那封');
    [$w, $a] = recruitSenderBlockSql('budi@pt-normal-test.example');
    $st = $pdo->prepare("SELECT m.id FROM recruit_messages m WHERE m.mailbox_id=$bid AND $w");
    $st->execute($a);
    ok(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) === [$r2['message_id']], '按完整邮箱查：大小写 / 尖括号都认');
} finally {
    $ids = implode(',', array_map('intval', array_filter($mids))) ?: '0';
    $pdo->exec("DELETE FROM recruit_resumes WHERE message_id IN ($ids)");
    $pdo->exec("DELETE FROM recruit_messages WHERE mailbox_id=$bid");
    $pdo->exec("DELETE FROM recruit_sender_blocks WHERE pattern IN ('@hunter-test.example','a_b@x-test.example')");
}

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
