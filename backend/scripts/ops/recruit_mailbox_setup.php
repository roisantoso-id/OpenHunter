<?php
/**
 * 配置招聘邮箱（招聘模块 P1）
 *
 *   php scripts/ops/recruit_mailbox_setup.php                         # 列出已配置的邮箱
 *   php scripts/ops/recruit_mailbox_setup.php add <邮箱地址> [名称]     # 新增/改密码，交互输入应用专用密码
 *   php scripts/ops/recruit_mailbox_setup.php enable  <id>
 *   php scripts/ops/recruit_mailbox_setup.php disable <id>
 *   php scripts/ops/recruit_mailbox_setup.php skip-existing <id>        # 跳过收件箱里已有的邮件，只拉以后的
 *
 * ⛔ 密码只从终端交互读取（关回显），**不接受命令行参数**：参数对同机任意用户 `ps aux` 可见，
 *    也会进 shell history。
 * ⛔ 必须是 Gmail「应用专用密码」（16 位），不是登录密码——开了两步验证的账号用登录密码 IMAP 必然失败。
 *    生成：Google 账号 → 安全性 → 两步验证 → 应用专用密码。并在 Gmail 设置里开启 IMAP。
 * ⛔ 先试登录，成功才落库。存进一个错密码的话，要等 cron 报错才发现。
 *
 * 新增的邮箱默认 **enabled=0**，确认无误后再 enable——
 * 首次同步会把收件箱里已有的邮件全部当简历处理（受每轮 50 封上限约束，分批拉完）。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Only CLI allowed\n"); }

$root = dirname(__DIR__, 2);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_mailsync.php';

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cmd = $argv[1] ?? 'list';

function readSecret(string $prompt): string {
    if (!stream_isatty(STDIN)) { fwrite(STDERR, "⛔ 需要交互式终端输入密码\n"); exit(1); }
    fwrite(STDOUT, $prompt);
    shell_exec('stty -echo');
    $v = trim((string)fgets(STDIN));
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
    return $v;
}

switch ($cmd) {
case 'list':
    $rows = $pdo->query("SELECT id, name, username, enabled, last_uid, last_sync_at, COALESCE(last_error,'') err
                         FROM recruit_mailboxes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "（还没有招聘邮箱。用 add <邮箱地址> 新增）\n";
    foreach ($rows as $r) {
        printf("#%-3s %-28s %-10s %s  last_uid=%-6s 上次同步 %s%s\n", $r['id'], $r['username'],
            $r['name'], $r['enabled'] ? '✅启用' : '⏸停用', $r['last_uid'], $r['last_sync_at'] ?? '-',
            $r['err'] !== '' ? "\n     ✗ " . $r['err'] : '');
    }
    echo "\n--- 来源映射（plus-code → 招聘专员）---\n";
    foreach ($pdo->query("SELECT code, user_id, user_name, active FROM recruit_sources ORDER BY code") as $s)
        printf("  +%-4s → #%-4s %s%s\n", $s['code'], $s['user_id'], $s['user_name'], $s['active'] ? '' : '（停用）');
    break;

case 'add':
    $user = strtolower(trim((string)($argv[2] ?? '')));
    if (!filter_var($user, FILTER_VALIDATE_EMAIL) || strpos($user, '+') !== false) {
        fwrite(STDERR, "用法: add <邮箱地址> [名称]（填 base 地址，不带 +code）\n"); exit(1);
    }
    $name = trim((string)($argv[3] ?? '招聘邮箱'));
    $pass = str_replace(' ', '', readSecret("应用专用密码（输入不显示）: "));   // Google 显示成 4 组，粘贴常带空格
    if ($pass === '') { fwrite(STDERR, "密码为空，中止\n"); exit(1); }

    echo "试登录 imap.gmail.com:993 … ";
    $c = new ImapClient();
    if (!$c->connect('imap.gmail.com', 993, true)) { echo "✗ 连接失败：{$c->lastError}\n"; exit(1); }
    $okLogin = $c->login($user, $pass);
    $loginErr = $c->lastError;          // 先存下来：close() 发 LOGOUT 会覆盖 lastError
    $okSel = $okLogin && $c->select('INBOX');
    $total = $okSel ? count($c->uidSearch('ALL')) : 0;
    $c->close();
    if (!$okLogin) { echo "✗ 登录失败：{$loginErr}\n   检查：是否用了应用专用密码、Gmail 设置里是否开启 IMAP\n"; exit(1); }
    if (!$okSel)   { echo "✗ 打不开 INBOX\n"; exit(1); }
    echo "✓（收件箱现有 $total 封）\n";

    $enc = encryptSecret($pass);
    $ex = $pdo->prepare("SELECT id FROM recruit_mailboxes WHERE username=?");
    $ex->execute([$user]);
    if ($id = (int)$ex->fetchColumn()) {
        $pdo->prepare("UPDATE recruit_mailboxes SET password_enc=?, name=?, last_error='', updated_at=(" . dbNow() . ") WHERE id=?")
            ->execute([$enc, $name, $id]);
        echo "✅ 已更新 #$id 的密码（启用状态与 last_uid 不变）\n";
    } else {
        $pdo->prepare("INSERT INTO recruit_mailboxes (name, username, password_enc, enabled, created_at, updated_at)
                       VALUES (?, ?, ?, 0, (" . dbNow() . "), (" . dbNow() . "))")
            ->execute([$name, $user, $enc]);
        $id = (int)$pdo->lastInsertId();
        echo "✅ 已新增 #{$id}（**未启用**）。\n";
        if ($total > 0) echo "   ⚠️ 收件箱已有 $total 封，启用后会全部当作投递处理。只要以后的，先跑：\n"
                           . "      php scripts/ops/recruit_mailbox_setup.php skip-existing $id\n";
        echo "   确认后：php scripts/ops/recruit_mailbox_setup.php enable $id\n";
    }
    break;

case 'enable':
case 'disable':
    $id = (int)($argv[2] ?? 0);
    $st = $pdo->prepare("UPDATE recruit_mailboxes SET enabled=?, updated_at=(" . dbNow() . ") WHERE id=?");
    $st->execute([$cmd === 'enable' ? 1 : 0, $id]);
    echo $st->rowCount() ? "✅ #$id 已" . ($cmd === 'enable' ? '启用' : '停用') . "\n" : "✗ 没有 #$id\n";
    break;

case 'skip-existing':
    // 把 last_uid 推到收件箱当前最大 UID：之前的邮件不再处理，只拉以后新来的
    $id = (int)($argv[2] ?? 0);
    $st = $pdo->prepare("SELECT * FROM recruit_mailboxes WHERE id=?");
    $st->execute([$id]);
    $box = $st->fetch(PDO::FETCH_ASSOC);
    if (!$box) { echo "✗ 没有 #$id\n"; exit(1); }
    $c = new ImapClient();
    if (!$c->connect((string)$box['imap_host'], (int)$box['imap_port'], (int)$box['imap_ssl'] === 1)
        || !$c->login((string)$box['username'], decryptSecret((string)$box['password_enc']))
        || !$c->select((string)($box['folder'] ?: 'INBOX'))) {
        echo "✗ 连不上：{$c->lastError}\n"; exit(1);
    }
    $uids = $c->uidSearch('ALL');
    $c->close();
    $max = $uids ? max($uids) : 0;
    recruitMailboxAdvance($pdo, $id, $max);   // 只增不减
    echo "✅ #$id last_uid → {$max}（跳过现有 " . count($uids) . " 封）\n";
    break;

default:
    fwrite(STDERR, "未知命令 {$cmd}。可用：list / add / enable / disable / skip-existing\n"); exit(1);
}
