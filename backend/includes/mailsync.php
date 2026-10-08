<?php
/**
 * 纯 PHP socket 实现的 IMAP 客户端 + MIME 解析（招聘邮箱同步、本地导入共用）。
 *
 * 不依赖 ext-imap，使用 stream_socket_client 直连 TLS 端口，手写协议 + MIME 解析。
 */

require_once __DIR__ . '/db_dialect.php';
require_once __DIR__ . '/oss.php';

class ImapClient {
    private $sock;
    private $tag = 0;
    public $lastError = '';

    public function connect(string $host, int $port, bool $ssl, int $timeout = 20): bool {
        $errno = 0; $errstr = '';
        $uri = ($ssl ? 'tls://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);
        $this->sock = @stream_socket_client($uri, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) {
            $this->lastError = "connect fail: $errstr ($errno)";
            return false;
        }
        stream_set_timeout($this->sock, $timeout);
        // 读欢迎行：* OK Gimap ready for requests
        $greet = fgets($this->sock, 4096);
        if (!$greet || strpos($greet, '* OK') !== 0) {
            $this->lastError = 'bad greeting: ' . trim((string)$greet);
            return false;
        }
        return true;
    }

    public function close(): void {
        if ($this->sock) {
            @fwrite($this->sock, 'a999 LOGOUT' . "\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    private function nextTag(): string {
        return 'a' . str_pad((string)(++$this->tag), 4, '0', STR_PAD_LEFT);
    }

    // 发送带 tag 的命令并读完整响应；返回 ['ok', 'tag', 'lines' => [...], 'literals' => [...]]
    // 每条响应行原始保留；遇到 {N} 字面量时读 N 字节原始数据作为 literal，并继续下一行
    public function cmd(string $command): array {
        $tag = $this->nextTag();
        $line = $tag . ' ' . $command . "\r\n";
        fwrite($this->sock, $line);
        $lines = [];
        $literals = [];
        while (true) {
            $l = fgets($this->sock, 32768);
            if ($l === false) {
                $this->lastError = 'read timeout';
                return ['ok' => false, 'tag' => $tag, 'lines' => $lines, 'literals' => $literals];
            }
            $l = rtrim($l, "\r\n");
            // 检测 {N}
            if (preg_match('/\{(\d+)\}$/', $l, $m)) {
                $size = (int)$m[1];
                $buf = '';
                while (strlen($buf) < $size) {
                    $chunk = fread($this->sock, $size - strlen($buf));
                    if ($chunk === false || $chunk === '') break;
                    $buf .= $chunk;
                }
                $literals[] = $buf;
                // 读掉本行后续：通常再读一行（右括号或续行）
                $lines[] = $l; // 先存行头
                continue;
            }
            $lines[] = $l;
            // 终止条件：以 tag 开头的行
            if (strpos($l, $tag . ' ') === 0) {
                $ok = (strpos($l, $tag . ' OK') === 0);
                if (!$ok) $this->lastError = $l;
                return ['ok' => $ok, 'tag' => $tag, 'lines' => $lines, 'literals' => $literals];
            }
        }
    }

    public function login(string $user, string $pass): bool {
        $u = addslashes($user);
        $p = addslashes($pass);
        $r = $this->cmd("LOGIN \"$u\" \"$p\"");
        return $r['ok'];
    }

    public function select(string $folder): bool {
        $r = $this->cmd('SELECT "' . addslashes($folder) . '"');
        return $r['ok'];
    }

    /** 返回匹配的 UID 数组 */
    public function uidSearch(string $criteria): array {
        $r = $this->cmd('UID SEARCH ' . $criteria);
        if (!$r['ok']) return [];
        $uids = [];
        foreach ($r['lines'] as $l) {
            if (preg_match('/^\* SEARCH (.*)$/', $l, $m)) {
                $parts = preg_split('/\s+/', trim($m[1]));
                foreach ($parts as $p) if ($p !== '' && ctype_digit($p)) $uids[] = (int)$p;
            }
        }
        return $uids;
    }

    /** 抓完整原文 RFC822 */
    public function uidFetchRaw(int $uid): ?string {
        $r = $this->cmd("UID FETCH $uid BODY.PEEK[]");
        if (!$r['ok']) return null;
        if (empty($r['literals'])) return null;
        return $r['literals'][0];
    }
}

// =========================================================
// MIME 解析 — 抽 header + PDF 附件
// =========================================================
function parseMimeHeaders(string $raw): array {
    $headers = [];
    $lines = preg_split('/\r?\n/', $raw);
    $cur = null;
    foreach ($lines as $l) {
        if ($l === '') break;
        if (preg_match('/^\s+/', $l) && $cur !== null) {
            // continuation
            $headers[$cur][count($headers[$cur]) - 1] .= ' ' . trim($l);
        } else if (preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', $l, $m)) {
            $cur = strtolower($m[1]);
            if (!isset($headers[$cur])) $headers[$cur] = [];
            $headers[$cur][] = $m[2];
        }
    }
    $out = [];
    foreach ($headers as $k => $vs) $out[$k] = $vs[0] ?? '';
    return $out;
}

function splitHeadersAndBody(string $raw): array {
    // 查找 \r\n\r\n 或 \n\n
    $idx = strpos($raw, "\r\n\r\n");
    $gap = 4;
    if ($idx === false) {
        $idx = strpos($raw, "\n\n");
        $gap = 2;
    }
    if ($idx === false) return [$raw, ''];
    return [substr($raw, 0, $idx), substr($raw, $idx + $gap)];
}

function parseContentType(string $v): array {
    // e.g. "multipart/mixed; boundary=\"===boundary===\""
    $parts = preg_split('/;\s*/', $v);
    $mime = strtolower(trim(array_shift($parts) ?: ''));
    $params = [];
    foreach ($parts as $p) {
        if (preg_match('/([A-Za-z0-9_-]+)\s*=\s*"?([^";]+)"?/', $p, $m)) {
            $params[strtolower($m[1])] = $m[2];
        }
    }
    return [$mime, $params];
}

function decodeMimeHeader(string $s): string {
    // 支持 =?UTF-8?B?..?= 和 =?UTF-8?Q?..?= 简单解码
    if (strpos($s, '=?') === false) return $s;
    $out = preg_replace_callback('/=\?([A-Za-z0-9\-_]+)\?([BbQq])\?([^?]+)\?=/', function ($m) {
        $cs = $m[1]; $enc = strtoupper($m[2]); $txt = $m[3];
        if ($enc === 'B') {
            return base64_decode($txt);
        } else {
            $txt = str_replace('_', ' ', $txt);
            return quoted_printable_decode($txt);
        }
    }, $s);
    return (string)$out;
}

function decodeBody(string $body, string $encoding): string {
    $enc = strtolower(trim($encoding));
    if ($enc === 'base64') return base64_decode(preg_replace('/\s+/', '', $body));
    if ($enc === 'quoted-printable') return quoted_printable_decode($body);
    return $body;
}

/**
 * 遍历 MIME 提取 text/plain 与 text/html 正文（首个非附件叶子）
 * 返回 ['text' => string, 'html' => string]
 */
function extractTextBodies(string $raw): array {
    [$headerBlock, $body] = splitHeadersAndBody($raw);
    $H = parseMimeHeaders($headerBlock);
    [$mime, $params] = parseContentType($H['content-type'] ?? 'text/plain');
    $text = ''; $html = '';
    $walk = function ($mime, $params, $body, $headers) use (&$walk, &$text, &$html) {
        if (strpos($mime, 'multipart/') === 0) {
            $boundary = $params['boundary'] ?? '';
            if (!$boundary) return;
            $parts = preg_split('/\r?\n--' . preg_quote($boundary, '/') . '(?:--)?\r?\n/', "\r\n" . $body);
            foreach ($parts as $p) {
                if (trim($p) === '') continue;
                [$ph, $pb] = splitHeadersAndBody($p);
                $subH = parseMimeHeaders($ph);
                [$sm, $sp] = parseContentType($subH['content-type'] ?? 'text/plain');
                $walk($sm, $sp, $pb, $subH);
            }
            return;
        }
        // 叶子：附件跳过
        if (!empty($headers['content-disposition'])) {
            [$disp, $dparams] = parseContentType($headers['content-disposition']);
            if (stripos($disp, 'attachment') !== false || !empty($dparams['filename']) || !empty($dparams['filename*'])) return;
        }
        $encoding = $headers['content-transfer-encoding'] ?? '7bit';
        $data = decodeBody($body, $encoding);
        $charset = $params['charset'] ?? '';
        if ($charset && stripos($charset, 'utf-8') === false && stripos($charset, 'utf8') === false) {
            $conv = @iconv($charset, 'UTF-8//IGNORE', $data);
            if ($conv !== false) $data = $conv;
        }
        if (stripos($mime, 'text/html') === 0) {
            if ($html === '') $html = $data;
        } elseif (stripos($mime, 'text/plain') === 0) {
            if ($text === '') $text = $data;
        }
    };
    $walk($mime, $params, $body, $H);
    return ['text' => $text, 'html' => $html];
}

/**
 * 遍历 MIME：返回所有 PDF 附件 [{filename, data}]
 * 并在外层解析 header 便于调用者取 subject/from/date
 */
/* $namePattern：按文件名筛附件，默认只要 PDF（签证/ITK 邮件的既有行为，调用方不传参数即不变）。
   招聘邮件传 '/\.(pdf|docx)$/i'——简历常见 Word。见 includes/recruit_mailsync.php */
function extractPdfAttachments(string $raw, string $namePattern = '/\.pdf$/i'): array {
    [$headerBlock, $body] = splitHeadersAndBody($raw);
    $H = parseMimeHeaders($headerBlock);
    [$mime, $params] = parseContentType($H['content-type'] ?? 'text/plain');

    $collected = [];

    $walk = function ($mime, $params, $body) use (&$walk, &$collected, $H) {
        if (strpos($mime, 'multipart/') === 0) {
            $boundary = $params['boundary'] ?? '';
            if (!$boundary) return;
            // 用 --boundary 拆
            $parts = preg_split('/\r?\n--' . preg_quote($boundary, '/') . '(?:--)?\r?\n/', "\r\n" . $body);
            // 跳过首尾空/epilogue
            foreach ($parts as $p) {
                if (trim($p) === '') continue;
                [$ph, $pb] = splitHeadersAndBody($p);
                $subH = parseMimeHeaders($ph);
                [$sm, $sp] = parseContentType($subH['content-type'] ?? 'text/plain');
                $walk($sm, $sp, $pb);
            }
            return;
        }
        // 叶子节点
        // 看 Content-Disposition 是否有 filename
        $disp = '';
        $filename = '';
        if (!empty($GLOBALS['__mime_local_H']['content-disposition'])) {
            // not used; we only have $subH in inner scope
        }
    };

    // 更直接的实现（递归 walkNode）
    $walkNode = function ($mime, $params, $body, $headers) use (&$walkNode, &$collected, $namePattern) {
        if (strpos($mime, 'multipart/') === 0) {
            $boundary = $params['boundary'] ?? '';
            if (!$boundary) return;
            $parts = preg_split('/\r?\n--' . preg_quote($boundary, '/') . '(?:--)?\r?\n/', "\r\n" . $body);
            foreach ($parts as $p) {
                if (trim($p) === '') continue;
                [$ph, $pb] = splitHeadersAndBody($p);
                $subH = parseMimeHeaders($ph);
                [$sm, $sp] = parseContentType($subH['content-type'] ?? 'text/plain');
                $walkNode($sm, $sp, $pb, $subH);
            }
            return;
        }
        // 叶子
        $filename = '';
        if (!empty($headers['content-disposition'])) {
            [, $dparams] = parseContentType($headers['content-disposition']);
            if (!empty($dparams['filename'])) $filename = $dparams['filename'];
            else if (!empty($dparams['filename*'])) $filename = $dparams['filename*'];
        }
        if (!$filename && !empty($params['name'])) $filename = $params['name'];
        if (!$filename) return;
        $filename = decodeMimeHeader($filename);
        if (!preg_match($namePattern, $filename)) return;

        $encoding = $headers['content-transfer-encoding'] ?? '7bit';
        $data = decodeBody($body, $encoding);
        $collected[] = ['filename' => basename($filename), 'data' => $data];
    };

    $walkNode($mime, $params, $body, $H);
    return $collected;
}

// =========================================================
// 对外：测试连接 + 同步
// =========================================================

/** PDF 文本层抽取（poppler pdftotext，前 3 页）。不是文本型 PDF / 没装 pdftotext 时返回空串 */
function itkExtractPdfText(string $pdfBin): string {
    if (strncmp(substr($pdfBin, 0, 4), '%PDF', 4) !== 0) return '';
    if (!function_exists('exec')) return '';
    $candidates = ['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext', '/bin/pdftotext'];
    $bin = null;
    foreach ($candidates as $p) { if (is_executable($p)) { $bin = $p; break; } }
    if ($bin === null) {
        @exec('which pdftotext 2>/dev/null', $w);
        if (!empty($w) && is_executable(trim($w[0]))) $bin = trim($w[0]);
    }
    if ($bin === null) { error_log('itkExtractPdfText: pdftotext not found'); return ''; }

    $tmp = tempnam(sys_get_temp_dir(), 'itktxt_') . '.pdf';
    if (file_put_contents($tmp, $pdfBin) === false) { @unlink($tmp); return ''; }
    $outTxt = $tmp . '.txt';
    $cmd = escapeshellarg($bin) . ' -layout -enc UTF-8 -f 1 -l 3 '
        . escapeshellarg($tmp) . ' ' . escapeshellarg($outTxt) . ' 2>&1';
    @exec($cmd, $o, $code);
    @unlink($tmp);
    $txt = @file_get_contents($outTxt);
    @unlink($outTxt);
    if ($code !== 0 || !is_string($txt)) {
        error_log('itkExtractPdfText failed: code=' . $code . ' out=' . implode(' ', (array)$o));
        return '';
    }
    return $txt;
}

