<?php
/**
 * 候选人编号 OH-CD-000050-37（纯函数）：php tests/recruit_code_test.php
 * 要守住的：格式固定、校验码按 MOD 97-10、错一位 / 相邻颠倒一定校验不过、多种输入写法都认、
 * 与前端 pages/Recruit/common.tsx candCode 同一算法（下面的固定样例两边都要成立）。
 * 另外：人才库搜索框输入编号精确到人，校验码不对一个都不返回。
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

// 固定样例：与前端 candCode 对拍（node 跑同样的算法得到同样结果）
$fixed = [1 => 'OH-CD-000001-95', 50 => 'OH-CD-000050-45', 12345 => 'OH-CD-012345-20', 999999 => 'OH-CD-999999-20'];
foreach ($fixed as $id => $code) ok(recruitCandCode($id) === $code, "$id → $code");
for ($id = 1; $id <= 3000; $id++) {
    if (((int)(sprintf('%06d', $id) . sprintf('%02d', recruitCandCheck($id)))) % 97 !== 1) { ok(false, "MOD 97 不成立：$id"); break; }
}
ok(true, '1–3000 全部满足 (序列号×100+校验码) mod 97 = 1');

// 错一位 / 相邻两位颠倒：一定校验不过
$bad = 0; $total = 0;
foreach ([50, 1234, 98765] as $id) {
    $digits = sprintf('%06d', $id) . sprintf('%02d', recruitCandCheck($id));
    for ($i = 0; $i < 8; $i++) for ($d = 0; $d <= 9; $d++) {
        if ((string)$d === $digits[$i]) continue;
        $x = $digits; $x[$i] = (string)$d; $total++;
        $p = recruitParseCandCode('OH-CD-' . substr($x, 0, 6) . '-' . substr($x, 6));
        if ($p && $p['valid']) $bad++;
    }
    for ($i = 0; $i < 7; $i++) {
        if ($digits[$i] === $digits[$i + 1]) continue;
        $x = $digits; [$x[$i], $x[$i + 1]] = [$x[$i + 1], $x[$i]]; $total++;
        $p = recruitParseCandCode('OH-CD-' . substr($x, 0, 6) . '-' . substr($x, 6));
        if ($p && $p['valid']) $bad++;
    }
}
ok($bad === 0, "错一位 / 相邻颠倒 $total 种写错全部识别出来");

$c = recruitCandCode(50);
foreach ([$c, strtolower($c), 'OH CD 000050 45', 'OHCD00005045', 'OH-CD-50-45', 'OH-CD-50', ' #50 '] as $in)
    ok(recruitParseCandCode($in) === ['id' => 50, 'valid' => true], "认得「{$in}」");
ok(recruitParseCandCode('OH-CD-000050-48')['valid'] === false, '校验码错 → valid=false');
ok(recruitParseCandCode('张三') === null && recruitParseCandCode('OH-CD-') === null && recruitParseCandCode('OH-CD-0') === null, '不是编号 → null');

[$w, $a] = recruitCandidateFilterSql(['keyword' => $c]);
ok(str_contains($w, 'c.id=?') && in_array(50, $a, true), '搜索框输入编号 → 精确到人');
[$w] = recruitCandidateFilterSql(['keyword' => 'OH-CD-000050-48']);
ok(str_contains($w, '1=0'), '校验码错 → 不返回任何人（不猜）');
ok(recruitIsExactQuery($c), '编号算精确查询，不走语义');

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
