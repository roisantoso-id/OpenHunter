<?php
/**
 * AI 接口配置与文件读取。
 *   openaiOcrConfig       读 system_settings 的 ocr.openai.*（api_key 加密存储；endpoint 任意 OpenAI 兼容地址）
 *   readFileBinaryByPath  读本地路径或 oss:// 路径的文件内容
 */

require_once __DIR__ . '/oss.php'; // encryptSecret / decryptSecret

function openaiOcrConfig($pdo): ?array {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'ocr.openai.%'");
        $map = [];
        foreach ($stmt->fetchAll() as $r) $map[$r['setting_key']] = (string)$r['setting_value'];

        $apiKey = (string)($map['ocr.openai.api_key'] ?? '');
        if ($apiKey !== '' && function_exists('decryptSecret')) {
            try { $apiKey = decryptSecret($apiKey); } catch (Exception $e) {}
        }
        if ($apiKey === '') return null;

        return [
            'api_key'  => $apiKey,
            'model'    => trim((string)($map['ocr.openai.model'] ?? 'gpt-4.1-mini')) ?: 'gpt-4.1-mini',
            'endpoint' => trim((string)($map['ocr.openai.endpoint'] ?? '')) ?: 'https://api.openai.com/v1/chat/completions',
        ];
    } catch (Exception $e) {
        return null;
    }
}


function readFileBinaryByPath($pdo, string $filePath): ?string {
    if ($filePath === '') return null;
    if (preg_match('#^oss://#', $filePath)) {
        require_once __DIR__ . '/oss.php';
        try {
            [$bucket, $key] = parseOssPath($filePath);
            if (!$bucket || !$key) return null;
            $client = ossPublicClient($pdo);
            if (!$client) return null;
            $bin = $client->getObject($bucket, $key);
            return is_string($bin) && $bin !== '' ? $bin : null;
        } catch (Exception $e) {
            error_log('OSS read failed for ' . $filePath . ': ' . $e->getMessage());
            return null;
        }
    }
    $abs = $filePath;
    if (!preg_match('#^/#', $abs)) {
        $abs = __DIR__ . '/../' . ltrim($abs, '/');
    }
    if (!is_readable($abs)) return null;
    $bin = @file_get_contents($abs);
    return $bin === false ? null : $bin;
}
