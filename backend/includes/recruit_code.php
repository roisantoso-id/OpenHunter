<?php
/**
 * 候选人编号（2026-09-24「候选人需要一个编号 OH-CD-序列号-xx 唯一识别」）：
 *   OH-CD-000050-37 = 前缀 + 6 位序列号（= recruit_candidates.id）+ 2 位校验码
 * 校验码按 ISO 7064 MOD 97-10（IBAN 同款）：(序列号 × 100 + 校验码) mod 97 = 1。
 * 电话里报编号、手输编号时错一位或相邻两位颠倒，校验一定不过，系统能识别出来，不会找错人。
 * 由 id 现算、终身不变，不落库——转移归属、合并都不影响已发给客户的推荐信上的编号。
 * ⛔ 前端 pages/Recruit/common.tsx 的 candCode / parseCandCode 是同一算法，改一处必须改另一处。
 */

function recruitCandCheck(int $id): int { return 98 - (($id * 100) % 97); }

function recruitCandCode(int $id): string { return sprintf('OH-CD-%06d-%02d', $id, recruitCandCheck($id)); }

/**
 * 解析用户输入的编号。认：OH-CD-000050-37 / oh cd 50 37 / OHCD00005037（连写时 8 位 = 6 位序列号 + 2 位校验）/
 * OH-CD-50（不带校验码）/ #50。
 * @return array{id:int, valid:bool}|null 不是编号返回 null；带了校验码但对不上 valid=false
 */
function recruitParseCandCode(string $s): ?array {
    $s = trim($s);
    if (preg_match('/^#(\d{1,9})$/', $s, $m)) return ['id' => (int)$m[1], 'valid' => true];
    if (!preg_match('/^OH[\s\-_]*CD[\s\-_]*(\d{1,9})(?:[\s\-_]+(\d{1,2}))?$/i', $s, $m)) return null;
    $num = $m[1]; $chk = $m[2] ?? null;
    if ($chk === null && strlen($num) === 8) { $chk = substr($num, 6); $num = substr($num, 0, 6); }
    $id = (int)$num;
    if ($id <= 0) return null;
    return ['id' => $id, 'valid' => $chk === null || (int)$chk === recruitCandCheck($id)];
}
