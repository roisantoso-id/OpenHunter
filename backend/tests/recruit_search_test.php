<?php
/**
 * 人才检索测试：纯函数 + 测试库集成（假向量 / 假 embedding，自清理）。php tests/recruit_search_test.php
 * 要守住的：一句话走向量、只按某一维度检索生效、向量不可用自动退回关键字（按命中词数排）、
 * 精确号码走关键字、样本找相似、只筛选按最近收到、黑名单永远排除、人才池加成员去重且只加看得到的人。
 */

$root = dirname(__DIR__);
putenv('RECRUIT_NO_FEEDBACK=1');   // 假模型的结果别当反馈写进测试库（recruit_prompts.php）
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_match.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

echo "一、拆词\n";
ok(recruitSearchTokens('医疗器械 销售，会英语、Jakarta') === ['医疗器械', '销售', '会英语', 'jakarta'], '中英文标点 / 空白切开、转小写');
ok(recruitSearchTokens('a b cc cc') === ['cc'], '丢单字符、去重');

echo "\n二、测试库集成\n";
$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = dbNow();
$OWNER = 91001;
$made = ['cand' => [], 'pool' => [], 'job' => 0, 'proj' => 0];
$basis = function (int $k) { $v = array_fill(0, RECRUIT_EMBED_DIMS, 0.0); $v[$k] = 1.0; return $v; };
try {
    $model = (string)recruitSemConfig($pdo, true)['model'];
    $store = recruitVectorStore($pdo);
    $cand = function (string $name, string $search, array $facetK, array $extra = []) use ($pdo, $now, $OWNER, &$made, $store, $model, $basis) {
        $x = $extra + ['status' => 'new', 'years_exp' => 3, 'received' => '2026-09-01 10:00:00', 'phone' => null];
        $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, vec_rev, status, owner_user_id, search_text, years_exp, phone_key, last_received_at, created_at, updated_at)
                       VALUES (?, '{}', 1, ?, ?, ?, ?, ?, ?, ?, $now, $now)")
            ->execute([$name, $facetK ? 1 : 0, $x['status'], $OWNER, $search, $x['years_exp'], $x['phone'], $x['received']]);
        $id = $made['cand'][] = (int)$pdo->lastInsertId();
        foreach ($facetK as $f => $k) $store->upsert('cand', $id, $f, $model, $basis($k), "s$id$f");
        return $id;
    };
    $all = fn(int $k) => array_fill_keys(RECRUIT_FACETS, $k);
    $med = $cand('__Med', 'medical device sales jakarta english', $all(7), ['years_exp' => 6, 'received' => '2026-09-02 10:00:00']);
    $mix = $cand('__Mix', 'hr staff medical', ['skills' => 7, 'experience' => 9, 'industry' => 9, 'headline' => 9], ['received' => '2026-09-03 10:00:00']);
    $noVec = $cand('__NoVec', 'medical sales', [], ['received' => '2026-09-04 10:00:00', 'phone' => '6281299990001']);
    $black = $cand('__Black', 'medical device sales jakarta', $all(7), ['status' => 'blacklisted']);
    $f = ['owner_id' => $OWNER];

    $embed7 = fn(array $t) => ['ok' => true, 'vectors' => [$basis(7)]];
    $r = recruitSearchRun($pdo, $f + ['q' => '做过医疗器械销售'], null, $embed7);
    $ids = array_keys($r['rank']);
    ok($r['mode'] === 'semantic' && ($ids[0] ?? 0) === $med, '一句话 → 向量检索，最相关的在前');
    ok(!in_array($black, $ids, true), '黑名单永远排除');
    ok(!in_array($noVec, $ids, true), '没有向量的人不进向量结果');
    ok(isset($r['rank'][$med]['facets']['skills']), '返回各维度相似度（页面画条）');
    $r = recruitSearchRun($pdo, $f + ['q' => '医疗器械行业', 'facet' => 'industry'], null, $embed7);
    ok(in_array($med, array_keys($r['rank']), true) && !in_array($mix, array_keys($r['rank']), true), '只按「行业」：技能像但行业不同的不出现');

    $down = fn(array $t) => ['ok' => false, 'error_kind' => 'rejected'];
    $r = recruitSearchRun($pdo, $f + ['q' => 'medical sales jakarta'], null, $down);
    $ids = array_keys($r['rank']);
    ok($r['mode'] === 'keyword' && $r['fallback'] === true, '向量不可用 → 自动退回关键字并标记');
    ok(($ids[0] ?? 0) === $med && $r['rank'][$med]['hits'] === 3, '按命中词数排：3 个词全中的在前');
    ok(in_array($noVec, $ids, true) && !in_array($black, $ids, true), '关键字能找到没向量的人；黑名单照样排除');
    $r = recruitSearchRun($pdo, $f + ['q' => '6281299990001'], null, $down);
    ok($r['mode'] === 'keyword' && !$r['fallback'] && array_keys($r['rank']) === [$noVec], '号码 → 精确关键字，不调向量');

    $r = recruitSearchRun($pdo, $f + ['seed' => $med], null);
    ok($r['mode'] === 'seed' && $r['seed_has_vector'] && !isset($r['rank'][$med]) && isset($r['rank'][$mix]), '样本找相似：不含样本本人');
    ok(recruitSearchRun($pdo, $f + ['seed' => $noVec], null)['seed_has_vector'] === false, '样本没向量 → 页面提示');

    $r = recruitSearchRun($pdo, $f + ['min_years' => 5], null);
    ok($r['mode'] === 'filter' && array_keys($r['rank']) === [$med], '只筛选：≥5 年只剩一人');
    $r = recruitSearchRun($pdo, $f, null);
    ok(array_keys($r['rank']) === [$noVec, $mix, $med], '只筛选：最近收到的在前，黑名单不在');
    ok(recruitSearchRun($pdo, $f + ['q' => 'medical'], 91999, $down)['rank'] === [], '只看自己名下：别人名下的一个都搜不到');

    echo "\n三、一句话拆条件 + 按条件检索（三语）\n";
    require_once dirname(__DIR__) . '/includes/recruit_query.php';
    $fb = recruitQueryFallback('做过 医疗器械 销售，英语，3年以上，本科');
    ok($fb['filters'] === ['min_edu' => 's1', 'min_years' => 3.0], '规则兜底：年限 / 学历落到筛选');
    ok(($fb['conditions'][0]['type'] ?? '') === 'language' && in_array('inggris', $fb['conditions'][0]['keywords'], true), '规则兜底：语言认得出、三语关键字');
    ok(in_array('医疗器械', array_merge(...array_column($fb['conditions'], 'keywords')), true), '规则兜底：其余按词');
    $fakeLlm = fn($sys, $text) => ['ok' => true, 'data' => ['conditions' => [
            ['type' => 'industry', 'label' => ['zh' => '医疗器械', 'en' => 'Medical device', 'id' => 'Alat kesehatan'],
             'keywords' => ['医疗器械', 'Medical Device', 'alat kesehatan', 'alkes', 'x'], 'must' => true],
            ['type' => 'language', 'label' => ['zh' => '英语'], 'keywords' => ['english', '英语', 'inggris'], 'must' => false],
            ['type' => 'bogus', 'label' => [], 'keywords' => []]],
        'filters' => ['min_edu' => 'S1', 'min_years' => '3'], 'semantic_query' => 'medical device sales, English']];
    $pq = recruitParseQuery($pdo, '做过医疗器械销售，英语优先，本科 3 年以上', $fakeLlm);
    ok($pq['source'] === 'ai' && count($pq['conditions']) === 2, 'AI 拆解：空条件丢掉');
    ok($pq['conditions'][0]['keywords'] === ['医疗器械', 'medical device', 'alat kesehatan', 'alkes'], '关键字小写、丢过短的');
    ok($pq['conditions'][1]['label'] === ['zh' => '英语', 'en' => '英语', 'id' => '英语'], '缺的语言显示名用已有的补齐');
    ok($pq['filters'] === ['min_edu' => 's1', 'min_years' => 3.0], '学历 / 年限规整');
    $pq2 = recruitParseQuery($pdo, '医疗器械', fn() => ['ok' => false, 'error_kind' => 'rejected']);
    ok($pq2['source'] === 'rule' && $pq2['error_kind'] === 'rejected', 'AI 被拒 → 按规则拆并标出原因');
    ok(recruitCondScore('xx', [['keywords' => ['a1'], 'must' => true]]) === null, '必须条件没中 → 排除');
    ok(recruitCondScore('aa bb', [['keywords' => ['aa'], 'must' => true], ['keywords' => ['zz'], 'must' => false]]) === ['score' => round(2 / 3, 4), 'hits' => [0]], '加分条件没中只扣分');

    // 印尼文简历 / 英文简历，中文描述也能搜到
    $pdo->prepare("UPDATE recruit_candidates SET profile_json=? WHERE id=?")
        ->execute([json_encode(['experience' => [['description' => 'Sales alat kesehatan di Jakarta']], 'languages' => [['language' => 'English']]], JSON_UNESCAPED_UNICODE), $med]);
    $pdo->prepare("UPDATE recruit_candidates SET profile_json=? WHERE id=?")
        ->execute([json_encode(['experience' => [['description' => 'HR staff, medical device company']]], JSON_UNESCAPED_UNICODE), $mix]);
    $r = recruitSearchRun($pdo, $f + ['conds' => $pq['conditions'], 'semantic_query' => ''], null, $down);
    $ids = array_keys($r['rank']);
    ok($r['mode'] === 'conds' && $ids === [$med, $mix], '中文条件命中印尼文、英文简历；命中多的在前');
    ok($r['rank'][$med]['hit_conds'] === [0, 1] && $r['rank'][$mix]['hit_conds'] === [0], '逐条返回命中了哪几条');
    $mustEn = $pq['conditions']; $mustEn[1]['must'] = true;
    ok(array_keys(recruitSearchRun($pdo, $f + ['conds' => $mustEn], null, $down)['rank']) === [$med], '英语改成「必须」→ 没写英语的被排除');
    $r = recruitSearchRun($pdo, $f + ['conds' => $pq['conditions'], 'semantic_query' => 'medical device'], null, $embed7);
    ok($r['semantic'] === true && isset($r['rank'][$med]['facets']['skills']), '有向量时叠加语义相关度');

    echo "\n四、职位候选人默认不列 AI 低分推荐\n";
    $pdo->prepare("INSERT INTO recruit_projects (name, kind, status, created_at, updated_at) VALUES ('__low', 'client', 'open', $now, $now)")->execute();
    $made['proj'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recruit_jobs (project_id, title, jd_text, status, jd_rev, created_at, updated_at) VALUES (?, '__low job', 'x', 'open', 1, $now, $now)")->execute([$made['proj']]);
    $jid = $made['job'] = (int)$pdo->lastInsertId();
    $lk = $pdo->prepare("INSERT INTO recruit_candidate_jobs (candidate_id, job_id, origin, stage, ai_score, human_score, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, $now, $now)");
    $lk->execute([$med, $jid, 'ai', 'suggested', 2.0, null]);      // AI 低分、没人动过 → 隐藏
    $lk->execute([$mix, $jid, 'ai', 'shortlisted', 2.0, null]);    // 低分但人推进过 → 显示
    $lk->execute([$noVec, $jid, 'manual', 'shortlisted', null, null]);   // 人加的、还没评 → 显示
    $lk->execute([$black, $jid, 'ai', 'suggested', 1.0, 3.5]);    // 人工分盖过 → 显示（黑名单另有 exclude 才排）
    $ids = function (array $ff) use ($pdo) { [$w, $a] = recruitCandidateFilterSql($ff); $st = $pdo->prepare("SELECT c.id FROM recruit_candidates c WHERE $w ORDER BY c.id"); $st->execute($a); return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); };
    ok($ids(['job_id' => $jid]) === [$mix, $noVec, $black], '默认：只隐藏「AI 自动挂、没人动过、< 3 分」的');
    ok(count($ids(['job_id' => $jid, 'show_low' => 1])) === 4, 'show_low=1 全部显示');
    ok($ids(['job_id' => $jid, 'stage' => 'suggested']) === [$med, $black], '带阶段的下钻口径不变（§6.7.1）');
    ok(count($ids(['project_id' => $made['proj']])) === 3, '按项目看同样隐藏');
    ok(count($ids([])) >= 4 && recruitHidesLow([]) === false, '不按职位 / 项目看时不隐藏');

    echo "\n五、人才池成员\n";
    $admin = (int)$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO recruit_pools (name, query_text, filters_json, visibility, created_by, created_at, updated_at) VALUES ('__pool', 'medical', '{}', 'private', ?, $now, $now)")
        ->execute([$admin]);
    $pid = $made['pool'][] = (int)$pdo->lastInsertId();
    ok(recruitPoolAddMembers($pdo, $admin, 'T', $pid, [$med, $mix, 99999999]) === 2, '加 2 人（不存在的 id 跳过）');
    ok(recruitPoolAddMembers($pdo, $admin, 'T', $pid, [$med]) === 0, '重复加不重复');
    $p = recruitPoolGet($pdo, $admin, $pid);
    ok(recruitPoolCanEdit($pdo, $admin, $p) && recruitPoolOut($pdo, $admin, $p)['filters'] instanceof stdClass, '创建人可改；条件解开给页面');
} finally {
    if ($made['pool']) {
        $ids = implode(',', $made['pool']);
        $pdo->exec("DELETE FROM recruit_pool_members WHERE pool_id IN ($ids)");
        $pdo->exec("DELETE FROM recruit_pools WHERE id IN ($ids)");
    }
    if ($made['job']) { $pdo->exec("DELETE FROM recruit_candidate_jobs WHERE job_id={$made['job']}"); $pdo->exec("DELETE FROM recruit_jobs WHERE id={$made['job']}"); }
    if ($made['proj']) $pdo->exec("DELETE FROM recruit_projects WHERE id={$made['proj']}");
    if ($made['cand']) {
        $ids = implode(',', $made['cand']);
        $pdo->exec("DELETE FROM recruit_vectors WHERE owner_type='cand' AND owner_id IN ($ids)");
        $pdo->exec("DELETE FROM recruit_candidates WHERE id IN ($ids)");
    }
}

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
