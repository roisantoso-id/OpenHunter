<?php
/**
 * 写一条 system_settings（AI 接口、搜索 key 等运行时配置）。
 *
 *   php scripts/set_setting.php ocr.openai.api_key sk-xxxx
 *   php scripts/set_setting.php ocr.openai.endpoint https://api.openai.com/v1/chat/completions
 *   php scripts/set_setting.php ocr.openai.model gpt-4.1-mini
 *   php scripts/set_setting.php intel.tavily.api_key tvly-xxxx        # 可选：企业库联网补全
 *
 * 值会进命令行历史；生产上建议在页面「招聘 → 设置」或用 `read -s` 传入。
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if ($argc < 3) { fwrite(STDERR, "用法: php scripts/set_setting.php <key> <value>\n"); exit(1); }
setSystemSetting(Database::getInstance()->getConnection(), $argv[1], $argv[2]);
echo "ok {$argv[1]}\n";
