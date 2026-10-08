<?php
/**
 * 职位投递链接（2026-09-26「开放一个候选人的外链」→「职位投递链接」）。
 *
 * 每个招聘专员在每个职位上有一条自己的公开链接 /apply/<token>（发到 LinkedIn / WhatsApp 群）：
 *   候选人免登录打开 → 看职位（不显示客户公司名，猎头惯例）→ 填姓名 / WhatsApp / 邮箱、勾选数据授权、上传简历 → 提交。
 * 提交的简历走原有上传通道：recruit_resumes.origin='apply'、归属 = 链接主人（与「谁收到算谁的」同口径）、target_job_id = 这个职位，
 * 解析 worker 解析出人后自动挂到职位（recruitLinkTargetJob，origin='apply'）；新简历汇总通知照常发给归属人。
 * 候选人自己填的联系方式在简历里没解析出来时补进档案（recruitApplyMergeContacts）。
 *
 * 链接长这样：/apply/hr-service-manager-jakarta-k7Qm2xRa —— 前面是职位名（只为好看、好认，需求方「这个地址太乱了」），
 * 最后一段 8 位随机码才是凭证（62^8 ≈ 2×10^14，配合限流不可枚举）；职位改名后旧链接照样能用（只认最后一段）。
 * 安全：token 随机（不可枚举）；撤销 / 职位不在招 / 项目不在进行中 → 统一「链接已失效」，不透露原因；
 * 每个 IP 每小时最多 RECRUIT_APPLY_IP_HOURLY 份、每条链接每天最多 RECRUIT_APPLY_LINK_DAILY 份；隐藏字段挡机器人；
 * 只接受简历文件类型、≤20MB；不返回任何内部数据（归属人、客户、其他候选人）。
 */

require_once __DIR__ . '/recruit_mailsync.php';   // RECRUIT_RESUME_PATTERN / recruitPrepareFile / recruitInsertResume

const RECRUIT_APPLY_IP_HOURLY = 5;
const RECRUIT_APPLY_LINK_DAILY = 300;
const RECRUIT_APPLY_MAX_BYTES = 20 * 1024 * 1024;
const RECRUIT_APPLY_LANGS = ['id', 'en', 'zh'];

function recruitApplyReady(PDO $pdo): bool {
    static $memo = [];
    $k = spl_object_id($pdo);
    if (!isset($memo[$k])) {
        try { $pdo->query("SELECT id FROM recruit_job_links WHERE 1=0"); $pdo->query("SELECT id FROM recruit_job_applications WHERE 1=0"); $memo[$k] = true; }
        catch (PDOException $e) { $memo[$k] = false; }
    }
    return $memo[$k];
}

/** 8 位随机码（大小写字母 + 数字） */
function recruitApplyNewToken(): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';   // 去掉 0/O/1/l/I，抄写不认错
    $t = '';
    for ($i = 0; $i < 8; $i++) $t .= $abc[random_int(0, strlen($abc) - 1)];
    return $t;
}

/** 链接里好看的职位名部分：只留英文字母数字，空格变短横，最多 48 个字符；中文标题没有英文就为空（链接只剩随机码） */
function recruitApplySlug(string $title): string {
    $s = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
    return trim(substr($s, 0, 48), '-');
}

/** 这个人在这个职位上的链接：有没撤销的就用它，没有就新建一条 @return array{id:int, token:string, slug:string, views:int, applies:int} */
function recruitApplyLinkFor(PDO $pdo, int $jobId, int $ownerId): array {
    $st = $pdo->prepare("SELECT l.id, l.token, l.views, j.title FROM recruit_job_links l JOIN recruit_jobs j ON j.id=l.job_id
                         WHERE l.job_id=? AND l.owner_user_id=? AND l.revoked=0 ORDER BY l.id DESC LIMIT 1");
    $st->execute([$jobId, $ownerId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if ($l && strlen((string)$l['token']) !== 8) { recruitApplyRevoke($pdo, $jobId, $ownerId); $l = null; }   // 早期的长 token（未上线过）换成短码
    if (!$l) {
        $ins = $pdo->prepare("INSERT INTO recruit_job_links (job_id, owner_user_id, token, revoked, views, created_by, created_at) VALUES (?, ?, ?, 0, 0, ?, (" . dbNow() . "))");
        for ($try = 0; ; $try++) {   // 撞了唯一索引就换一个码（概率极低）
            $token = recruitApplyNewToken();
            try { $ins->execute([$jobId, $ownerId, $token, $ownerId]); $newId = (int)$pdo->lastInsertId(); break; }
            catch (PDOException $e) { if ($try >= 4) throw $e; }
        }
        foreach (RECRUIT_APPLY_LANGS as $x) recruitApplyTranslation($pdo, $jobId, $x);   // 新链接：三种语言提前翻好，候选人切语言即时显示
        $t = $pdo->prepare("SELECT title FROM recruit_jobs WHERE id=?");
        $t->execute([$jobId]);
        $l = ['id' => $newId, 'token' => $token, 'views' => 0, 'title' => (string)$t->fetchColumn()];   // id 要在 INSERT 后立刻取，中间跑了别的语句 MySQL 会返回 0
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM recruit_job_applications WHERE link_id=?");
    $c->execute([(int)$l['id']]);
    return ['id' => (int)$l['id'], 'token' => (string)$l['token'], 'slug' => recruitApplySlug((string)$l['title']),
            'views' => (int)$l['views'], 'applies' => (int)$c->fetchColumn()];
}

/** 撤销：之后打开旧链接一律「已失效」；再点「投递链接」会生成新的一条 */
function recruitApplyRevoke(PDO $pdo, int $jobId, int $ownerId): int {
    $st = $pdo->prepare("UPDATE recruit_job_links SET revoked=1, revoked_at=(" . dbNow() . ") WHERE job_id=? AND owner_user_id=? AND revoked=0");
    $st->execute([$jobId, $ownerId]);
    return $st->rowCount();
}

/** token → 有效链接 + 职位（职位招聘中、项目进行中才算有效）；无效返回 null，不区分原因 */
function recruitApplyResolve(PDO $pdo, string $token): ?array {
    $token = (string)substr((string)strrchr('-' . $token, '-'), 1);   // 只认最后一段：前面的职位名改了也不影响
    if (!preg_match('/^[A-Za-z0-9]{8}$/', $token)) return null;
    $st = $pdo->prepare("SELECT l.id link_id, l.owner_user_id, j.id job_id, j.title, j.location, j.employment_type, j.salary_text, j.jd_text, j.posting_json
                         FROM recruit_job_links l JOIN recruit_jobs j ON j.id=l.job_id JOIN recruit_projects p ON p.id=j.project_id
                         WHERE l.token=? AND l.revoked=0 AND j.status='open' AND p.status='open'");
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * 对外展示的职位：只给标题、地点、类型、薪资（填了才有）、职位描述。
 * 描述优先级：这个语言的人工招聘文案 > 这个语言的 AI 译文（$tr）> 原文 JD；标题 / 薪资有译文用译文。
 * translated = 页面标「AI 翻译」；translating = 译文还在路上，页面过几秒再取。
 */
function recruitApplyPublicJob(array $l, string $lang, array $tr = ['status' => 'none', 'content' => null]): array {
    $posting = json_decode((string)($l['posting_json'] ?? ''), true) ?: [];
    $t = $tr['status'] === 'ready' && is_array($tr['content']) ? $tr['content'] : [];
    $desc = trim((string)($posting[$lang] ?? ''));
    $aiDesc = false;
    if ($desc === '' && trim((string)($t['jd_text'] ?? '')) !== '') { $desc = trim((string)$t['jd_text']); $aiDesc = true; }
    if ($desc === '') $desc = trim((string)$l['jd_text']);
    return ['title' => (string)($t['title'] ?? '') ?: (string)$l['title'], 'location' => (string)$l['location'], 'employment_type' => (string)$l['employment_type'],
            'salary' => (string)($t['salary_text'] ?? '') ?: (string)$l['salary_text'], 'description' => $desc,
            'translated' => $aiDesc, 'translating' => trim((string)($posting[$lang] ?? '')) === '' && $tr['status'] === 'generating'];
}

/**
 * 投递页切语言时职位内容也跟着翻（2026-09-26「语言切换内容也要跟着翻译」）：复用简历翻译的缓存与 worker（owner_type=job）。
 * 原文（标题 / JD / 薪资）没变且已翻好 → 直接用；没有 → 拉起 worker 异步翻（页面先显示原文、几秒后自动换成译文）。
 * 同一职位同一语言同一版原文只翻一次；翻失败 10 分钟内不重试（免登录的页面不能被刷成反复调模型）；超当日 AI 预算不翻。
 * @return array{status:string, content:?array}  status: ready / generating / failed / none
 */
function recruitApplyTranslation(PDO $pdo, int $jobId, string $lang, bool $start = true): array {
    require_once __DIR__ . '/recruit_translate.php';
    try { $pdo->query("SELECT 1 FROM recruit_translations LIMIT 1"); } catch (PDOException $e) { return ['status' => 'none', 'content' => null]; }
    $src = recruitTransSource($pdo, 'job', $jobId);
    if (!$src || !isset(RECRUIT_TRANS_LANGS[$lang])) return ['status' => 'none', 'content' => null];
    $hash = sha1(json_encode($src, JSON_UNESCAPED_UNICODE));
    $st = $pdo->prepare("SELECT * FROM recruit_translations WHERE owner_type='job' AND owner_id=? AND lang=?");
    $st->execute([$jobId, $lang]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['src_hash'] === $hash) {
        if ($row['status'] === 'ready') return ['status' => 'ready', 'content' => json_decode((string)$row['content_json'], true) ?: null];
        $fresh = strtotime((string)$row['updated_at']) >= time() - RECRUIT_TRANS_STALE_MINUTES * 60;
        if ($fresh) return ['status' => (string)$row['status'], 'content' => null];   // 正在翻 / 刚失败：不重复拉
    }
    if (!$start || recruitTokensToday($pdo) >= recruitDailyBudget($pdo)) return ['status' => 'none', 'content' => null];
    if ($row) {
        $pdo->prepare("UPDATE recruit_translations SET status='generating', src_hash=?, content_json=NULL, error=NULL, updated_at=(" . dbNow() . ") WHERE id=?")
            ->execute([$hash, (int)$row['id']]);
        $id = (int)$row['id'];
    } else {
        $pdo->prepare("INSERT INTO recruit_translations (owner_type, owner_id, lang, src_hash, status, created_by, created_at, updated_at)
                       VALUES ('job', ?, ?, ?, 'generating', 0, (" . dbNow() . "), (" . dbNow() . "))")->execute([$jobId, $lang, $hash]);
        $id = (int)$pdo->lastInsertId();
    }
    require_once __DIR__ . '/worker_spawn.php';
    $sp = workerSpawn($pdo, dirname(__DIR__) . '/scripts/recruit_translate_worker.php', [$id], 'recruit_translate');
    if (!$sp['ok']) {
        $pdo->prepare("UPDATE recruit_translations SET status='failed', error=?, updated_at=(" . dbNow() . ") WHERE id=?")->execute([mb_substr((string)$sp['error'], 0, 500), $id]);
        return ['status' => 'failed', 'content' => null];
    }
    return ['status' => 'generating', 'content' => null];
}

/** 手机号只留数字，08xx / 8xx → 62xx（印尼） */
function recruitApplyPhone(string $p): string {
    $d = preg_replace('/\D+/', '', $p);
    if (str_starts_with($d, '0')) $d = '62' . substr($d, 1);
    elseif (str_starts_with($d, '8')) $d = '62' . $d;
    return $d;
}

/**
 * 提交投递。$f = ['name' => 原文件名, 'bin' => 内容]。
 * $store = 存文件（默认 recruitPrepareFile → OSS；测试注入假的）。
 * @return array{status:string, resume_id?:int}  status: added / duplicate；校验不过抛 InvalidArgumentException(错误码)
 */
function recruitApplySubmit(PDO $pdo, array $link, array $in, array $f, string $ip, string $ua, ?callable $store = null): array {
    if (trim((string)($in['website'] ?? '')) !== '') throw new InvalidArgumentException('spam');   // 隐藏字段：人看不见，机器人会填
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
    $phone = recruitApplyPhone((string)($in['phone'] ?? ''));
    $email = mb_strtolower(trim((string)($in['email'] ?? '')));
    if ($name === '') throw new InvalidArgumentException('nameRequired');
    if (strlen($phone) < 9 || strlen($phone) > 15) throw new InvalidArgumentException('phoneInvalid');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('emailInvalid');
    if (empty($in['consent'])) throw new InvalidArgumentException('consentRequired');
    if (!preg_match(RECRUIT_RESUME_PATTERN, (string)$f['name'])) throw new InvalidArgumentException('fileType');
    if (strlen((string)$f['bin']) === 0 || strlen((string)$f['bin']) > RECRUIT_APPLY_MAX_BYTES) throw new InvalidArgumentException('fileSize');

    $since = date('Y-m-d H:i:s', time() - 3600);
    $c = $pdo->prepare("SELECT COUNT(*) FROM recruit_job_applications WHERE ip=? AND created_at >= ?");
    $c->execute([$ip, $since]);
    if ((int)$c->fetchColumn() >= RECRUIT_APPLY_IP_HOURLY) throw new InvalidArgumentException('tooMany');
    $c = $pdo->prepare("SELECT COUNT(*) FROM recruit_job_applications WHERE link_id=? AND created_at >= ?");
    $c->execute([(int)$link['link_id'], date('Y-m-d 00:00:00')]);
    if ((int)$c->fetchColumn() >= RECRUIT_APPLY_LINK_DAILY) throw new InvalidArgumentException('tooMany');

    $jobId = (int)$link['job_id'];
    $key = 'apl:' . hash('sha256', (string)$f['bin']) . ':' . $jobId;   // 同一份简历投同一个职位只收一次
    $ex = $pdo->prepare("SELECT id FROM recruit_resumes WHERE dedupe_key=?");
    $ex->execute([$key]);
    if ($rid = (int)$ex->fetchColumn()) return ['status' => 'duplicate', 'resume_id' => $rid];

    $owner = (int)$link['owner_user_id'];
    $src = ['id' => 0, 'user_id' => $owner, 'user_name' => ''];
    $u = $pdo->prepare("SELECT u.name, COALESCE(s.id,0) sid FROM users u LEFT JOIN recruit_sources s ON s.user_id=u.id AND s.active=1 WHERE u.id=? ORDER BY s.id LIMIT 1");
    $u->execute([$owner]);
    if ($row = $u->fetch(PDO::FETCH_ASSOC)) $src = ['id' => (int)$row['sid'], 'user_id' => $owner, 'user_name' => (string)$row['name']];

    $store = $store ?? fn(string $name, string $bin) => recruitPrepareFile($pdo, $name, $bin, 'apply');   // 只存 OSS；失败抛异常，由 handler 报「稍后再试」
    $prep = $store((string)$f['name'], (string)$f['bin']);
    recruitInsertResume($pdo, $prep + ['origin' => 'apply', 'message_id' => 0, 'received_at' => date('Y-m-d H:i:s'), 'src' => $src,
                                       'dedupe_key' => $key, 'target_job_id' => $jobId, 'uploaded_by' => $owner]);
    $ex->execute([$key]);
    $rid = (int)$ex->fetchColumn();
    $lang = in_array($in['lang'] ?? '', RECRUIT_APPLY_LANGS, true) ? (string)$in['lang'] : '';
    $pdo->prepare("INSERT INTO recruit_job_applications (link_id, job_id, resume_id, name, phone, email, lang, ip, user_agent, consent_at, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, (" . dbNow() . "), (" . dbNow() . "))")
        ->execute([(int)$link['link_id'], $jobId, $rid, $name, $phone, $email, $lang, mb_substr($ip, 0, 64), mb_substr($ua, 0, 255)]);
    return ['status' => 'added', 'resume_id' => $rid];
}

/**
 * 解析时调用：链接投递的简历里没解析出电话 / 邮箱 / 姓名，就用候选人自己填的补上（填的比 AI 猜的准，但简历里有的以简历为准）。
 * 改的是 $parsed（之后识别 / 新建候选人都用它），不另写库。
 */
function recruitApplyMergeContacts(PDO $pdo, int $resumeId, array $parsed): array {
    if (!recruitApplyReady($pdo)) return $parsed;
    $st = $pdo->prepare("SELECT name, phone, email FROM recruit_job_applications WHERE resume_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$resumeId]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return $parsed;
    if (empty($parsed['contacts']['phones']) && $a['phone'] !== '') $parsed['contacts']['phones'] = ['+' . $a['phone']];
    if (empty($parsed['contacts']['emails']) && $a['email'] !== '') $parsed['contacts']['emails'] = [$a['email']];
    if (trim((string)($parsed['person']['full_name'] ?? '')) === '' && $a['name'] !== '') $parsed['person']['full_name'] = $a['name'];
    return $parsed;
}
