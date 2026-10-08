<?php
/**
 * OpenHunter · 收件
 *
 * IMAP 拉招聘邮箱新邮件（或本地 .eml 导入）→ 按 plus-code 认领招聘专员 →
 * 附件（PDF/DOCX/DOC/JPG/PNG）**先存 OSS** 并抽文本 → 单事务写 recruit_messages + recruit_resumes →
 * 推进 last_uid。解析、识别候选人、匹配职位都在 cron_recruit_parse.php 里做，这里不调 LLM
 * （单份 3–8 秒，一封多份会顶到 FPM 超时，§7.10）。
 *
 * ⛔ 与签证邮件同步（mailsync.php 的 mailsyncRun）完全分开：不读 mail.*、不写 mailbox_*。
 *    只复用 ImapClient 与 MIME 工具函数。
 */

require_once __DIR__ . '/mailsync.php';

/** 收哪些附件。图片 = 手机拍的简历；.doc 存档但不解析（服务器没有转换工具）。 */
const RECRUIT_RESUME_PATTERN = '/\.(pdf|docx|doc|jpe?g|png)$/i';
/** 没附件、正文至少这么长才当作「正文就是简历」，否则是「请查收」之类的空邮件 */
const RECRUIT_BODY_MIN_CHARS = 300;
/** 文字层少于这么多字就当扫描件，交给 worker 走视觉模式 */
const RECRUIT_TEXT_MIN_CHARS = 200;

/**
 * DOCX → 纯文本。DOCX 是 zip，正文在 word/document.xml，段落 <w:p>、文字 <w:t>。
 * ⛔ 用 ZipArchive + DOMDocument 解析，不用正则剥 XML：
 *    2026-09 用正则处理 docx 毁过一份法律文件（296 段剩 74 段）。
 */
function recruitDocxText(string $bin): string {
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
        error_log('recruitDocxText: 缺 zip 或 dom 扩展');
        return '';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'rdocx_');
    file_put_contents($tmp, $bin);
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) return '';
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') return '';

        $dom = new DOMDocument();
        // LIBXML_NONET：不许解析时联网（外部实体），简历是外部来的文件
        if (!@$dom->loadXML($xml, LIBXML_NONET)) return '';
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $lines = [];
        foreach ($dom->getElementsByTagNameNS($ns, 'p') as $p) {
            $buf = '';
            foreach ($p->getElementsByTagNameNS($ns, 't') as $t) $buf .= $t->textContent;
            $buf = trim($buf);
            if ($buf !== '') $lines[] = $buf;
        }
        return implode("\n", $lines);
    } finally {
        @unlink($tmp);
    }
}

/** 按扩展名分派到 PDF / DOCX 抽文本。抽不出来返回空串，由调用方标 no_text。 */
function recruitResumeText(string $filename, string $bin): string {
    if (preg_match('/\.pdf$/i', $filename))  return trim(itkExtractPdfText($bin));
    if (preg_match('/\.docx$/i', $filename)) return trim(recruitDocxText($bin));
    return '';
}

/**
 * 从原始邮件头里找「本邮箱 base 地址 + plus-code」。
 *
 * 邮箱 username 为 hr@example.com 时，只认 hr+<code>@gmail.com，
 * 别的 plus 地址（抄送里别人的）一律不算。
 *
 * ⛔ 不用 parseMimeHeaders()：它同名头只留第一个值，转发链里有多个 To/Delivered-To 时会漏。
 * ⛔ 出现两个**不同**的 code 视为歧义，返回 ambiguous=true，由调用方落「待认领」，不猜——
 *    归属直接决定绩效算在谁头上，猜错比不填更糟。
 *
 * @return array{code:string, ambiguous:bool}
 */
function recruitPlusCode(string $headerBlock, string $mailboxUser): array {
    $mailboxUser = strtolower(trim($mailboxUser));
    if (!preg_match('/^([^@+]+)@(.+)$/', $mailboxUser, $m)) return ['code' => '', 'ambiguous' => false];
    [$base, $domain] = [$m[1], $m[2]];

    // 只看地址类头；正文里出现的地址不算
    $codes = [];
    $pat = '/^(delivered-to|x-original-to|to|cc):(.*(?:\r?\n[ \t].*)*)/im';
    if (preg_match_all($pat, $headerBlock, $hs)) {
        foreach ($hs[2] as $val) {
            $addrPat = '/' . preg_quote($base, '/') . '\+([a-z0-9_-]{1,32})@' . preg_quote($domain, '/') . '/i';
            if (preg_match_all($addrPat, $val, $am)) {
                foreach ($am[1] as $c) $codes[strtolower($c)] = true;
            }
        }
    }
    $codes = array_keys($codes);
    if (count($codes) === 1) return ['code' => $codes[0], 'ambiguous' => false];
    if (count($codes) > 1)   return ['code' => '', 'ambiguous' => true];
    return ['code' => '', 'ambiguous' => false];
}

/* 发件人黑名单（2026-09-24「解析失败的有时是猎头公司的广告，直接把邮箱拉黑，以后都不解析」）：
   recruit_sender_blocks.pattern = 完整邮箱（ads@hunter.co.id）或 @域名（@hunter.co.id，整家公司）。
   命中的邮件照样记一行 recruit_messages（IMAP 去重靠它，不然每轮都重下），但不存附件、不建简历、不花 token。
   公共邮箱域名不许整域拉黑——拉黑 @gmail.com 等于不收简历了。 */
const RECRUIT_PUBLIC_MAIL_DOMAINS = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.id', 'ymail.com', 'outlook.com', 'hotmail.com',
    'live.com', 'msn.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'qq.com', '163.com', '126.com', 'foxmail.com', 'sina.com'];

/** 「张三 <Zhang@Hunter.co.id>」→ zhang@hunter.co.id；认不出返回 '' */
function recruitSenderEmail(string $from): string {
    if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $from, $m) || preg_match('/([^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+)/', $from, $m)) {
        return strtolower(trim($m[1], '.'));
    }
    return '';
}

/** 命中哪条黑名单（完整邮箱优先，其次 @域名）；没命中 / 表还没建 → null */
function recruitSenderBlocked(PDO $pdo, string $email): ?string {
    if ($email === '' || !str_contains($email, '@')) return null;
    try {
        $st = $pdo->prepare("SELECT pattern FROM recruit_sender_blocks WHERE pattern IN (?, ?) ORDER BY LENGTH(pattern) DESC LIMIT 1");
        $st->execute([$email, '@' . substr($email, strpos($email, '@') + 1)]);
        $p = $st->fetchColumn();
        return $p === false ? null : (string)$p;
    } catch (PDOException $e) { return null; }
}

/** SQL：某条黑名单覆盖的邮件（m = recruit_messages）。@return array{0:string,1:array} */
function recruitSenderBlockSql(string $pattern): array {
    // from_addr 存的是整段「名字 <邮箱>」：按邮箱结尾 / 尖括号内匹配，大小写不敏感；邮箱里的 _ % 转义，别当通配符
    $e = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $pattern);
    return $pattern[0] === '@'
        ? ["(LOWER(m.from_addr) LIKE ? ESCAPE '!' OR LOWER(m.from_addr) LIKE ? ESCAPE '!')", ['%' . $e . '>', '%' . $e]]
        : ["(LOWER(m.from_addr) LIKE ? ESCAPE '!' OR LOWER(m.from_addr)=?)", ['%<' . $e . '>', $pattern]];
}

/**
 * 处理一封原始邮件：认领 → 附件存 OSS → 单事务写邮件行与简历行。
 * IMAP 同步与 scripts/ops/recruit_import.php --eml 共用这一个入口，保证测到的就是线上跑的。
 *
 * @param array $box  至少含 id（导入时为 0）与 username（用于认 plus-code）
 * @return array{status:string, message_id:int, resumes:int, unclaimed:bool}
 *         status: new | duplicate
 */
function recruitIngestRawMessage(PDO $pdo, array $box, int $uid, string $raw): array {
    $bid = (int)$box['id'];
    [$headerBlock, ] = splitHeadersAndBody($raw);
    $H = parseMimeHeaders($headerBlock);
    $messageId = trim((string)($H['message-id'] ?? ''), " <>");

    $dup = $pdo->prepare("SELECT 1 FROM recruit_messages
                          WHERE (mailbox_id=? AND imap_uid=?) OR (message_id<>'' AND message_id=?) LIMIT 1");
    $dup->execute([$bid, $uid, $messageId]);
    if ($dup->fetchColumn()) return ['status' => 'duplicate', 'message_id' => 0, 'resumes' => 0, 'unclaimed' => false];

    $subject = decodeMimeHeader((string)($H['subject'] ?? ''));
    $from    = decodeMimeHeader((string)($H['from'] ?? ''));
    $to      = decodeMimeHeader((string)($H['to'] ?? ''));
    $date    = recruitParseMailDate((string)($H['date'] ?? ''));
    $bodies  = extractTextBodies($raw);
    $bodyTxt = trim((string)($bodies['text'] ?? ''));
    if ($bodyTxt === '' && !empty($bodies['html'])) $bodyTxt = trim(html_entity_decode(strip_tags((string)$bodies['html'])));
    $bodyTxt = mb_substr($bodyTxt, 0, 20000);

    // 归属
    $pc  = recruitPlusCode($headerBlock, (string)($box['username'] ?? ''));
    $src = recruitResolveSource($pdo, $pc['code']);
    // 黑名单发件人：只记邮件行，附件不存、简历不建
    $blockedBy = recruitSenderBlocked($pdo, recruitSenderEmail($from));

    // 附件先存 OSS（事务外：失败最多留孤儿文件，不会有半截数据）
    $files = [];
    if ($blockedBy === null) {
        foreach (extractPdfAttachments($raw, RECRUIT_RESUME_PATTERN) as $i => $att) {
            $files[] = recruitPrepareFile($pdo, $att['filename'], $att['data'], 'mbox' . $bid) + ['idx' => $i];
        }
    }

    $pdo->beginTransaction();
    try {
        $mi = $pdo->prepare(dbInsertIgnore() . " recruit_messages
            (mailbox_id, imap_uid, message_id, subject, from_addr, to_addr, plus_code, source_id,
             received_at, body_text, attachment_count, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,(" . dbNow() . "))");
        $mi->execute([$bid, $uid, $messageId, $subject, mb_substr($from, 0, 255), mb_substr($to, 0, 255),
                      $pc['ambiguous'] ? '?' : $pc['code'], (int)$src['id'], $date, $bodyTxt, count($files)]);
        /* ⛔ 判 IGNORE 生效用 rowCount 不用 lastInsertId：SQLite 的 INSERT OR IGNORE
           被忽略时 lastInsertId 返回的是**上一次**插入的 id 而不是 0，会把简历挂错邮件 */
        if ($mi->rowCount() === 0) {
            $pdo->rollBack();
            return ['status' => 'duplicate', 'message_id' => 0, 'resumes' => 0, 'unclaimed' => false];
        }
        $mid = (int)$pdo->lastInsertId();

        $n = 0;
        foreach ($files as $f) {
            $n += recruitInsertResume($pdo, $f + [
                'origin' => 'email', 'message_id' => $mid, 'received_at' => $date, 'src' => $src,
                'dedupe_key' => "msg:$mid:{$f['idx']}:" . substr($f['sha256'], 0, 12),
            ]);
        }
        if ($blockedBy !== null) {
            $pdo->prepare("UPDATE recruit_sender_blocks SET hits=hits+1, last_hit_at=(" . dbNow() . ") WHERE pattern=?")->execute([$blockedBy]);
        }
        // 没附件但正文像简历：正文本身当一份简历（worker 判 doc_type，不是简历会标 other）
        elseif (!$files && mb_strlen($bodyTxt) >= RECRUIT_BODY_MIN_CHARS) {
            $n += recruitInsertResume($pdo, [
                'origin' => 'email', 'message_id' => $mid, 'received_at' => $date, 'src' => $src,
                'dedupe_key' => "msg:$mid:body", 'path' => '', 'name' => '', 'ext' => '', 'size' => 0,
                'sha256' => hash('sha256', $bodyTxt), 'text' => $bodyTxt, 'status' => 'pending',
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['status' => 'new', 'message_id' => $mid, 'resumes' => $n, 'unclaimed' => (int)$src['id'] === 0, 'blocked' => $blockedBy];
}

/**
 * 邮件 Date 头 → 业务时间（雅加达，PHP 时区已在 config/database.php:9 设好）。解析不了取当前时间。
 * ⛔ 先去掉开头的星期几：strtotime("Tue, 23 Sep 2026") 在星期与日期对不上时
 *    会跳到「下一个星期二」（实测 23 号 → 29 号）。归属按收件时间判先后，错一天就可能判给错的人。
 */
function recruitParseMailDate(string $hdr): string {
    $ts = trim($hdr) !== '' ? strtotime(preg_replace('/^\s*[A-Za-z]{3},\s*/', '', $hdr)) : false;
    return date('Y-m-d H:i:s', $ts ?: time());
}

/** plus-code → 招聘专员。code 为空或没配映射 → 待认领（id=0），不猜 */
function recruitResolveSource(PDO $pdo, string $code): array {
    $none = ['id' => 0, 'user_id' => 0, 'user_name' => ''];
    if ($code === '') return $none;
    $st = $pdo->prepare("SELECT id, user_id, user_name FROM recruit_sources WHERE code=? AND active=1");
    $st->execute([$code]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: $none;
}

/**
 * 单个文件：存 OSS + 抽文本 + 定初始解析状态。
 * 状态：.doc → unsupported（存档可下载，不解析）；其余 pending（文字不够的由 worker 走视觉模式）。
 */
/**
 * 附件 → OSS（内存直传 putObject，不落本地盘）。
 * ⛔ 2026-09-24：生产机磁盘很小，招聘附件只存 OSS。**不走 uploadAttachment 的本地 fallback**——
 *    OSS 没开或上传失败就抛异常：邮件同步不推进游标、下轮重试；页面上传直接报错。宁可慢，不写满磁盘。
 * 抽文字时 PDF（pdftotext）/ DOCX（ZipArchive）各要一个临时文件，用完即删（毫秒级），不会堆积。
 */
function recruitPrepareFile(PDO $pdo, string $filename, string $bin, string $scope): array {
    require_once __DIR__ . '/oss.php';
    if (!ossIsEnabled($pdo)) throw new RuntimeException('OSS 未启用：招聘附件只存 OSS，不写本地磁盘（系统设置 · OSS 存储）');
    $safe = basename($filename);
    $path = ossUploadContent($pdo, $bin, ossBuildKey($pdo, 'recruit', $scope, $safe));
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return [
        'path' => $path, 'name' => $safe, 'ext' => $ext,
        'size' => strlen($bin), 'sha256' => hash('sha256', $bin),
        'text' => in_array($ext, ['pdf', 'docx'], true) ? recruitResumeText($filename, $bin) : '',
        'status' => $ext === 'doc' ? 'unsupported' : 'pending',
    ];
}

/** 写一行 recruit_resumes；dedupe_key 撞了返回 0（幂等），否则 1 */
function recruitInsertResume(PDO $pdo, array $r): int {
    $src = $r['src'];
    $st = $pdo->prepare(dbInsertIgnore() . " recruit_resumes
        (origin, message_id, dedupe_key, source_id, source_user_id, source_user_name, received_at,
         file_path, file_name, file_ext, file_size, file_sha256, raw_text, text_chars,
         parse_status, target_job_id, uploaded_by, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,(" . dbNow() . "),(" . dbNow() . "))");
    $st->execute([
        $r['origin'], (int)$r['message_id'], $r['dedupe_key'],
        (int)$src['id'], (int)$src['user_id'], (string)$src['user_name'], $r['received_at'],
        $r['path'], $r['name'], $r['ext'], (int)$r['size'], $r['sha256'],
        $r['text'], mb_strlen((string)$r['text']), $r['status'],
        (int)($r['target_job_id'] ?? 0), (int)($r['uploaded_by'] ?? 0),
    ]);
    return $st->rowCount() > 0 ? 1 : 0;
}

/**
 * 同步所有启用的招聘邮箱。每封邮件处理完（含事务提交）才推进 last_uid，
 * 中途出错下次从这封重来，唯一键保证不重复入库。
 */
/** 单封邮件本轮内最多试几次（每次失败重连 IMAP 再试） */
const RECRUIT_MAIL_TRIES = 3;
const RECRUIT_MAIL_RETRY_SLEEP = [2, 5];
/** 跨轮次：一封邮件累计失败这么多轮就放弃（gave_up），页面「招聘邮箱」里能看到并手动重试 */
const RECRUIT_MAIL_MAX_ROUNDS = 10;

/** 连 IMAP 并选好文件夹；失败抛异常 */
function recruitImapOpen(array $box, string $pass): ImapClient {
    $c = new ImapClient();
    if (!$c->connect((string)$box['imap_host'], (int)$box['imap_port'], (int)$box['imap_ssl'] === 1)) throw new RuntimeException('IMAP 连接失败：' . $c->lastError);
    if (!$c->login((string)$box['username'], $pass)) { $e = $c->lastError; $c->close(); throw new RuntimeException('IMAP 登录失败：' . $e); }
    if (!$c->select((string)($box['folder'] ?: 'INBOX'))) { $e = $c->lastError; $c->close(); throw new RuntimeException('SELECT 失败：' . $e); }
    return $c;
}

/** 记一次失败（跨轮次重试用）；累计到上限标 gave_up */
function recruitMailFailureRecord(PDO $pdo, int $bid, int $uid, string $err): string {
    $st = $pdo->prepare("SELECT id, attempts FROM recruit_mail_failures WHERE mailbox_id=? AND imap_uid=?");
    $st->execute([$bid, $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $n = $row ? (int)$row['attempts'] + 1 : 1;
    $status = $n >= RECRUIT_MAIL_MAX_ROUNDS ? 'gave_up' : 'pending';
    if ($row) {
        $pdo->prepare("UPDATE recruit_mail_failures SET attempts=?, status=?, last_error=?, last_try_at=(" . dbNow() . ") WHERE id=?")
            ->execute([$n, $status, mb_substr($err, 0, 1000), (int)$row['id']]);
    } else {
        $pdo->prepare("INSERT INTO recruit_mail_failures (mailbox_id, imap_uid, attempts, status, last_error, first_failed_at, last_try_at)
                       VALUES (?, ?, 1, ?, ?, (" . dbNow() . "), (" . dbNow() . "))")->execute([$bid, $uid, $status, mb_substr($err, 0, 1000)]);
    }
    return $status;
}

/**
 * 同步所有启用的招聘邮箱。
 *
 * 容错（2026-09-24）：
 *   ① 单封邮件本轮内最多试 RECRUIT_MAIL_TRIES 次，每次失败先重连 IMAP（连接断了后面每封都会失败）
 *   ② 还失败：记进 recruit_mail_failures、游标照常往后走——一封坏邮件不再卡住后面所有邮件；
 *      之后每一轮**先重试失败清单**，成功就标 done；累计 RECRUIT_MAIL_MAX_ROUNDS 轮仍失败标 gave_up
 *   ③ 登录失败 / 连不上：整个邮箱本轮跳过、游标不动，下轮再来
 * 重复处理安全：recruit_messages 按 (mailbox_id, imap_uid) 唯一，重试到已入库的邮件只会判 duplicate。
 *
 * @param callable|null $progress 手动跑时逐封打印进度：fn(string $event, array $info)
 *        event = connected（box, total, batch, retry）/ message（box, i, n, uid, status, resumes, kb, sec, tries, retry）
 */
function recruitMailSyncRun(PDO $pdo, int $maxPerMailbox = 50, ?callable $progress = null): array {
    $boxes = $pdo->query("SELECT * FROM recruit_mailboxes WHERE enabled=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$boxes) return ['ok' => true, 'msg' => '没有启用的招聘邮箱', 'boxes' => []];
    $report = [];

    foreach ($boxes as $box) {
        $bid = (int)$box['id'];
        $r = ['mailbox_id' => $bid, 'name' => $box['name'], 'fetched' => 0, 'new' => 0, 'resumes' => 0, 'unclaimed' => 0,
              'remaining' => 0, 'failed' => 0, 'retried_ok' => 0, 'gave_up' => 0, 'error' => ''];
        $pass = '';
        try { $pass = decryptSecret((string)$box['password_enc']); } catch (Throwable $e) {}
        if ($pass === '') {
            $r['error'] = '密码为空或解密失败';
            recruitMailboxMark($pdo, $bid, $r['error']);
            $report[] = $r; continue;
        }
        $c = null;
        try {
            // OSS 没开：每封都会失败，逐封重试只会白等、还把整批塞进失败清单——整个邮箱直接停，游标不动
            require_once __DIR__ . '/oss.php';
            if (!ossIsEnabled($pdo)) throw new RuntimeException('OSS 未启用：招聘附件只存 OSS，先在系统设置 · OSS 存储里配好');
            $c = recruitImapOpen($box, $pass);

            /* 取一封：失败重连再试，最多 RECRUIT_MAIL_TRIES 次。成功返回 [res, kb, tries]，全失败返回 [null, error, tries]。
               重连失败（IMAP 整体挂了）直接抛出，本邮箱本轮到此为止。 */
            $one = function (int $uid) use (&$c, $pdo, $box, $pass): array {
                $err = '';
                for ($t = 1; $t <= RECRUIT_MAIL_TRIES; $t++) {
                    try {
                        $raw = $c->uidFetchRaw($uid);
                        if (!$raw) throw new RuntimeException("取 UID $uid 失败：" . $c->lastError);
                        return [recruitIngestRawMessage($pdo, $box, $uid, $raw), (int)(strlen($raw) / 1024), $t];
                    } catch (Throwable $e) {
                        $err = $e->getMessage();
                        if ($t === RECRUIT_MAIL_TRIES) break;
                        sleep(RECRUIT_MAIL_RETRY_SLEEP[$t - 1] ?? 5);
                        try { $c->close(); } catch (Throwable $x) {}
                        $c = recruitImapOpen($box, $pass);
                    }
                }
                return [null, $err, RECRUIT_MAIL_TRIES];
            };
            $tally = function (array $res) use (&$r) {
                if ($res['status'] !== 'new') return;
                $r['new']++; $r['resumes'] += $res['resumes'];
                if ($res['unclaimed']) $r['unclaimed']++;
            };

            // ① 先重试之前失败的
            $fs = $pdo->prepare("SELECT imap_uid FROM recruit_mail_failures WHERE mailbox_id=? AND status='pending' ORDER BY imap_uid LIMIT 50");
            $fs->execute([$bid]);
            $retry = array_map('intval', $fs->fetchAll(PDO::FETCH_COLUMN));
            $done = $pdo->prepare("UPDATE recruit_mail_failures SET status='done', last_error=NULL, last_try_at=(" . dbNow() . ") WHERE mailbox_id=? AND imap_uid=?");

            // ② 再拉新的
            $last = (int)$box['last_uid'];
            /* ⛔ IMAP 的 `UID n:*` 在没有新邮件时仍返回当前最大 UID（* 永远指最后一封），
               所以必须在这里再滤一遍 > last，否则最后一封每次都被当新邮件。 */
            $all = array_values(array_filter($c->uidSearch('UID ' . ($last + 1) . ':*'), fn($u) => $u > $last));
            sort($all);                                    // 从旧到新，保证 last_uid 单调推进
            $uids = array_slice($all, 0, $maxPerMailbox);
            $r['remaining'] = count($all) - count($uids);  // 补拉历史时看还剩多少
            if ($progress) $progress('connected', ['box' => $box['username'], 'total' => count($all), 'batch' => count($uids), 'retry' => count($retry)]);

            $queue = array_merge(array_map(fn($u) => [$u, true], $retry), array_map(fn($u) => [(int)$u, false], $uids));
            foreach ($queue as $i => [$uid, $isRetry]) {
                $t0 = microtime(true);
                [$res, $kbOrErr, $tries] = $one($uid);
                if ($res !== null) {
                    $tally($res);
                    if ($isRetry) { $done->execute([$bid, $uid]); $r['retried_ok']++; }
                } else {
                    $st = recruitMailFailureRecord($pdo, $bid, $uid, (string)$kbOrErr);
                    $r['failed']++;
                    if ($st === 'gave_up') $r['gave_up']++;
                }
                if (!$isRetry) { $r['fetched']++; recruitMailboxAdvance($pdo, $bid, $uid); }   // 失败也往后走，失败的进重试清单
                if ($progress) $progress('message', ['box' => $box['username'], 'i' => $i + 1, 'n' => count($queue), 'uid' => $uid,
                    'retry' => $isRetry, 'tries' => $tries, 'status' => $res['status'] ?? 'failed', 'resumes' => (int)($res['resumes'] ?? 0),
                    'kb' => $res !== null ? (int)$kbOrErr : 0, 'error' => $res === null ? (string)$kbOrErr : '', 'sec' => round(microtime(true) - $t0, 1)]);
            }
            recruitMailboxMark($pdo, $bid, $r['failed'] > 0 ? "本轮 {$r['failed']} 封拉取失败，已记入重试清单" : '');
        } catch (Throwable $e) {
            $r['error'] = $e->getMessage();
            recruitMailboxMark($pdo, $bid, $r['error']);
        } finally {
            if ($c) { try { $c->close(); } catch (Throwable $x) {} }
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM recruit_mail_failures WHERE mailbox_id=? AND status='pending'");
        $cnt->execute([$bid]);
        $r['pending_failures'] = (int)$cnt->fetchColumn();
        $report[] = $r;
    }
    $ok = !array_filter($report, fn($x) => $x['error'] !== '');
    return ['ok' => $ok, 'msg' => $ok ? '完成' : '部分邮箱出错', 'boxes' => $report];
}

/** 推进 last_uid（只增不减，防止乱序时回退） */
function recruitMailboxAdvance(PDO $pdo, int $bid, int $uid): void {
    $pdo->prepare("UPDATE recruit_mailboxes SET last_uid=?, updated_at=(" . dbNow() . ") WHERE id=? AND last_uid<?")
        ->execute([$uid, $bid, $uid]);
}

/** 记录本轮同步结果；$err 为空表示成功 */
function recruitMailboxMark(PDO $pdo, int $bid, string $err): void {
    $pdo->prepare("UPDATE recruit_mailboxes SET last_sync_at=(" . dbNow() . "), last_error=?, updated_at=(" . dbNow() . ") WHERE id=?")
        ->execute([mb_substr($err, 0, 1000), $bid]);
}
