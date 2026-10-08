<?php
/**
 * SQL 方言层：SQLite ↔ MySQL/RDS 的写法差异，全部收敛到这里。
 *
 * ⛔ 别在业务代码里自己判断 driver 再拼 SQL。散出去之后，
 * 迁移时漏改一处就是「在 SQLite 上跑得好好的，切 MySQL 后静默算错」——
 * 比语法错误危险，因为不报错（RDS_MIGRATION.md 阶段 4 讲的就是这类）。
 *
 * 用法：这些函数返回 **SQL 片段字符串**，拼进 SQL 里：
 *
 *   $sql = "UPDATE x SET updated_at=" . dbNow() . " WHERE id=?";
 *   $sql = "SELECT " . dbYearMonth('locked_at') . " AS ym FROM executor_commissions";
 *
 * ⚠️ 返回值直接进 SQL，**不经过预处理绑定**。所以只能传字面量列名，
 * 绝不能把用户输入传进来（dbYearMonth($_GET['col']) = SQL 注入）。
 */

/* ⛔ 这里**不能** require config/database.php：database.php 末尾会 require 本文件
 * （为的是让不走 api/handler.php 的入口——worker、cron、测试——也拿得到 dbNow()）。
 * 两边互相 require 就成了循环：require_once 虽能挡住无限递归，但会造成
 * 「Database 类还没定义完，本文件里已经在调它」的顺序问题。
 * 本文件只在**函数体内**用 Database，那时类必然已加载完毕。 */

/** 当前后端：'sqlite' | 'mysql'。全局唯一判定入口 */
function dbDriver(): string {
    static $d = null;
    if ($d === null) {
        try {
            $d = Database::getInstance()->getDriver();
        } catch (Throwable $e) {
            $d = 'sqlite';   // 连不上时按默认走，让调用方自己去撞连接错误
        }
    }
    return $d;
}

function dbIsMysql(): bool { return dbDriver() === 'mysql'; }

/** SQLite 时间修饰符：业务时区相对 UTC 的偏移，如 '+7 hours'（OPENHUNTER_UTC_OFFSET，默认 7） */
function dbTzMod(): string {
    $h = function_exists('ohUtcOffsetHours') ? ohUtcOffsetHours() : 7;
    return ($h < 0 ? '-' : '+') . abs($h) . ' hours';
}

/**
 * 当前业务时间（业务时区，见 OPENHUNTER_UTC_OFFSET）。
 *
 * SQLite: datetime('now','+N hours') —— UTC 加业务时区偏移，不依赖 TZ env
 * MySQL:  NOW() —— 连接已 SET time_zone='+07:00'（config/database.php:connectMysql）
 *
 * ⚠️ 两边都返回 'YYYY-MM-DD HH:MM:SS' 格式，存量数据可比。
 */
function dbNow(): string {
    return dbIsMysql() ? 'NOW()' : "datetime('now','" . dbTzMod() . "')";
}

/**
 * 当前业务时间偏移。$modifier 形如 '-7 days' / '+3 days' / '-12 months'。
 *
 * ⛔ $modifier 必须是代码里写死的字面量，不接受外部输入——它直接进 SQL。
 * 需要动态天数时用 dbNowMinusDaysExpr() 那种带绑定参数的写法。
 */
function dbNowOffset(string $modifier): string {
    $m = trim($modifier);
    if (!preg_match('/^([+-])\s*(\d+)\s+(second|minute|hour|day|week|month|year)s?$/i', $m, $mt)) {
        throw new InvalidArgumentException("dbNowOffset 只接受形如 '-7 days' 的字面量，收到：$modifier");
    }
    if (!dbIsMysql()) {
        return "datetime('now','" . dbTzMod() . "','" . $mt[1] . $mt[2] . ' ' . strtolower($mt[3]) . "s')";
    }
    $sign = $mt[1] === '-' ? 'DATE_SUB' : 'DATE_ADD';
    $unit = strtoupper($mt[3]);
    return "{$sign}(NOW(), INTERVAL {$mt[2]} {$unit})";
}

/**
 * 当前业务时间 + **运行时算出来的**偏移量（天数/秒数由绑定参数传入）。
 *
 * SQLite: datetime('now','+7 hours','-' || CAST(? AS INTEGER) || ' days')
 * MySQL : DATE_SUB(NOW(), INTERVAL ? DAY)
 *
 * ⛔ $unit 必须是代码里写死的字面量（DAY/SECOND/...），不接受外部输入——它直接进 SQL。
 * 数值走绑定参数，安全。
 *
 * @param string $unit  DAY | HOUR | MINUTE | SECOND | MONTH | YEAR
 * @param string $sign  '-' 或 '+'
 */
function dbNowOffsetParam(string $unit, string $sign = '-'): string {
    $u = strtoupper(trim($unit));
    if (!in_array($u, ['SECOND', 'MINUTE', 'HOUR', 'DAY', 'MONTH', 'YEAR'], true)) {
        throw new InvalidArgumentException("dbNowOffsetParam 单位只能是 SECOND/MINUTE/HOUR/DAY/MONTH/YEAR，收到：$unit");
    }
    if ($sign !== '-' && $sign !== '+') {
        throw new InvalidArgumentException("dbNowOffsetParam 符号只能是 + 或 -");
    }
    if (dbIsMysql()) {
        $fn = $sign === '-' ? 'DATE_SUB' : 'DATE_ADD';
        return "{$fn}(NOW(), INTERVAL ? {$u})";
    }
    // SQLite：单位小写复数形式（days/seconds/...）
    $su = strtolower($u) . 's';
    return "datetime('now','" . dbTzMod() . "','{$sign}' || CAST(? AS INTEGER) || ' {$su}')";
}

/**
 * 取 'YYYY-MM' 年月串。用于按月分组统计。
 *
 * SQLite: substr(col,1,7) —— 时间戳是 TEXT，直接截字符串
 * MySQL:  DATE_FORMAT(col,'%Y-%m')
 *
 * ⚠️ $col 必须是字面量列名（可带表别名），不接受用户输入。
 */
function dbYearMonth(string $col): string {
    assertPlainIdentifier($col, 'dbYearMonth');
    return dbIsMysql() ? "DATE_FORMAT($col,'%Y-%m')" : "substr($col,1,7)";
}

/** 取 'YYYY' 年份串 */
function dbYear(string $col): string {
    assertPlainIdentifier($col, 'dbYear');
    return dbIsMysql() ? "DATE_FORMAT($col,'%Y')" : "strftime('%Y',$col)";
}

/** 取 'YYYY-Qn' 季度串（如 2026-Q3）。SQLite 无 QUARTER()，按月份算 */
function dbYearQuarter(string $col): string {
    assertPlainIdentifier($col, 'dbYearQuarter');
    return dbIsMysql()
        ? "CONCAT(DATE_FORMAT($col,'%Y'),'-Q',QUARTER($col))"
        : "strftime('%Y',$col) || '-Q' || ((CAST(strftime('%m',$col) AS INTEGER)+2)/3)";
}

/** 取 'YYYYMM' 紧凑年月（executor_commissions.period_index 那种格式） */
function dbYearMonthCompact(string $col): string {
    assertPlainIdentifier($col, 'dbYearMonthCompact');
    return dbIsMysql() ? "DATE_FORMAT($col,'%Y%m')" : "strftime('%Y%m',$col)";
}

/** 取 'MM' 月份串 */
function dbMonth(string $col): string {
    assertPlainIdentifier($col, 'dbMonth');
    return dbIsMysql() ? "DATE_FORMAT($col,'%m')" : "strftime('%m',$col)";
}

/**
 * 两个日期相差多少天（$later - $earlier，正数表示 $later 在后）。
 *
 * SQLite: julianday() 差值，返回小数天
 * MySQL : DATEDIFF()，返回整数天
 *
 * ⚠️ 两边精度不同（SQLite 带小数、MySQL 只到天），所以**只适合喂给 AVG/SUM 看趋势**，
 * 别拿单行结果去和一个精确天数做等值比较。
 *
 * ⚠️ $later / $earlier 必须是字面量列名（可带表别名），不接受用户输入。
 */
function dbDateDiffDays(string $later, string $earlier): string {
    assertPlainIdentifier($later, 'dbDateDiffDays');
    assertPlainIdentifier($earlier, 'dbDateDiffDays');
    return dbIsMysql()
        ? "DATEDIFF($later, $earlier)"
        : "(julianday($later) - julianday($earlier))";
}

/**
 * 按**字符数**计算长度（不是字节数）。
 *
 * SQLite: length()      —— 对 TEXT 本来就按字符数
 * MySQL : CHAR_LENGTH() —— MySQL 的 LENGTH() 数字节，多字节字符会数错
 *
 * ⛔ 不能在 SQL 里直接写 CHAR_LENGTH()：SQLite 没有这个函数，
 * 报 "no such function"。而调用点若把 PDOException 吞掉（kbli_helpers 就是），
 * 表现是**静默返回空结果**，不报错、更难查
 * （2026-09-04 实测：KBLI 检索在 SQLite 下全部 0 命中）。
 */
function dbCharLength(string $expr): string {
    assertPlainIdentifier($expr, 'dbCharLength');
    return dbIsMysql() ? "CHAR_LENGTH($expr)" : "length($expr)";
}

/**
 * 大小写不敏感比较的修饰符。用法：`WHERE name = ?" . dbNoCase() . "`
 *
 * SQLite 默认**区分**大小写，要显式写 `COLLATE NOCASE`；
 * MySQL 的 utf8mb4_unicode_ci **本身就不区分**，且没有 NOCASE 这个 collation
 * （写了报 1273 Unknown collation），所以返回空串。
 * 两边最终语义一致：都按不区分大小写比较。
 */
function dbNoCase(): string {
    return dbIsMysql() ? '' : ' COLLATE NOCASE';
}

/**
 * 标量「取较大值」：dbGreatest('1', $subquery)。
 *
 * ⛔ 两边函数名不同，而且**互相没有对方那个**：
 *   SQLite: MAX(a, b) —— 多参数时是标量函数（单参数才是聚合）
 *   MySQL : GREATEST(a, b) —— MySQL 的 MAX() 只有聚合语义，
 *           写 MAX(1, subquery) 直接报 1064（踩过：交付任务列表整个打不开）
 * SQLite 没有 GREATEST，MySQL 的 MAX 不接两个参数，所以必须按 driver 分。
 *
 * ⚠️ 参数直接进 SQL、不走绑定 —— 只能传代码里写死的表达式。
 */
function dbGreatest(string ...$args): string {
    if (count($args) < 2) {
        throw new InvalidArgumentException('dbGreatest 至少要两个参数');
    }
    $fn = dbIsMysql() ? 'GREATEST' : 'MAX';
    return $fn . '(' . implode(', ', $args) . ')';
}

/** 标量「取较小值」。同 dbGreatest 的理由：SQLite 用 MIN、MySQL 用 LEAST */
/**
 * 代付代缴报价项里「替客户转付、不算我们收入」的那部分金额（别名必须是 qi）。
 *
 * 拆了本金/手续费的行只算本金——手续费是服务收入，PPh23 要征、营收要计
 * （2026-09-18 代发薪资：82jt 本金 + 10% 手续费，只有 8.2jt 是我们的）。
 * 没拆的（pass_through_amount=0）沿用整行 amount——老代缴项常把钱塞在 amount、
 * pass_through_amount 留空（[代缴]1200美金单 amount=22jt/过路费=0），按后者扣会扣不掉。
 * LEAST 封顶：本金×数量理论上 ≤ amount，异常数据不能让净额算成负。
 *
 * ⛔ 三处同一条规则（§6.7.1）：本函数（payments.php oppPassThroughRatio、
 *    dashboard.php dashboardRevenueExprs）+ 前端 Opportunities/index.tsx passThroughRatioOf。
 *    改任一处必须同步另两处。
 */
function dbPassThroughQiAmountExpr(): string {
    $qty = 'COALESCE(qi.quantity,1) * ' . dbGreatest('COALESCE(qi.category_count,1)', '1');
    return 'CASE WHEN COALESCE(qi.pass_through_amount,0) > 0
                 THEN ' . dbLeast('COALESCE(qi.amount,0)', "qi.pass_through_amount * $qty") . '
                 ELSE COALESCE(qi.amount,0) END';
}

function dbLeast(string ...$args): string {
    if (count($args) < 2) {
        throw new InvalidArgumentException('dbLeast 至少要两个参数');
    }
    $fn = dbIsMysql() ? 'LEAST' : 'MIN';
    return $fn . '(' . implode(', ', $args) . ')';
}

/**
 * DATETIME 列「空/非空」判断（2026-09-08 10:30，切换后第 13 类）。
 * SQLite 日期列默认 '' ，代码里到处 `signed_at IS NULL OR signed_at=''`；
 * MySQL 严格模式把 '' 转 DATETIME 失败 → 1525 Incorrect DATETIME value: ''，
 * 且 OR 不短路，IS NULL 为真时 `=''` 那半截照样炸。导数据时 '' 已成 NULL。
 *   dbDateEmpty('o.signed_at')     →  SQLite: COALESCE(o.signed_at,'') = ''    MySQL: o.signed_at IS NULL
 *   dbDateNotEmpty('o.signed_at')  →  SQLite: COALESCE(o.signed_at,'') <> ''   MySQL: o.signed_at IS NOT NULL
 * 写入侧清空一律 `SET col = NULL`，别写 ''（MySQL 1292）。
 */
function dbDateEmpty(string $col): string {
    return dbIsMysql() ? "$col IS NULL" : "COALESCE($col,'') = ''";
}
function dbDateNotEmpty(string $col): string {
    return dbIsMysql() ? "$col IS NOT NULL" : "COALESCE($col,'') <> ''";
}

/**
 * 字符串拼接。SQLite 用 `||`，MySQL 里 `||` 是**逻辑 OR**（除非 sql_mode 开 PIPES_AS_CONCAT），
 * `notes || ' | ' || ?` 会把右边的中文转成整数做布尔运算 → 1292 Truncated incorrect INTEGER value
 * （2026-09-08 00:50 负责人撤回报销撞到，切换后第 11 类方言）。
 * 两端 NULL 语义一致：任一参数 NULL 结果 NULL。
 *   dbConcat("notes", "' | '", "?")  →  SQLite: (notes || ' | ' || ?)   MySQL: CONCAT(notes, ' | ', ?)
 */
function dbConcat(string ...$parts): string {
    if (count($parts) === 0) return "''";
    return dbIsMysql() ? 'CONCAT(' . implode(', ', $parts) . ')' : '(' . implode(' || ', $parts) . ')';
}

/**
 * GROUP_CONCAT，分隔符语法两边不同。
 *
 * SQLite: GROUP_CONCAT(col, ', ')
 * MySQL:  GROUP_CONCAT(col SEPARATOR ', ')
 */
function dbGroupConcat(string $col, string $sep = ',', bool $rawExpr = false): string {
    /* $rawExpr=true：$col 是任意 SQL 表达式（如 dbConcat(...)），$sep 是 SQL 原文（如 char(30)）而非字面量。
     * 给 customers.php 的 docs_raw 用——它拼 4 个字段再用 char(30) 分隔，
     * 原来写成 GROUP_CONCAT(a||b||c, char(30))：`||` 在 MySQL 是 OR，两参数形式在 MySQL 会把 char(30)
     * 当成第二个拼接列，一行两个坑（2026-09-08 切换后扫出）。 */
    if ($rawExpr) {
        return dbIsMysql()
            ? "GROUP_CONCAT($col SEPARATOR $sep)"
            : "GROUP_CONCAT($col, $sep)";
    }
    assertPlainIdentifier($col, 'dbGroupConcat');
    if (strpos($sep, "'") !== false) {
        throw new InvalidArgumentException('dbGroupConcat 分隔符不得含单引号');
    }
    return dbIsMysql()
        ? "GROUP_CONCAT($col SEPARATOR '$sep')"
        : "GROUP_CONCAT($col, '$sep')";
}

/**
 * 临时关闭/打开外键约束（批量删除、整表重灌时用）。
 *
 * SQLite: PRAGMA foreign_keys = OFF/ON
 * MySQL : SET FOREIGN_KEY_CHECKS = 0/1
 *
 * ⛔ PRAGMA 是 SQLite 专有，MySQL 上报 1064。
 * ⚠️ 关掉之后一定要开回来——两边都是**连接级**设置，
 *    php-fpm 连接复用时会带到下一个请求。
 */
function dbForeignKeys(bool $on): string {
    if (dbIsMysql()) return 'SET FOREIGN_KEY_CHECKS = ' . ($on ? '1' : '0');
    return 'PRAGMA foreign_keys = ' . ($on ? 'ON' : 'OFF');
}

/**
 * 两个时间点相差多少天（浮点）。
 *
 * SQLite: julianday(a) - julianday(b)
 * MySQL : TIMESTAMPDIFF(SECOND, b, a) / 86400
 *
 * ⛔ julianday() 是 SQLite 专有，MySQL 上报 1305 FUNCTION does not exist。
 * ⚠️ 注意参数顺序：返回 $later - $earlier（$later 在后为正）。
 */
function dbDaysBetween(string $later, string $earlier): string {
    return dbIsMysql()
        ? "(TIMESTAMPDIFF(SECOND, $earlier, $later) / 86400.0)"
        : "(julianday($later) - julianday($earlier))";
}

/**
 * 今天的日期（YYYY-MM-DD，雅加达时区）。
 *
 * SQLite: date('now','+7 hours')   MySQL: DATE(NOW())
 * （MySQL 侧连接已设 time_zone='+07:00'，NOW() 本身就是雅加达时间）
 */
function dbToday(): string {
    return dbIsMysql() ? "DATE(NOW())" : "date('now','" . dbTzMod() . "')";
}

/**
 * 表是否存在。
 * SQLite: sqlite_master   MySQL: information_schema.tables
 */
/**
 * 这个 PDO 连的是不是 MySQL。
 *
 * ⛔ 不能只看全局的 dbDriver()：它有 static 缓存，而测试/脚本会自建
 * `:memory:` SQLite 连接传进来。全局缓存是 mysql 时，对 SQLite 连接发
 * information_schema 查询会直接炸（踩过：customer_aggregate_test）。
 * 按**传入的连接**判断才对。
 */
function pdoIsMysql(PDO $pdo): bool {
    try { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
    catch (Throwable $e) { return dbIsMysql(); }
}

function dbTableExists(PDO $pdo, string $table): bool {
    if (pdoIsMysql($pdo)) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
    } else {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?");
    }
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * 列是否存在。
 * SQLite: PRAGMA table_info（不能用绑定参数，故先校验标识符）
 * MySQL:  information_schema.columns
 */
function dbColumnExists(PDO $pdo, string $table, string $column): bool {
    if (pdoIsMysql($pdo)) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
        );
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    }
    assertPlainIdentifier($table, 'dbColumnExists');
    $rows = $pdo->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (strcasecmp((string)$r['name'], $column) === 0) return true;
    }
    return false;
}

/** 全部表名 */
function dbListTables(PDO $pdo): array {
    $sql = pdoIsMysql($pdo)
        ? "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() ORDER BY table_name"
        : "SELECT name FROM sqlite_master WHERE type='table'
             AND name NOT LIKE 'sqlite_%' ORDER BY name";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * 「插入，主键/唯一键冲突就跳过」。
 *
 * SQLite: INSERT OR IGNORE   MySQL: INSERT IGNORE
 *
 * ⚠️ MySQL 的 INSERT IGNORE 会把**所有**错误降级成警告（类型不符、超长等），
 * 不只是唯一键冲突。所以只在「确实只可能撞唯一键」的场景用。
 */
function dbInsertIgnore(): string {
    return dbIsMysql() ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
}

/**
 * 「插入，主键/唯一键冲突就整行替换」。
 *
 * SQLite: INSERT OR REPLACE INTO   MySQL: REPLACE INTO
 *
 * ⚠️ 两边都是**先删后插**：未在本次 INSERT 里列出的列会回到默认值，
 * 且会触发外键级联删除。只在「整行覆盖」确实是意图时用
 * （如缓存表 wecom_contact_cache、translations）。
 * 要「只更新部分列」请改用 dbUpsertSql()。
 */
function dbReplaceInto(): string {
    return dbIsMysql() ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';
}

/**
 * Upsert：插入，冲突则更新指定列。
 *
 * SQLite: INSERT ... ON CONFLICT(key) DO UPDATE SET c=excluded.c
 * MySQL:  INSERT ... ON DUPLICATE KEY UPDATE c=VALUES(c)
 *
 * ⛔ 不用 SQLite 的 INSERT OR REPLACE：它是**先删后插**，
 * 会丢掉未在本次 INSERT 里列出的列（置回默认值），还会触发外键级联删除。
 * MySQL 的 REPLACE INTO 同样是删+插，同样的坑。两边都用真正的 upsert。
 *
 * @param string   $table      表名（字面量）
 * @param string[] $insertCols 插入的列
 * @param string[] $conflictKeys 冲突判定键（SQLite 需要，MySQL 忽略但仍要求传，
 *                                以便调用方明确自己依赖哪个唯一键）
 * @param string[] $updateCols 冲突时更新哪些列
 */
function dbUpsertSql(string $table, array $insertCols, array $conflictKeys, array $updateCols): string {
    assertPlainIdentifier($table, 'dbUpsertSql');
    foreach (array_merge($insertCols, $conflictKeys, $updateCols) as $c) {
        assertPlainIdentifier($c, 'dbUpsertSql');
    }
    if (!$insertCols)   throw new InvalidArgumentException('dbUpsertSql: insertCols 不能为空');
    if (!$conflictKeys) throw new InvalidArgumentException('dbUpsertSql: 必须显式声明依赖哪个唯一键');
    if (!$updateCols)   throw new InvalidArgumentException('dbUpsertSql: updateCols 为空请改用 dbInsertIgnore()');

    $cols = '`' . implode('`,`', $insertCols) . '`';
    $ph   = implode(',', array_fill(0, count($insertCols), '?'));

    if (dbIsMysql()) {
        $sets = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $updateCols));
        return "INSERT INTO `$table` ($cols) VALUES ($ph) ON DUPLICATE KEY UPDATE $sets";
    }
    $keys = '`' . implode('`,`', $conflictKeys) . '`';
    $sets = implode(',', array_map(fn($c) => "`$c`=excluded.`$c`", $updateCols));
    return "INSERT INTO `$table` ($cols) VALUES ($ph) ON CONFLICT($keys) DO UPDATE SET $sets";
}

/**
 * Upsert 的冲突子句（只出 `ON CONFLICT(...) DO UPDATE SET ...` / `ON DUPLICATE KEY UPDATE ...`
 * 这一段，INSERT 前半截由调用方自己拼）。
 *
 * 为什么需要它而不是都用 dbUpsertSql()：真实调用点里 SET 子句常混着
 * `updated_at=(dbNow())` 这种**非占位符表达式**，dbUpsertSql 只支持 `列=VALUES(列)`
 * 的纯列映射，套不进去。
 *
 * SQLite: ON CONFLICT(k) DO UPDATE SET a=excluded.a, updated_at=(datetime(...))
 * MySQL : ON DUPLICATE KEY UPDATE a=VALUES(a), updated_at=(NOW())
 *
 * ⛔ `excluded.x` 是 SQLite/Postgres 语法，MySQL 认的是 `VALUES(x)`——
 * 2026-09-07 迁 RDS 时，保存系统设置直接报
 * `1064 ... near 'CONFLICT(setting_key) DO UPDATE SET'`。全仓 22 处同款。
 *
 * @param string[] $conflictKeys 冲突判定键（MySQL 忽略，但仍要求传，
 *                               以便调用方明确自己依赖哪个唯一键）
 * @param string[] $copyCols     从待插入行取值的列（SQLite excluded.x / MySQL VALUES(x)）
 * @param string[] $rawSets      原样拼接的赋值，如 ["updated_at=(" . dbNow() . ")"]
 */
function dbOnConflict(array $conflictKeys, array $copyCols, array $rawSets = []): string {
    foreach (array_merge($conflictKeys, $copyCols) as $c) {
        assertPlainIdentifier($c, 'dbOnConflict');
    }
    if (!$conflictKeys) throw new InvalidArgumentException('dbOnConflict: 必须显式声明依赖哪个唯一键');
    if (!$copyCols && !$rawSets) throw new InvalidArgumentException('dbOnConflict: 没有要更新的列');

    if (dbIsMysql()) {
        $sets = array_map(fn($c) => "`$c`=VALUES(`$c`)", $copyCols);
        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', array_merge($sets, $rawSets));
    }
    $keys = '`' . implode('`,`', $conflictKeys) . '`';
    $sets = array_map(fn($c) => "`$c`=excluded.`$c`", $copyCols);
    return "ON CONFLICT($keys) DO UPDATE SET " . implode(', ', array_merge($sets, $rawSets));
}

/**
 * 在 upsert 的 SET 子句里引用「本次待插入的值」。
 *
 * SQLite: excluded.col     MySQL: VALUES(col)
 *
 * 给 dbOnConflict() 的 $rawSets 里写复杂表达式时用，例如
 * 「新值非空才覆盖，否则保留库里已有的」：
 *   "title_zh=CASE WHEN " . dbNewVal('title_zh') . " != '' THEN " . dbNewVal('title_zh')
 *     . " ELSE kbli_cache.title_zh END"
 */
function dbNewVal(string $col): string {
    assertPlainIdentifier($col, 'dbNewVal');
    return dbIsMysql() ? "VALUES(`$col`)" : "excluded.`$col`";
}

/**
 * CAST 的目标类型：SQLite 与 MySQL 的类型名根本不是一套。
 *
 * SQLite 的 CAST 接受它那套「类型亲和」名字（INTEGER / REAL / TEXT），
 * MySQL 的 CAST 只认固定几个（SIGNED / DECIMAL / CHAR），给它 INTEGER 直接
 *   1064 ... near 'INTEGER)' at line N
 *
 * 对照：
 *   CAST(x AS INTEGER)  → MySQL: CAST(x AS SIGNED)
 *   CAST(x AS REAL)     → MySQL: CAST(x AS DECIMAL(18,4))
 *   CAST(x AS TEXT)     → MySQL: CAST(x AS CHAR)
 *
 * ⚠️ REAL 用 DECIMAL(18,4) 而不是 DOUBLE：MySQL 的 CAST 压根不支持 DOUBLE
 * （8.0.17 起才有，且本项目金额一律走 DECIMAL，混用会引入浮点误差）。
 * 4 位小数够用——本项目最细的是汇率，金额都是 2 位。
 *
 * 用法：
 *   "CAST(? AS " . dbCastInt() . ")"
 *   "CAST(" . dbYear('period_start') . " AS " . dbCastInt() . ")=?"
 */
function dbCastInt(): string {
    return dbIsMysql() ? 'SIGNED' : 'INTEGER';
}

function dbCastReal(): string {
    return dbIsMysql() ? 'DECIMAL(18,4)' : 'REAL';
}

function dbCastText(): string {
    return dbIsMysql() ? 'CHAR' : 'TEXT';
}

/**
 * JSON 数组聚合的**函数名 + 左括号**。
 *
 * SQLite: json_group_array(x)    MySQL: JSON_ARRAYAGG(x)
 * 功能一致，只是名字不同（2026-09-07 负责人点交付列表报
 * 1305 FUNCTION mydb.json_group_array does not exist）。
 *
 * ⚠️ 刻意返回「名字+左括号」而不是包装整个表达式：调用点的参数常常跨十几行
 * （nba_analytics 的 json_object(...) 有 15 个字段），把它们塞进函数参数
 * 要么得拼超长字符串、要么改动大到看不出 diff。只换名字，括号结构原样不动。
 *
 * ⚠️ 空结果集两边都返回 NULL（不是 '[]'），调用方要 COALESCE 或在 PHP 侧兜底。
 *
 * 用法：  "(SELECT " . dbJsonArrayAggOpen() . "json_array(a, b)) FROM t)"
 */
function dbJsonArrayAggOpen(): string {
    return dbIsMysql() ? 'JSON_ARRAYAGG(' : 'json_group_array(';
}

/**
 * JSON 对象聚合的函数名 + 左括号。
 * SQLite json_group_object(k,v) / MySQL JSON_OBJECTAGG(k,v)。
 */
function dbJsonObjectAggOpen(): string {
    return dbIsMysql() ? 'JSON_OBJECTAGG(' : 'json_group_object(';
}

/**
 * 标识符白名单校验。这些函数的返回值直接进 SQL、不走绑定，
 * 传进用户输入就是注入。允许 表别名.列名 和引号包裹。
 */
function assertPlainIdentifier(string $ident, string $fn): void {
    if (!preg_match('/^[`"\[]?[A-Za-z_][A-Za-z0-9_]*[`"\]]?(\.[`"\[]?[A-Za-z_][A-Za-z0-9_]*[`"\]]?)?$/', trim($ident))) {
        throw new InvalidArgumentException("$fn 只接受字面量标识符，收到：$ident");
    }
}

/**
 * 「这一列长得像 YYYY-MM」的判定。
 *
 * ⛔ 起因（2026-09-08 生产 1064）：
 *   period_label GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]'
 * GLOB 是 SQLite 专有运算符，MySQL 直接语法错误——整个接口 500。
 *
 * 两边分流：
 *   SQLite → GLOB（本项目一直用它，行为已知，不动）
 *   MySQL  → REGEXP，且必须加 ^...$ 锚定。
 *            ⚠️ MySQL 的 REGEXP 默认是**子串匹配**，不锚定的话
 *            '2026-09-15' 里的 '2026-09' 也会命中，把日期误判成期次标签。
 *            GLOB 则是整串匹配，两者语义不同，这里靠锚定对齐。
 *
 * @param string $col 列名（已引用好的表达式亦可）
 */
function dbLooksLikeYearMonth(string $col): string
{
    return dbIsMysql()
        ? "$col REGEXP '^[0-9]{4}-[0-9]{2}$'"
        : "$col GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]'";
}

/**
 * 「这一列里含有 <数字>人 这样的片段」（jarvis_finance F-x 的弱指纹判定）。
 *
 * SQLite 的 GLOB '*[0-9]人*' 是子串匹配；MySQL 用不锚定的 REGEXP 即等价。
 */
function dbContainsDigitThen(string $col, string $suffix): string
{
    $s = str_replace("'", "''", $suffix);
    return dbIsMysql()
        ? "$col REGEXP '[0-9]$s'"
        : "$col GLOB '*[0-9]$s*'";
}

/**
 * 把**存储列**从 UTC 换算到雅加达（+7），取日期或完整时间。
 *
 * ⛔ 起因（2026-09-08 生产 1064）：
 *   SELECT DATE(created_at, '+7 hours') …
 * `date(列, '修饰符')` 是 SQLite 专有写法；MySQL 的 DATE() 只接受一个参数，
 * 第二个参数直接语法错误，整个接口 500。
 *
 * ⚠️ 与 dbNow() / dbToday() 不同：那两个换算的是「当前时刻」，
 * 这个换算的是**表里已存的列值**，两者不能互相替代。
 *
 * ⚠️ 为什么不用 CONVERT_TZ：它依赖 MySQL 时区表（mysql.time_zone_name），
 * RDS 默认没导入，返回 NULL 而不报错——一个静默返回 NULL 的日期分组
 * 比报错更难查。这里用固定 INTERVAL 7 HOUR，与 SQLite 侧的硬编码 +7 完全对齐。
 *
 * @param string $col 列名或表达式
 */
function dbJakartaDate(string $col): string
{
    return dbIsMysql()
        ? "DATE($col + INTERVAL " . (function_exists("ohUtcOffsetHours") ? ohUtcOffsetHours() : 7) . " HOUR)"
        : "date($col, '" . dbTzMod() . "')";
}

function dbJakartaDateTime(string $col): string
{
    return dbIsMysql()
        ? "($col + INTERVAL " . (function_exists("ohUtcOffsetHours") ? ohUtcOffsetHours() : 7) . " HOUR)"
        : "datetime($col, '" . dbTzMod() . "')";
}

/**
 * 这个 PDOException 是不是「唯一键冲突」（重复记录）。
 *
 * ⛔ 别再写 `strpos($e->getMessage(), 'UNIQUE') !== false`：
 *    那只匹配 **SQLite** 的措辞（`UNIQUE constraint failed: ...`）。
 *    MySQL 报的是 `Integrity constraint violation: 1062 Duplicate entry ...`，
 *    里面**没有 UNIQUE 这个词**，判断恒 false —— 本该「跳过重复」的分支走不到，
 *    重复记录被当成致命错误，整批导入中断
 *    （2026-09-22 企微收款同步实测：fetched 1 / inserted 0，报「落库失败」）。
 *
 * 判据用 SQLSTATE 23000（完整性约束冲突，两种驱动一致）+ 驱动错误码：
 * MySQL 1062 = Duplicate entry；SQLite 19 = SQLITE_CONSTRAINT。
 * ⚠️ 23000 还覆盖外键/非空冲突，所以必须再看驱动码，不能只认 23000。
 */
function dbIsDuplicateKeyError(PDOException $e): bool
{
    if (($e->getCode() ?? '') !== '23000') return false;
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    return dbIsMysql() ? ($driverCode === 1062) : ($driverCode === 19 || $driverCode === 2067);
}
