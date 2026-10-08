<?php
/**
 * 后台 worker 拉起（任务单 W）。全站三处拉起点共用这一份，别再各写一份。
 *
 * ⛔ 2026-08-13 生产事故：原代码 `$phpBin = PHP_BINARY ?: 'php'`。
 *    **PHP-FPM 下 PHP_BINARY 是 php-fpm 的二进制，不是 CLI php**：
 *      CLI = /usr/bin/php → /www/server/php/82/bin/php
 *      FPM = /www/server/php/82/sbin/php-fpm      ← 两个不同的可执行文件
 *    拿 php-fpm 去执行脚本什么也不会发生，而输出又全进 `/dev/null 2>&1`，
 *    **失败完全静默**且调用方还把 spawned 置成 true —— job 永远停在 pending，
 *    用户看到的是「识别特别慢」（实为永远转圈）。生产 job id=1 pending 了 1.5 小时。
 *
 *    为什么本地从没暴露：本地跑 `php -S` 内置服务器，PHP_BINARY 恰好就是 CLI php。
 *    **本地绿不算数，这个 bug 只在 FPM 下出现。**
 *
 * 三条对策（与 CLAUDE.md 7.9/7.10「看起来有防护、实际防护从不生效」同类）：
 *   1) 解析出真正的 CLI php 并**当场验证** PHP_SAPI === 'cli'，不信任 PHP_BINARY；
 *   2) 拉起后**等待 worker 认领**，没认领就把任务标 error —— 不许静默；
 *   3) 输出写日志文件而不是 /dev/null —— 今天这个 bug 查了两轮就是因为它被吞了。
 */

/** 运维逃生口：system_settings 里显式指定 CLI php 路径 */
const WORKER_PHP_BIN_SETTING = 'ai_intake.php_bin';

/**
 * 纯函数：从候选里挑第一个通过校验的。**可单测**（校验器注入，不碰文件系统）。
 * $candidates: [来源标签 => 路径]；$isCli: fn(string $path): bool
 * 返回 ['bin'=>路径或空, 'source'=>命中来源, 'tried'=>失败候选说明[]]
 */
function workerPickPhpBinary(array $candidates, callable $isCli): array {
    $tried = [];
    foreach ($candidates as $source => $path) {
        $path = trim((string)$path);
        if ($path === '') continue;
        if (!$isCli($path)) { $tried[] = "$source=$path(非 CLI 或不可执行)"; continue; }
        return ['bin' => $path, 'source' => (string)$source, 'tried' => $tried];
    }
    return ['bin' => '', 'source' => '', 'tried' => $tried];
}

/**
 * 校验一个路径确实是 CLI php：能执行且 PHP_SAPI === 'cli'。
 * 这一步是本单的核心——只看文件存在会把 php-fpm 也放过去（事故原样）。
 */
function workerIsCliPhp(string $path): bool {
    if ($path === '' || !@is_file($path) || !@is_executable($path)) return false;
    $out = [];
    $code = 1;
    @exec(escapeshellarg($path) . ' -r ' . escapeshellarg('echo PHP_SAPI;') . ' 2>/dev/null', $out, $code);
    return $code === 0 && trim(implode('', $out)) === 'cli';
}

/** 候选列表（顺序即优先级）。$override 来自 system_settings，最高优先。 */
function workerPhpCandidates(string $override = ''): array {
    $c = ['setting' => $override, 'PHP_BINDIR' => (defined('PHP_BINDIR') && PHP_BINDIR !== '') ? PHP_BINDIR . '/php' : ''];
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/opt/homebrew/bin/php'] as $p) $c[$p] = $p;
    $c['command -v php'] = trim((string)@shell_exec('command -v php 2>/dev/null'));
    return $c;
}

/** 解析 CLI php（单请求内缓存：每个候选都要 exec 一次验证，别重复付这个钱） */
function workerResolvePhpBinary(?PDO $pdo = null): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $override = '';
    if ($pdo instanceof PDO) {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
            $st->execute([WORKER_PHP_BIN_SETTING]);
            $override = trim((string)$st->fetchColumn());
        } catch (Throwable $e) {
            // 表缺失/连接异常 → 走自动探测，不抛
        }
    }
    return $cached = workerPickPhpBinary(workerPhpCandidates($override), 'workerIsCliPhp');
}

/** worker 日志文件（data/ 已 gitignore）。失败原因要留得下来，不能进 /dev/null */
function workerLogPath(string $tag): string {
    $dir = dirname(__DIR__) . '/data/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/worker_' . preg_replace('/[^a-z0-9_\-]/i', '', $tag) . '.log';
}

/**
 * 日志是否可写。**不可写时必须降级为不写日志、而不是让拉起失败** ——
 * 日志是辅助设施，让它成为主流程的单点故障是设计错误。
 *
 * 2026-08-13 生产实证：有人用 root 跑了一次 CLI，`data/logs/` 与日志文件就变成 root 属主；
 * 之后 FPM（www）再拉起时 `>> 日志` 失败 → 整条命令失败 → worker 起不来 → 任务标 error。
 * 一次以错误身份执行的验证，把功能给弄坏了。
 */
function workerLogWritable(string $path): bool {
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) return false;
    return !file_exists($path) || is_writable($path);
}

/** exec 是否可用（函数存在且没被 disable_functions 禁） */
function workerExecAvailable(): bool {
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

/**
 * 往 worker 日志写一行。日志文件不可写时**改走 PHP 错误通道**，不静默丢弃。
 * 与「报告『日志坏了』的消息不能走那条坏了的日志」同一原则：主通道坏了要有第二条。
 */
function workerLogWrite(string $tag, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    $path = workerLogPath($tag);
    if (workerLogWritable($path) && @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false) return;
    error_log('[worker:' . $tag . '] ' . $msg);
}

/**
 * 甩一个后台 worker。返回 ['ok'=>bool,'error'=>说明,'bin'=>用的解释器,'log'=>日志路径]。
 * **ok=true 只代表命令发出去了**，不代表 worker 真的跑起来了——是否跑起来必须由
 * workerAwaitClaim 判定（事故教训：原代码把「发出去了」当成「成功了」）。
 */
function workerSpawn(?PDO $pdo, string $scriptPath, array $args, string $tag): array {
    $log = workerLogPath($tag);
    if (!workerExecAvailable()) {
        return ['ok' => false, 'error' => 'exec 被禁用，无法启动后台任务', 'bin' => '', 'log' => $log];
    }
    if (!is_file($scriptPath)) {
        return ['ok' => false, 'error' => 'worker 脚本不存在: ' . basename($scriptPath), 'bin' => '', 'log' => $log];
    }
    $r = workerResolvePhpBinary($pdo);
    if ($r['bin'] === '') {
        return ['ok' => false, 'bin' => '', 'log' => $log,
                'error' => '找不到可用的 CLI php（已试: ' . implode('; ', $r['tried'])
                         . '）。可在系统设置 ' . WORKER_PHP_BIN_SETTING . ' 显式指定路径'];
    }
    $cmd = 'OPENHUNTER_TENANT=' . escapeshellarg(Tenant::slug()) . ' ' . escapeshellarg($r['bin']) . ' ' . escapeshellarg($scriptPath);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    $setsid = trim((string)@shell_exec('command -v setsid 2>/dev/null'));
    // 输出**追加进日志**而不是丢弃：静默失败是这次事故最贵的部分。
    // 但日志写不进去时**必须降级为 /dev/null 继续拉起** —— 否则辅助设施变成主流程的单点故障。
    $logOk = workerLogWritable($log);
    $redirect = $logOk ? ' >> ' . escapeshellarg($log) . ' 2>&1' : ' > /dev/null 2>&1';
    if (!$logOk) {
        // 降级本身不许静默。走 PHP 自己的错误通道（FPM error log），
        // **刻意不写 data/logs** —— 那正是当前写不进去的地方，写那儿等于没报
        error_log('[worker_spawn] 日志不可写，已降级为不记录: ' . $log
            . '（检查属主：应为运行 PHP-FPM 的用户，通常是 www；被 root 跑过一次就会变成 root）');
    }
    @exec(($setsid !== '' ? 'setsid ' : 'nohup ') . $cmd . $redirect . ' &');
    return ['ok' => true, 'error' => '', 'bin' => $r['bin'], 'log' => $log, 'log_writable' => $logOk];
}

/**
 * 拉起失败时给调用方的诊断提示。日志不可写时**不能再让人去看那个日志** ——
 * 指向一个根本没被写入的文件，比不给提示更浪费排查时间。
 */
function workerFailureHint(array $sp): string {
    if (!empty($sp['log_writable'])) return '，请查看 ' . basename((string)$sp['log']);
    return '（⚠️ 日志不可写已降级，无内容可查：请检查 data/logs 及其中文件的属主，'
         . '应为运行 PHP-FPM 的用户）';
}

/**
 * 等待 worker 认领任务。$claimed 回调返回 true 即认领成功。
 * 成功时立刻返回（通常 200-400ms），失败时最多等 $timeoutMs 再判定。
 * 远小于 FPM request_terminate_timeout（生产 100s），符合 CLAUDE.md 7.10。
 */
function workerAwaitClaim(callable $claimed, int $timeoutMs = 3000, int $stepMs = 150): bool {
    $deadline = microtime(true) + $timeoutMs / 1000;
    do {
        if ($claimed()) return true;
        usleep($stepMs * 1000);
    } while (microtime(true) < $deadline);
    return $claimed();
}
