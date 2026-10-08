<?php
/**
 * 统一加载入口：API、cron、worker、迁移脚本都只 require 这一个文件。
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/db_dialect.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/oss.php';
require_once __DIR__ . '/openai_vision.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/web_research.php';
require_once __DIR__ . '/mailsync.php';
require_once __DIR__ . '/worker_spawn.php';
require_once __DIR__ . '/host.php';
foreach (glob(__DIR__ . '/handlers/*.php') as $__h) require_once $__h;
unset($__h);
