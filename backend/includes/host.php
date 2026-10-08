<?php
/**
 * 宿主适配层：招聘代码只通过这里碰「客户」与「人」，不直接查别的业务表。
 *
 * 招聘模块最初嵌在一套 CRM 里，客户、商机、收款都来自 CRM。OpenHunter 独立运行：
 *   - 客户 = recruit_clients（name = 显示名，legal_name = 法定名称，用于推荐信抬头 / 签约主体）
 *   - 商机 / 收款不存在：recruit_projects.opportunity_id 列保留，恒为 0
 * 想把 OpenHunter 再嵌回自己的 CRM，只需改这一个文件。
 *
 * 为兼容前端，客户显示名在接口里仍叫 group_name / customer_group_name。
 */

/** 招聘顾问角色：可被指定为候选人归属人 */
function recruitHostRecruiterRoles(): array {
    return ['recruiter', 'admin'];
}

/** 默认接收人（高分提醒、通知抄送、企业库查看名单从没配置过时）：所有在职系统管理员 */
function recruitHostDefaultWatchers(PDO $pdo): array {
    try {
        return array_map('intval', $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

/** 选客户：按名称 / 法定名称 / id 搜，最多 20 条。@return list<array{id:int, group_name:string}> */
function recruitHostSearchClients(PDO $pdo, string $kw): array {
    $like = '%' . $kw . '%';
    $st = $pdo->prepare("SELECT id, name group_name FROM recruit_clients
                         WHERE name LIKE ? OR COALESCE(legal_name,'') LIKE ? OR CAST(id AS CHAR)=?
                         ORDER BY id DESC LIMIT 20");
    $st->execute([$like, $like, $kw]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'group_name' => (string)$r['group_name']], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** @return array{id:int, name:string, legal_name:string, group_name:string, customer_name:string}|null */
function recruitHostClient(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT id, name, COALESCE(legal_name,'') legal_name FROM recruit_clients WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'legal_name' => (string)$r['legal_name'],
            'group_name' => (string)$r['name'], 'customer_name' => $r['legal_name'] !== '' ? (string)$r['legal_name'] : (string)$r['name']];
}

/** 新建客户；同名已存在就返回已有的 id */
function recruitHostCreateClient(PDO $pdo, string $name, string $legalName, int $uid): int {
    $st = $pdo->prepare("SELECT id FROM recruit_clients WHERE name=? LIMIT 1");
    $st->execute([$name]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) return $id;
    $pdo->prepare("INSERT INTO recruit_clients (name, legal_name, created_by, created_at, updated_at) VALUES (?, ?, ?, (" . dbNow() . "), (" . dbNow() . "))")
        ->execute([$name, $legalName, $uid]);
    return (int)$pdo->lastInsertId();
}
