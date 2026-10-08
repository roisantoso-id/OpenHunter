<?php
require_once __DIR__ . '/host.php';
/**
 * 企业库查看权限 + 设置读取（轻量，不拉其它招聘模块）：handleCurrentUser 每次登录态刷新都会用，
 * 所以单独成文件，别把 recruit_company.php 那一串 require 带进来。
 */

function recruitCompanySetting(PDO $pdo, string $key, string $default = ''): string {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false || $v === null || $v === '' ? $default : (string)$v;
}
/**
 * 谁能看企业库：只认名单，admin 角色不自动放行（企业库是敏感的挖人目标清单）。
 * 名单存 system_settings `recruit.company.viewer_ids`（JSON 用户 id 数组），在企业库「查看权限」页签里增删。
 * 没配过名单时默认所有系统管理员能看（能进来再加人）。
 */
function recruitCompanyViewerIds(PDO $pdo): array {
    $v = json_decode(recruitCompanySetting($pdo, 'recruit.company.viewer_ids', ''), true);
    if (is_array($v)) return array_values(array_unique(array_filter(array_map('intval', $v))));
    return recruitHostDefaultWatchers($pdo);
}
function recruitCompanyCanView(PDO $pdo, int $uid): bool { return $uid > 0 && in_array($uid, recruitCompanyViewerIds($pdo), true); }
/** 改企业资料 / 名次 / 赛道 / 名单：在名单里 且 是招聘管理员 */
function recruitCompanyCanEdit(PDO $pdo, int $uid): bool { return recruitCompanyCanView($pdo, $uid) && userHasModule($pdo, $uid, 'recruit_admin'); }

