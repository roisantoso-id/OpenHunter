<?php
/**
 * OpenHunter · 拉起一轮 AI 流水线（解析 → 拆要求 → 向量化 → 语义分 → 大模型精排）。
 *
 * 三个入口共用：
 *   manual  页面「立即 AI 匹配」（handlers/recruit.php handleRecruitRunPipeline）
 *   auto    有新活就自动拉（2026-09-24「能否自动触发，不要每次手动」）：
 *           邮件同步拉到新简历、页面上传简历、新建职位 / 改了 JD —— 只在「自动解析」开关打开时
 *   cron    crontab 每 5 分钟 cron_recruit_run.php：先拉邮件、再直接跑 cron_recruit_parse.php（不经过这里）
 * 已经有一轮在跑就不重复拉起（运行记录 + 文件锁双重判断）；每日 token 预算照样生效。
 */

require_once __DIR__ . '/worker_spawn.php';
require_once __DIR__ . '/recruit_cost.php';

/** 当前正在跑的那一轮 id（queued/running 且 15 分钟内开始的），没有返回 0 */
function recruitPipelineRunningId(PDO $pdo): int {
    try {
        return (int)$pdo->query("SELECT id FROM recruit_pipeline_runs WHERE status IN ('queued','running') AND started_at >= "
            . dbNowOffset('-15 minutes') . " ORDER BY id DESC LIMIT 1")->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

/**
 * 拉起一轮。@return array{status: started|running|budget|off|failed, run_id: int, error?: string}
 * $trigger = manual | auto；auto 时只在「自动解析」开关打开才拉（manual 不看开关，人点了就是要跑）
 */
function recruitSpawnPipeline(PDO $pdo, string $trigger, int $uid = 0, string $name = ''): array {
    if ($trigger === 'auto') {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='ai_intake.recruit.enabled'");
        $st->execute();
        if ((string)$st->fetchColumn() !== '1') return ['status' => 'off', 'run_id' => 0];
    }
    if (recruitTokensToday($pdo) >= recruitDailyBudget($pdo)) return ['status' => 'budget', 'run_id' => 0];
    if ($cur = recruitPipelineRunningId($pdo)) return ['status' => 'running', 'run_id' => $cur];
    $lp = sys_get_temp_dir() . '/openhunter_recruit_parse.lock';
    $fh = @fopen($lp, 'r');
    if ($fh && !flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return ['status' => 'running', 'run_id' => recruitPipelineRunningId($pdo)]; }
    if ($fh) { flock($fh, LOCK_UN); fclose($fh); }

    $runId = 0;
    try {
        $pdo->prepare("INSERT INTO recruit_pipeline_runs (trigger_type, started_by, started_by_name, status, log, started_at)
                       VALUES (?, ?, ?, 'queued', '', (" . dbNow() . "))")->execute([$trigger, $uid, mb_substr($name, 0, 191)]);
        $runId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) { /* 建表脚本没重跑：照样跑，只是没日志 */ }
    $sp = workerSpawn($pdo, dirname(__DIR__) . '/scripts/cron_recruit_parse.php',
        array_merge(['--ignore-switch', '--quiet'], $runId ? ["--run=$runId"] : []), 'recruit_parse');
    if (!$sp['ok']) {
        if ($runId) $pdo->prepare("UPDATE recruit_pipeline_runs SET status='failed', log=?, finished_at=(" . dbNow() . ") WHERE id=?")->execute([(string)$sp['error'], $runId]);
        return ['status' => 'failed', 'run_id' => $runId, 'error' => (string)$sp['error']];
    }
    return ['status' => 'started', 'run_id' => $runId];
}

/** 有新活时自动拉一轮；任何失败都吞掉——自动触发不能影响上传、保存职位、收邮件本身 */
function recruitKickPipeline(PDO $pdo, string $why): void {
    try { recruitSpawnPipeline($pdo, 'auto', 0, $why); } catch (Throwable $e) { error_log('recruitKickPipeline: ' . $e->getMessage()); }
}
