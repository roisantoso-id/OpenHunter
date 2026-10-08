<?php
/**
 * 阿里云 OSS 统一上传/下载封装
 *
 * 存储模式 C：所有新上传的附件都走 OSS；本地仅作为 PHP $_FILES 的临时 tmp。
 * 历史文件保留在 data/uploads/ 等本地目录，serveFile 按 path 前缀兼容读取。
 *
 * DB 存的 file_path 约定：
 *   - 历史：'data/uploads/...'  → 本地文件
 *   - 新增：'oss://{bucket}/{key}' → OSS 对象
 *
 * 配置来源：system_settings 表 key='oss.*'
 *   oss.enabled / oss.endpoint / oss.region / oss.bucket
 *   oss.access_key_id / oss.access_key_secret (加密) / oss.path_prefix
 */

// OSS SDK 可选：没装（composer require aliyuncs/oss-sdk-php）时只用本地磁盘，ossIsEnabled() 恒为 false
if (is_file(__DIR__ . '/../vendor/autoload.php')) require_once __DIR__ . '/../vendor/autoload.php';

use OSS\OssClient;
use OSS\Core\OssException;

// --- 加密密钥（用于保护 AccessKeySecret）-----------------
// 存放在 config/encryption_key.php（gitignored）。不存在则自动生成一个。
function getEncryptionKey(): string {
    $path = __DIR__ . '/../config/encryption_key.php';
    if (file_exists($path)) {
        $k = (string)(require $path);
        if (strlen($k) >= 32) return $k;
    }
    $new = bin2hex(random_bytes(16)); // 32 字符十六进制
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $content = "<?php\n// 自动生成的对称加密密钥，切勿提交 git；丢失会导致已存的 OSS 密钥无法解密\nreturn '" . $new . "';\n";
    file_put_contents($path, $content, LOCK_EX);
    @chmod($path, 0600);
    return $new;
}

function encryptSecret(string $plain): string {
    if ($plain === '') return '';
    $key = getEncryptionKey();
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    return 'enc:' . base64_encode($iv . $cipher);
}

function decryptSecret(string $stored): string {
    if ($stored === '') return '';
    if (strpos($stored, 'enc:') !== 0) return $stored; // 未加密（兼容）
    $raw = base64_decode(substr($stored, 4));
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $key = getEncryptionKey();
    $out = openssl_decrypt($cipher, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    return $out === false ? '' : $out;
}

// --- 从 system_settings 读取 OSS 配置 -------------------
function ossGetConfig($pdo): ?array {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'oss.%'");
        $rows = $stmt->fetchAll();
        $map = [];
        foreach ($rows as $r) $map[$r['setting_key']] = (string)$r['setting_value'];
        if (empty($map['oss.enabled']) || $map['oss.enabled'] !== '1') {
            $cached = null;
            return null;
        }
        $bucket = $map['oss.bucket'] ?? '';
        $endpoint = $map['oss.endpoint'] ?? '';
        $keyId = $map['oss.access_key_id'] ?? '';
        $keySecret = decryptSecret($map['oss.access_key_secret'] ?? '');
        if (!$bucket || !$endpoint || !$keyId || !$keySecret) {
            $cached = null;
            return null;
        }
        $cached = [
            'enabled' => true,
            'endpoint' => $endpoint,
            'public_endpoint' => $map['oss.public_endpoint'] ?? '',
            'region' => $map['oss.region'] ?? '',
            'bucket' => $bucket,
            'access_key_id' => $keyId,
            'access_key_secret' => $keySecret,
            'path_prefix' => trim($map['oss.path_prefix'] ?? '', '/'),
        ];
        return $cached;
    } catch (Exception $e) {
        error_log('ossGetConfig failed: ' . $e->getMessage());
        return null;
    }
}

function ossResetCache(): void {
    // 配置变更后调用，强制下次重新读取
    static $reset;
    ossGetConfig_cache_reset();
}
// PHP 不能直接重置 static，包一层：使用 apcu 或全局变量更可控；简单起见重启 PHP 进程
function ossGetConfig_cache_reset() { /* no-op: 依赖请求粒度 */ }

function ossIsEnabled($pdo): bool {
    return class_exists(OssClient::class) && ossGetConfig($pdo) !== null;
}

// --- 客户端 -------------------------------------------
function ossClient($pdo): ?OssClient {
    $c = ossGetConfig($pdo);
    if (!$c) return null;
    try {
        return new OssClient($c['access_key_id'], $c['access_key_secret'], $c['endpoint']);
    } catch (OssException $e) {
        error_log('ossClient init failed: ' . $e->getMessage());
        return null;
    }
}

// 把内网 endpoint 映射为公网 endpoint（用于给浏览器签名的 URL）
// 规则：去掉 "-internal" 子串；如果有 mail.oss_public_endpoint 覆盖优先用
function ossPublicEndpoint(array $c): string {
    // 优先从 config 里读 explicit override
    $override = trim((string)($c['public_endpoint'] ?? ''));
    if ($override !== '') return $override;
    // 自动推导：把 -internal 去掉
    $ep = $c['endpoint'];
    return str_replace('-internal.aliyuncs.com', '.aliyuncs.com', $ep);
}

// 专门给浏览器签名用的 client（使用公网 endpoint）
function ossPublicClient($pdo): ?OssClient {
    $c = ossGetConfig($pdo);
    if (!$c) return null;
    try {
        return new OssClient($c['access_key_id'], $c['access_key_secret'], ossPublicEndpoint($c));
    } catch (OssException $e) {
        error_log('ossPublicClient init failed: ' . $e->getMessage());
        return null;
    }
}

// --- Key 路径规则 -------------------------------------
function ossBuildKey($pdo, string $category, string $scope, string $filename): string {
    $c = ossGetConfig($pdo);
    $prefix = $c && !empty($c['path_prefix']) ? $c['path_prefix'] . '/' : '';
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    $ts = time() . '_' . bin2hex(random_bytes(3));
    return $prefix . $category . '/' . $scope . '/' . $ts . '_' . $safe;
}

// --- 上传：给定本地路径和 key，上传后返回 'oss://bucket/key' --
function ossUploadFile($pdo, string $localPath, string $ossKey): string {
    $c = ossGetConfig($pdo);
    if (!$c) throw new Exception('OSS 未启用或配置不完整');
    $client = ossClient($pdo);
    if (!$client) throw new Exception('OSS 客户端初始化失败');
    $client->uploadFile($c['bucket'], $ossKey, $localPath);
    return 'oss://' . $c['bucket'] . '/' . $ossKey;
}

// --- 上传：内存里的内容直接 putObject，不经过本地文件 -----------
// 招聘邮件附件用（2026-09-24「直接传 OSS，不要下载到本地，本地磁盘很小」）。
function ossUploadContent($pdo, string $content, string $ossKey): string {
    $c = ossGetConfig($pdo);
    if (!$c) throw new Exception('OSS 未启用或配置不完整');
    $client = ossClient($pdo);
    if (!$client) throw new Exception('OSS 客户端初始化失败');
    $client->putObject($c['bucket'], $ossKey, $content);
    return 'oss://' . $c['bucket'] . '/' . $ossKey;
}

// --- 判断路径是否 OSS -----------------------------------
function isOssPath(string $path): bool {
    return strpos($path, 'oss://') === 0;
}

// 从 'oss://bucket/key' 解析出 [bucket, key]
function parseOssPath(string $path): array {
    if (!isOssPath($path)) return ['', ''];
    $p = substr($path, 6); // strip 'oss://'
    $slash = strpos($p, '/');
    if ($slash === false) return ['', ''];
    return [substr($p, 0, $slash), substr($p, $slash + 1)];
}

// --- 从 OSS 流式下载并输出给客户端 -----------------------
// 因走 serveFile（JWT 鉴权）代理，bucket 可保持私有读
function ossStreamToClient($pdo, string $ossPath, ?string $downloadFilename = null, bool $forceDownload = false): void {
    [$bucket, $key] = parseOssPath($ossPath);
    if (!$bucket || !$key) {
        http_response_code(404);
        echo 'Invalid OSS path';
        return;
    }
    $client = ossPublicClient($pdo);
    if (!$client) {
        http_response_code(500);
        echo 'OSS client unavailable';
        return;
    }
    /* ⛔ 只允许读**当前配置的** bucket。
     * bucket 此前完全取自调用方传进来的路径（前端把库里存的 oss:// 原样拼进
     * ?action=serveFile&path=...），等于「客户端说读哪个就读哪个」。
     *
     * 2026-09-08 切 RDS + 换 OSS 账号后，老页面残留的 oss://bucket/... 打进来，
     * 报 AccessDenied——那次是无害的（跨账号已不可读），但暴露了这个信任面：
     * 任意 bucket 名都会被拿去用**本站的凭证**发一次请求。
     * 同账号下的其它 bucket 就读得到，越权范围取决于 AK 的权限。
     *
     * 收紧后：bucket 不匹配一律 404，不发那次请求。
     * ⚠️ 只管这条「给浏览器看文件」的读路径。迁移/删除类代码有正当理由
     * 跨 bucket 操作，不在这里限制。 */
    $cfg = ossGetConfig($pdo);
    if (!$cfg || $bucket !== ($cfg['bucket'] ?? '')) {
        error_log('ossStreamToClient blocked foreign bucket: ' . $bucket . ' (allowed: ' . ($cfg['bucket'] ?? '-') . ')');
        http_response_code(404);
        echo 'File not found';
        return;
    }
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    $mimeMap = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'heic' => 'image/heic', 'pdf' => 'application/pdf', 'svg' => 'image/svg+xml',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv' => 'text/csv', 'txt' => 'text/plain', 'zip' => 'application/zip',
    ];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    $baseName = $downloadFilename ?: basename($key);
    $safeName = rawurlencode($baseName);
    // Content-Disposition 的 filename="..." 必须是 ASCII——直接塞 UTF-8 中文字节不合规范，
    // 各浏览器解析行为不一致，会截断成乱码。ASCII fallback 用扩展名占位，
    // 真正的中文文件名走 filename*=UTF-8''...（RFC 5987），现代浏览器都认这个
    $asciiName = preg_match('/[^\x20-\x7e]/', $baseName) ? ('file.' . $ext) : $baseName;
    $inlineExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'heic', 'pdf', 'svg'];
    $dispoType = ($forceDownload || !in_array($ext, $inlineExts, true)) ? 'attachment' : 'inline';

    try {
        // 改用服务端代理：PHP 从 OSS 拉对象再转给浏览器，彻底避免 302 + 跨域签名问题
        // 小文件 CRM 场景流量可接受；真正大对象可以将来再做分片 Range 透传
        $obj = $client->getObject($bucket, $key);
        $size = strlen($obj);
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Content-Disposition: ' . $dispoType . '; filename="' . addslashes($asciiName) . '"; filename*=UTF-8\'\'' . $safeName);
        header('Cache-Control: private, max-age=300');
        header('X-Accel-Buffering: no');
        echo $obj;
    } catch (OssException $e) {
        /* 对象不存在 = 404，不是 500。
         * 500 的语义是「服务端出故障了，可以重试」，前端与浏览器据此重试、用户也反复点击：
         * 2026-09-08 mailbox 11 个附件（3611~3621）在 OSS 里确实不存在，
         * 却按 500 返回，同一批文件从 17:36 一路重试到 18:15 刷满 error.log。
         * NoSuchKey 是**终态**，重试永远不会成功，必须让调用方一次就放弃。
         *
         * ⚠️ getErrorCode() 只在 OssException 收到数组 details 时才有值
         * （见 OssException::__construct 的字符串分支），所以补一层 message 兜底判断。 */
        $code = $e->getErrorCode();
        $isMissing = ($code === 'NoSuchKey' || $code === 'SymlinkTargetNotExist')
                  || ($code === '' && stripos($e->getMessage(), 'NoSuchKey') !== false);
        error_log('ossStreamToClient OSS fetch failed for ' . $ossPath . ': ' . $e->getMessage());
        http_response_code($isMissing ? 404 : 500);
        header('Content-Type: text/plain; charset=utf-8');
        /* ⛔ 不把 $e->getMessage() 回给客户端：里面带 bucket 名、object key 和阿里云
         * RequestId，属于内部信息。诊断信息只进 error_log（上面那行已记全）。 */
        echo $isMissing ? 'File not found' : 'File temporarily unavailable';
    } catch (Exception $e) {
        error_log('ossStreamToClient error: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File temporarily unavailable';
    }
}

// --- 通用上传 helper ---------------------------------
// 所有业务 handler 调用此函数，自动处理 OSS / 本地兼容。
// category: 业务分组 e.g. 'recruit/resumes'
// scope: 子分组 e.g. 项目 id（可为空字符串）
// 返回 ['path' => '存回 DB 的路径', 'name' => '原始文件名']
// 失败抛异常
function uploadAttachment($pdo, string $localTmp, string $origName, string $category, string $scope = ''): array {
    if (!is_file($localTmp)) {
        throw new Exception('临时文件不存在');
    }
    $safeOrig = basename($origName);
    if (ossIsEnabled($pdo)) {
        $ossKey = ossBuildKey($pdo, $category, $scope ?: 'misc', $safeOrig);
        $fullPath = ossUploadFile($pdo, $localTmp, $ossKey);
        return ['path' => $fullPath, 'name' => $safeOrig];
    }
    // 本地 fallback：写到 data/uploads/{category}/{scope}/
    $subdir = trim($category . '/' . $scope, '/');
    $dir = __DIR__ . '/../data/uploads/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $safeOrig);
    $unique = time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeName;
    $target = $dir . '/' . $unique;
    if (!@rename($localTmp, $target) && !@copy($localTmp, $target)) {
        throw new Exception('保存文件到本地失败');
    }
    $rel = 'data/uploads/' . $subdir . '/' . $unique;
    return ['path' => $rel, 'name' => $safeOrig];
}

// --- 测试连接（供系统设置「测试」按钮用）---------------
function ossTest($pdo): array {
    $c = ossGetConfig($pdo);
    if (!$c) return ['ok' => false, 'msg' => 'OSS 未启用或配置不完整'];
    try {
        $client = new OssClient($c['access_key_id'], $c['access_key_secret'], $c['endpoint']);
        $exists = $client->doesBucketExist($c['bucket']);
        if (!$exists) return ['ok' => false, 'msg' => "Bucket {$c['bucket']} 不存在或无权访问"];
        // 尝试上传一个 ping 文件
        $pingKey = ($c['path_prefix'] ? $c['path_prefix'] . '/' : '') . '_ping/' . time() . '.txt';
        $client->putObject($c['bucket'], $pingKey, 'ping ' . date('c'));
        $client->deleteObject($c['bucket'], $pingKey);
        return ['ok' => true, 'msg' => '连接成功，可读写 Bucket ' . $c['bucket']];
    } catch (OssException $e) {
        return ['ok' => false, 'msg' => 'OSS 连接失败：' . $e->getMessage()];
    }
}
