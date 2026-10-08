<?php
/**
 * 本地导入简历到 OpenHunter（不连 IMAP）
 *
 * 两种模式，都走线上同一套代码（存 OSS → 写 recruit_resumes），测到的就是真跑的：
 *
 *   # 1) 简历文件夹：把目录里的 pdf/docx/doc/jpg/png 当作某招聘专员收到的简历
 *   php scripts/ops/recruit_import.php <目录> --source=cl [--received-at=mtime|now] [--apply]
 *   php scripts/ops/recruit_import.php <目录> --unclaimed [--apply]
 *
 *   # 2) .eml 邮件：走 recruitIngestRawMessage()，plus-code 认领、MIME、附件提取整条链路都测到
 *   php scripts/ops/recruit_import.php --eml <目录> --mailbox-user=hr@example.com [--apply]
 *
 * 用途：Gmail 密钥到之前做联调；以后也可以用来补录线下收到的简历（招聘会、微信发来的）。
 *
 * 不带 --apply：只列出文件、sha、抽出的文字长度、预计状态与归属，**不上传 OSS、不写库**。
 * 幂等：文件夹模式 dedupe_key='imp:{sha256}:{code}'（同一文件同一招聘专员只入一次）；
 *       .eml 模式靠 recruit_messages 的 (mailbox_id, imap_uid) 与 Message-ID 去重。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }

$root = dirname(__DIR__, 2);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_mailsync.php';

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$opt = ['apply' => false, 'eml' => false, 'source' => null, 'unclaimed' => false,
        'received-at' => 'mtime', 'mailbox-user' => '', 'dir' => ''];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply')          $opt['apply'] = true;
    elseif ($a === '--eml')        $opt['eml'] = true;
    elseif ($a === '--unclaimed')  $opt['unclaimed'] = true;
    elseif (preg_match('/^--(source|received-at|mailbox-user)=(.*)$/', $a, $m)) $opt[$m[1]] = $m[2];
    elseif ($a[0] !== '-')         $opt['dir'] = $a;
    else { fwrite(STDERR, "未知参数 $a\n"); exit(1); }
}
$dir = rtrim($opt['dir'], '/');
if ($dir === '' || !is_dir($dir)) { fwrite(STDERR, "用法见文件头注释；目录不存在：{$dir}\n"); exit(1); }

echo "=== 招聘简历导入 " . ($opt['apply'] ? '[APPLY]' : '[DRY-RUN]') . " · " . ($opt['eml'] ? '.eml 模式' : '文件夹模式') . " ===\n\n";

// ---------------------------------------------------------------- .eml 模式
if ($opt['eml']) {
    $user = strtolower(trim($opt['mailbox-user']));
    if (!filter_var($user, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "--eml 必须给 --mailbox-user=<招聘邮箱 base 地址>\n"); exit(1); }
    $box = ['id' => 0, 'username' => $user];   // mailbox_id=0 = 本地导入，与真实邮箱的 UID 空间隔开
    $files = glob($dir . '/*.eml') ?: [];
    sort($files);
    $tot = ['new' => 0, 'dup' => 0, 'resumes' => 0];
    foreach ($files as $f) {
        $raw = file_get_contents($f);
        [$hb, ] = splitHeadersAndBody($raw);
        $H = parseMimeHeaders($hb);
        $mid = trim((string)($H['message-id'] ?? ''), " <>");
        // 没有真实 UID：用 Message-ID（没有就用文件内容）的 crc32 当 UID，重跑结果不变
        $uid = crc32($mid !== '' ? $mid : hash('sha256', $raw)) & 0x7fffffff;
        $pc  = recruitPlusCode($hb, $user);
        $src = recruitResolveSource($pdo, $pc['code']);
        $atts = extractPdfAttachments($raw, RECRUIT_RESUME_PATTERN);
        $who = $pc['ambiguous'] ? '⚠️ 歧义（多个 code）→ 待认领'
             : ((int)$src['id'] > 0 ? "+{$pc['code']} → {$src['user_name']}"
             : ($pc['code'] !== '' ? "+{$pc['code']}（未配映射）→ 待认领" : '无 code → 待认领'));
        printf("%s\n    归属: %s · 附件 %d: %s\n", basename($f), $who, count($atts),
            implode(', ', array_column($atts, 'filename')) ?: '-');
        if (!$opt['apply']) continue;
        $res = recruitIngestRawMessage($pdo, $box, $uid, $raw);
        if ($res['status'] === 'new') { $tot['new']++; $tot['resumes'] += $res['resumes'];
            printf("    ✅ 邮件 #%d，简历 %d 份\n", $res['message_id'], $res['resumes']); }
        else { $tot['dup']++; echo "    [skip] 已导入过\n"; }
    }
    printf("\n%d 封 .eml%s\n", count($files), $opt['apply']
        ? "；新增 {$tot['new']} 封 / 简历 {$tot['resumes']} 份 / 跳过 {$tot['dup']} 封" : '（dry-run，未写库。加 --apply 执行）');
    exit(0);
}

// ---------------------------------------------------------------- 文件夹模式
if ($opt['unclaimed'] === ($opt['source'] !== null)) {
    fwrite(STDERR, "文件夹模式必须二选一：--source=<code> 或 --unclaimed\n"); exit(1);
}
$src = ['id' => 0, 'user_id' => 0, 'user_name' => ''];
$code = 'none';
if ($opt['source'] !== null) {
    $code = strtolower($opt['source']);
    $src = recruitResolveSource($pdo, $code);
    if ((int)$src['id'] === 0) { fwrite(STDERR, "recruit_sources 里没有启用的 code '$code'\n"); exit(1); }
}
echo "归属: " . ((int)$src['id'] > 0 ? "+$code → {$src['user_name']}" : '待认领') . "\n\n";

$files = array_values(array_filter(glob($dir . '/*') ?: [], fn($f) => is_file($f) && preg_match(RECRUIT_RESUME_PATTERN, $f)));
sort($files);
$chk = $pdo->prepare("SELECT id FROM recruit_resumes WHERE dedupe_key=?");
$added = 0; $skipped = 0;
foreach ($files as $f) {
    $bin = file_get_contents($f);
    $sha = hash('sha256', $bin);
    $key = "imp:$sha:$code";
    $chk->execute([$key]);
    if ($chk->fetchColumn()) { $skipped++; printf("[skip] %s（已导入）\n", basename($f)); continue; }

    $ext  = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $text = in_array($ext, ['pdf', 'docx'], true) ? recruitResumeText(basename($f), $bin) : '';
    $mode = $ext === 'doc' ? 'unsupported'
          : (mb_strlen($text) >= RECRUIT_TEXT_MIN_CHARS ? 'pending·文本' : 'pending·视觉');
    printf("[%s] %-48s %7s KB  sha %s  文字 %5d 字  → %s\n", $opt['apply'] ? 'add ' : 'plan',
        mb_strimwidth(basename($f), 0, 48, '…'), number_format(strlen($bin) / 1024, 0),
        substr($sha, 0, 10), mb_strlen($text), $mode);
    if (!$opt['apply']) continue;

    $recv = $opt['received-at'] === 'now' ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', filemtime($f));
    $prep = recruitPrepareFile($pdo, basename($f), $bin, 'import');
    $added += recruitInsertResume($pdo, $prep + [
        'origin' => 'import', 'message_id' => 0, 'received_at' => $recv, 'src' => $src, 'dedupe_key' => $key,
    ]);
}
printf("\n%d 个文件；%s跳过 %d 个（已导入）\n", count($files),
    $opt['apply'] ? "新增 $added 份，" : '（dry-run，未上传未写库）', $skipped);
