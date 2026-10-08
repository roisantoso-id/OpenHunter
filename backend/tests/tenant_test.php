<?php
/**
 * 多租户隔离：php tests/tenant_test.php
 * 要守住的：每个租户一个独立库，A 租户写的数据 B 租户读不到；token 只在签发它的租户里有效，
 * 改 token 里的租户标识验签就失败；停用的租户连不上；租户标识的格式校验。
 * 会建一个临时租户 tt_iso（跑完停用；库文件留在 data 目录，可手工删）。
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

echo "一、标识校验\n";
ok(Tenant::valid('acme') && Tenant::valid('a_1') && Tenant::valid('default'), '正常标识');
ok(!Tenant::valid('A') && !Tenant::valid('a-b') && !Tenant::valid('../x') && !Tenant::valid('control') && !Tenant::valid(''), '大写 / 连字符 / 路径 / control / 空 都拒绝');

echo "\n二、建租户并隔离\n";
$slug = 'tt_iso';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/tenant.php') . ' create ' . $slug . ' "Iso Test" isoadmin isopass1234 >/dev/null 2>&1', $code);
ok($code === 0 || Tenant::find($slug), '租户创建成功（已存在也算）');
Tenant::set('default');
$a = Database::getInstance()->getConnection();
Tenant::set($slug);
$b = Database::getInstance()->getConnection();
ok($a !== $b, '两个租户是两个连接');
$mark = 'iso_' . bin2hex(random_bytes(3));
$b->prepare("INSERT INTO users (username, name, role, status, password_hash) VALUES (?, 'X', 'recruiter', 'active', 'x')")->execute([$mark]);
$st = $a->prepare("SELECT COUNT(*) FROM users WHERE username=?"); $st->execute([$mark]);
ok((int)$st->fetchColumn() === 0, '租户 B 写的账号，租户 A 看不到');
$st = $b->prepare("SELECT COUNT(*) FROM users WHERE username=?"); $st->execute([$mark]);
ok((int)$st->fetchColumn() === 1, '租户 B 自己看得到');
$b->prepare("DELETE FROM users WHERE username=?")->execute([$mark]);

echo "\n三、token 绑定租户\n";
Tenant::set('default');
$tok = generateToken(1);
ok(verifyTokenString($tok) === 1, '本租户内有效');
ok(tokenTenant($tok) === 'default', 'token 里带租户标识');
Tenant::set($slug);
ok(verifyTokenString($tok) === null, '拿 default 的 token 到别的租户 → 无效');
[$body, $sig] = explode('.', $tok);
$p = json_decode(b64urlDecode($body), true); $p['tn'] = $slug;
ok(tokenTenant(b64urlEncode(json_encode($p)) . '.' . $sig) === null, '篡改 token 里的租户 → 验签失败');

echo "\n四、停用\n";
Database::control()->prepare("UPDATE tenants SET status='disabled' WHERE slug=?")->execute([$slug]);
ok(Tenant::find($slug) === null, '停用后 find 不到');
Tenant::set($slug);
$threw = false;
try { Database::getInstance(); } catch (Throwable $e) { $threw = true; }
// 同进程里连接已缓存；新进程必须连不上
passthru('OPENHUNTER_TENANT=' . $slug . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . $root . '/includes/bootstrap.php"; try { Database::getInstance(); exit(0);} catch (Throwable $e) { exit(3);}'), $c2);
ok($c2 === 3, '新进程连停用的租户 → 拒绝');

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
