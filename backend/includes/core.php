<?php
/**
 * 基础设施：JSON 响应、登录 token、权限、操作日志、系统设置。
 *
 * 权限模型：users.role = 'admin' 拥有全部权限；其它角色的模块列表存在 role_permissions.modules（JSON 数组），
 * 单个用户可在 users.extra_modules 追加。招聘用到的模块：recruit / recruit_all / recruit_admin。
 */

require_once __DIR__ . '/db_dialect.php';

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function b64urlEncode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64urlDecode(string $s): string {
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return (string)base64_decode(strtr($s, '-_', '+/'), true);
}

/**
 * token 签名密钥：环境变量 OPENHUNTER_TOKEN_SECRET（≥32 字符）。
 * 没配就拒绝签发 / 校验（fail closed）——发一张验不过的 token 比登录失败更难排查。
 */
function tokenSecret(): string {
    $k = ohEnv('OPENHUNTER_TOKEN_SECRET');
    if (strlen($k) >= 32) return $k;
    throw new RuntimeException('缺少 OPENHUNTER_TOKEN_SECRET（至少 32 字符）。生成：php -r "echo bin2hex(random_bytes(32));"');
}

function generateToken($userId, int $ttl = 86400) {
    $body = b64urlEncode(json_encode(['user_id' => (int)$userId, 'exp' => time() + $ttl, 'iat' => time()]));
    return $body . '.' . b64urlEncode(hash_hmac('sha256', $body, tokenSecret(), true));
}

/** 校验 token 串，返回 user_id 或 null */
function verifyTokenString($token) {
    $token = (string)$token;
    $dot = strpos($token, '.');
    if ($dot === false || $dot === 0 || $dot === strlen($token) - 1) return null;
    $body = substr($token, 0, $dot);
    $sig  = b64urlDecode(substr($token, $dot + 1));
    try {
        $expect = hash_hmac('sha256', $body, tokenSecret(), true);
    } catch (RuntimeException $e) {
        error_log('[auth] ' . $e->getMessage());
        return null;
    }
    if (!hash_equals($expect, $sig)) return null;
    $payload = json_decode(b64urlDecode($body), true);
    if (!$payload || !isset($payload['user_id'], $payload['exp'])) return null;
    if ($payload['exp'] < time()) return null;
    return $payload['user_id'];
}

function verifyToken() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return null;
    return verifyTokenString($m[1]);
}

function getCurrentUserName($pdo) {
    $userId = verifyToken();
    if (!$userId) return '';
    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return (string)($stmt->fetchColumn() ?: '');
}

function logOperation($pdo, $userId, $username, $action, $targetType = '', $targetId = 0, $detail = '') {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $pdo->prepare("INSERT INTO operation_logs (user_id, username, action, target_type, target_id, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, (" . dbNow() . "))")
            ->execute([$userId, $username, $action, $targetType, $targetId, $detail, $ip]);
    } catch (PDOException $e) {
        // 日志失败不影响主流程
    }
}

/** 角色模块列表（admin 不走这里，见 userHasModule） */
function userModules($pdo, int $userId): array {
    $s = $pdo->prepare("SELECT role, extra_modules FROM users WHERE id = ?");
    $s->execute([$userId]);
    $u = $s->fetch();
    if (!$u) return [];
    $mods = [];
    $p = $pdo->prepare("SELECT modules FROM role_permissions WHERE role = ?");
    $p->execute([(string)$u['role']]);
    $mods = json_decode((string)($p->fetchColumn() ?: '[]'), true) ?: [];
    $extra = json_decode((string)($u['extra_modules'] ?? '[]'), true) ?: [];
    return array_values(array_unique(array_merge((array)$mods, (array)$extra)));
}

function userHasModule($pdo, $userId, string $moduleKey): bool {
    $s = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $s->execute([$userId]);
    if ((string)($s->fetchColumn() ?: '') === 'admin') return true;
    return in_array($moduleKey, userModules($pdo, (int)$userId), true);
}

/** 通知类型开关：system_settings 里 notif_enabled_<type> = '0' 才关，默认开 */
function isNotifEnabled($pdo, $type) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'notif_enabled_%'")->fetchAll() as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (PDOException $e) {}
    }
    return (($cache['notif_enabled_' . $type] ?? '1') !== '0');
}

function setSystemSetting($pdo, $key, $value) {
    try {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, (" . dbNow() . "))
                       " . dbOnConflict(['setting_key'], ['setting_value'], ["updated_at=(" . dbNow() . ")"]))
            ->execute([$key, (string)$value]);
    } catch (PDOException $e) { error_log('setSystemSetting failed: ' . $e->getMessage()); }
}

// ---------- 账号 ----------

function handleLogin($pdo, $input) {
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($username === '' || $password === '') {
        jsonResponse(['success' => false, 'errorMessage' => 'Username and password are required'], 400);
    }
    // 简单限流：同一 IP 10 分钟内失败 10 次即拒绝
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $c = $pdo->prepare("SELECT COUNT(*) FROM operation_logs WHERE action='login_failed' AND ip=? AND created_at > (" . dbNowOffset('-10 minutes') . ")");
    $c->execute([$ip]);
    if ((int)$c->fetchColumn() >= 10) {
        jsonResponse(['success' => false, 'errorMessage' => 'Too many attempts, try again later'], 429);
    }
    $s = $pdo->prepare("SELECT id, username, name, role, email, password_hash, status FROM users WHERE username = ?");
    $s->execute([$username]);
    $u = $s->fetch();
    if (!$u || !password_verify($password, (string)$u['password_hash'])) {
        logOperation($pdo, 0, $username, 'login_failed', 'user', 0, '');
        jsonResponse(['success' => false, 'errorMessage' => 'Invalid username or password'], 401);
    }
    if (($u['status'] ?? 'active') !== 'active') {
        jsonResponse(['success' => false, 'errorMessage' => 'Account disabled'], 403);
    }
    logOperation($pdo, (int)$u['id'], $u['username'], 'login', 'user', (int)$u['id'], '');
    jsonResponse([
        'success' => true,
        'token' => generateToken((int)$u['id']),
        'user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'email' => $u['email']],
    ]);
}

function handleCurrentUser($pdo) {
    $userId = (int)verifyToken();
    $s = $pdo->prepare("SELECT id, username, name, role, email, lang FROM users WHERE id = ?");
    $s->execute([$userId]);
    $user = $s->fetch();
    if (!$user) jsonResponse(['success' => false, 'errorMessage' => 'User not found'], 404);
    $user['id'] = (int)$user['id'];
    $user['modules'] = userModules($pdo, $userId);
    // 企业库查看名单（recruit.company.viewer_ids）：前端 access.canViewRecruitCompanies 看这个
    require_once __DIR__ . '/recruit_company_acl.php';
    if (recruitCompanyCanView($pdo, $userId)) $user['modules'][] = 'recruit_company_view';
    jsonResponse(['success' => true, 'data' => $user]);
}

function handleUpdateMyLang($pdo, $input) {
    $userId = (int)verifyToken();
    $lang = (string)($input['lang'] ?? '');
    if (!in_array($lang, ['zh-CN', 'en-US', 'id-ID'], true)) jsonResponse(['success' => false, 'errorMessage' => 'bad lang'], 400);
    $pdo->prepare("UPDATE users SET lang = ? WHERE id = ?")->execute([$lang, $userId]);
    jsonResponse(['success' => true]);
}
