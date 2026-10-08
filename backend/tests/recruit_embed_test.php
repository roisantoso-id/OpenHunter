<?php
/**
 * 招聘向量（语义关联）测试：纯函数 + 测试库集成（假 embedding，自清理）。
 *   php tests/recruit_embed_test.php
 *
 * 假 embedding：按词哈希到维度的词袋向量——共享词越多余弦越高，足以验证「相近的排前面」与整条流水线，
 * 不调任何外部接口。
 *
 * 要守住的：向量归一化（余弦=点积）、维度缺失不当 0 分、只在文本变了才重算、失败不推进版本、
 * 语义分入表且只重算变了的、搜索与相似候选人按相关度排序。
 */

$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/openai_vision.php';
require_once $root . '/includes/recruit_match.php';

$fails = 0;
function ok($cond, string $name) {
    global $fails;
    echo ($cond ? "  ✓ " : "  ✗ ") . $name . "\n";
    if (!$cond) $fails++;
}
function fakeVec(string $t): array {
    $v = array_fill(0, 3072, 0.0);   // 与 gemini-embedding-001 完整输出同维，验证本地截断
    preg_match_all('/[a-z0-9]+/', strtolower($t), $m);
    foreach ($m[0] as $w) if (strlen($w) >= 3) $v[crc32($w) % 700] += 1.0;   // 只落在前 700 维，截断到 768 不丢信息
    if (!array_filter($v)) $v[0] = 1.0;
    return $v;
}
$texts = [];
$fail = null;
$embed = function (array $in) use (&$texts, &$fail) {
    if ($fail) return ['ok' => false, 'error_kind' => $fail, 'error' => 'fake'];
    array_push($texts, ...$in);
    return ['ok' => true, 'vectors' => array_map('fakeVec', $in), 'usage' => [], 'elapsed' => 0.01];
};

echo "一、向量工具\n";
$v = recruitVecNormalize([3.0, 4.0]);
ok(abs($v[0] - 0.6) < 1e-9 && abs($v[1] - 0.8) < 1e-9, '归一化 [3,4] → [0.6,0.8]');
$u = recruitVecUnpack(recruitVecPack($v));
ok(count($u) === 2 && abs($u[1] - 0.8) < 1e-6, 'float32 打包往返');
$t = recruitVecTruncate(array_merge([1.0, 1.0], array_fill(0, 10, 5.0)), 2);
ok(count($t) === 2 && abs(recruitVecDot($t, $t) - 1) < 1e-9, '截断后重新归一化');
$w = ['skills' => 0.5, 'industry' => 0.5];
$s = recruitSemScore(['skills' => [1.0, 0.0]], ['skills' => [1.0, 0.0], 'industry' => [0.0, 1.0]], $w);
ok($s['score'] === 1.0 && !isset($s['facets']['industry']), '候选人缺行业维度：不当 0 分，权重让给其它维度');
$s = recruitSemScore(['skills' => [1.0, 0.0], 'industry' => [1.0, 0.0]], ['skills' => [1.0, 0.0], 'industry' => [0.0, 1.0]], $w);
ok($s['score'] === 0.5 && $s['facets'] === ['skills' => 1.0, 'industry' => 0.0], '技能对口、行业不同 → 各维度分开看得到（可跨行业的信号）');
$q = recruitQueryScore([1.0, 0.0], ['skills' => [1.0, 0.0], 'industry' => [0.0, 1.0]], $w);
ok($q['facet'] === 'skills' && $q['score'] > 0.5, '搜索：命中维度 = 最相关那个，单一方面的查询不被其它维度稀释');

echo "\n二、维度文本\n";
$ft = recruitCandidateFacetTexts(['skills' => ['Jaringan'], 'skills_en' => ['Computer Networking'],
    'experience' => [['title' => 'NOC', 'company' => 'PT A', 'description' => 'Zabbix', 'company_industry' => 'datacenter_infra']],
    'industries' => ['telecom'], 'headline' => 'NOC Engineer'], ['highest_edu' => 's1', 'years_exp' => 3]);
ok(strpos($ft['skills'], 'Computer Networking') !== false && strpos($ft['skills'], 'Jaringan') === false, '技能优先用英文规范名（跨语言对得上）');
ok(strpos($ft['industry'], 'data center') !== false && strpos($ft['industry'], 'telecommunications') !== false, '行业代码转成英文说明');
ok(strpos($ft['headline'], 'bachelor') !== false && strpos($ft['headline'], '3 years') !== false, '概要含学历与年限');
$jt = recruitJobFacetTexts(['title' => 'NOC', 'jd_text' => 'x'], [['text' => 'CCNA', 'level' => 'core'], ['text' => 'English', 'level' => 'nice']]);
ok(strpos($jt['skills'], 'Required: CCNA') !== false && strpos($jt['skills'], 'Preferred: English') !== false, '职位技能维度 = core/nice 要求');
ok(recruitCandidateFacetTexts([])['skills'] === '', '空档案 → 空文本（该维度不建向量）');

// ------------------------------------------------------------------ 集成
$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$run = bin2hex(random_bytes(3));
$made = ['proj' => 0, 'jobs' => [], 'cands' => []];
$only = function () use (&$made) { return ['only_candidate_ids' => $made['cands'], 'only_job_ids' => $made['jobs']]; };   // 引用捕获：fn() 会在定义时拷贝空数组
$vecCount = fn(string $tp, int $id) => (int)$pdo->query("SELECT COUNT(*) FROM recruit_vectors WHERE owner_type='$tp' AND owner_id=$id")->fetchColumn();

try {
    echo "\n三、向量化（测试库）\n";
    $pdo->prepare("INSERT INTO recruit_projects (name, kind, status, created_at, updated_at) VALUES (?, 'client', 'open', " . dbNow() . ", " . dbNow() . ")")
        ->execute(["embed-test-$run"]);
    $made['proj'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recruit_jobs (project_id, title, jd_text, status, jd_rev, jd_req_source, jd_requirements_json, created_at, updated_at)
                   VALUES (?, 'NOC Engineer', 'Monitor network with Zabbix in data center', 'open', 1, 'manual', ?, " . dbNow() . ", " . dbNow() . ")")
        ->execute([$made['proj'], json_encode([['id' => 'R1', 'text' => 'Zabbix network monitoring', 'level' => 'core']])]);
    $made['jobs'][] = $jid = (int)$pdo->lastInsertId();
    $profiles = [
        'Budi' => ['headline' => 'NOC Engineer', 'skills' => ['Zabbix', 'network monitoring'], 'skills_en' => ['Zabbix', 'Network Monitoring'],
                   'experience' => [['title' => 'NOC Engineer', 'company' => 'PT DC', 'description' => 'Zabbix network monitoring data center']]],
        'Siti' => ['headline' => 'Chef', 'skills' => ['Cooking'], 'skills_en' => ['Cooking', 'Pastry'],
                   'experience' => [['title' => 'Chef', 'company' => 'Hotel', 'description' => 'Kitchen pastry cooking']]],
    ];
    foreach ($profiles as $n => $p) {
        $pdo->prepare("INSERT INTO recruit_candidates (name, profile_json, profile_rev, latest_title, status, last_received_at, created_at, updated_at)
                       VALUES (?, ?, 1, ?, 'new', " . dbNow() . ", " . dbNow() . ", " . dbNow() . ")")
            ->execute([$n, json_encode($p), $p['headline']]);
        $made['cands'][] = (int)$pdo->lastInsertId();
    }
    [$c1, $c2] = $made['cands'];

    $fail = 'network';
    $r = recruitEmbedBatch($pdo, $embed, $only());
    $vr = (int)$pdo->query("SELECT vec_rev FROM recruit_candidates WHERE id=$c1")->fetchColumn();
    ok($vr === 0 && $vecCount('cand', $c1) === 0, '接口失败 → 不推进 vec_rev，下轮重来');
    $fail = 'rejected';
    ok(recruitEmbedBatch($pdo, $embed, $only())['aborted'] === true, 'key 被拒 → 本轮中止');
    $fail = null;

    $texts = [];
    $r = recruitEmbedBatch($pdo, $embed, $only());
    ok($r['candidates'] === 2 && $r['jobs'] === 1, '2 人 + 1 职位向量化 ');
    ok($vecCount('cand', $c1) === 4 && $vecCount('job', $jid) === 4, '每人/每职位 4 个维度');
    $row = $pdo->query("SELECT dims, LENGTH(vec) lv, LENGTH(vec_s) ls FROM recruit_vectors WHERE owner_type='cand' AND owner_id=$c1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok((int)$row['dims'] === 768 && (int)$row['lv'] === 768 * 4 && (int)$row['ls'] === 256 * 4, '3072 维截断存 768（精排）+ 256（搜索）');
    ok((int)$pdo->query("SELECT vec_rev FROM recruit_candidates WHERE id=$c1")->fetchColumn() === 1, 'vec_rev 追上 profile_rev');

    $texts = [];
    recruitEmbedBatch($pdo, $embed, $only());
    ok($texts === [], '没变化 → 零调用');

    // 只改经历描述：只有 experience 维度重算
    $p = $profiles['Budi']; $p['experience'][0]['description'] .= ' SNMP';
    $pdo->prepare("UPDATE recruit_candidates SET profile_json=?, profile_rev=2 WHERE id=?")->execute([json_encode($p), $c1]);
    $texts = [];
    recruitEmbedBatch($pdo, $embed, $only());
    ok(count($texts) === 1 && strpos($texts[0], 'SNMP') !== false, '只改了经历 → 只重算 experience 一个维度（text_hash 没变的不花钱）');

    echo "\n四、语义分\n";
    $r = recruitSemScoreBatch($pdo, $only());
    ok($r['pairs'] === 2, '2 人 × 1 职位 入表 ');
    $sc = $pdo->query("SELECT candidate_id, score FROM recruit_sem_scores WHERE job_id=$jid")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok((float)$sc[$c1] > (float)$sc[$c2], 'NOC 候选人语义分 > 厨师（' . $sc[$c1] . ' vs ' . $sc[$c2] . '）');
    ok(recruitSemScoreBatch($pdo, $only())['pairs'] === 0, '向量没变 → 不重算');
    $pdo->exec("UPDATE recruit_jobs SET jd_text='Monitor network with Zabbix and Grafana', jd_rev=2 WHERE id=$jid");
    recruitEmbedBatch($pdo, $embed, $only());
    ok(recruitSemScoreBatch($pdo, $only())['pairs'] === 2, '职位改了 → 这个职位的分全部重算');

    echo "\n五、搜索与相似候选人\n";
    $res = recruitSemanticSearch($pdo, 'zabbix network monitoring', $made['cands'], $embed);
    ok($res['ok'] && $res['scores'][$c1]['score'] > $res['scores'][$c2]['score'], '「zabbix network monitoring」→ Budi 排前');
    ok(in_array($res['scores'][$c1]['facet'], ['skills', 'experience'], true), '命中维度是技能/经历');
    $fail = 'network';
    ok(!recruitSemanticSearch($pdo, 'x', $made['cands'], $embed)['ok'], '查询向量化失败 → ok=false（调用方退回关键字搜索）');
    $fail = null;
    // 人才库混合检索（handlers/recruit.php）：名字走关键字排最前，其余按语义相关度
    $pdo->exec("UPDATE recruit_candidates SET search_text=LOWER(name) WHERE id IN ($c1,$c2)");
    $rank = recruitSemanticRank($pdo, ['keyword' => 'zabbix network monitoring'], 'zabbix network monitoring', $embed);
    ok($rank !== null && array_key_first($rank) === $c1 && $rank[$c1]['by'] === 'semantic', '自然语言查询 → Budi 按语义排第一');
    ok(!isset($rank[$c2]), '厨师相关度低于阈值 → 不列出');
    $rank = recruitSemanticRank($pdo, ['keyword' => 'Siti'], 'Siti', $embed);
    ok(array_key_first($rank) === $c2 && $rank[$c2]['by'] === 'keyword', '搜名字 → 关键字命中的排最前');
    ok(recruitSemanticRank($pdo, ['keyword' => 'x y z', 'owner_id' => 99999999], 'x y z', $embed) === null, '筛选后没人有向量 → null（退回关键字）');
    ok(recruitIsExactQuery('0812-3456-789') && recruitIsExactQuery('a@b.com') && recruitIsExactQuery('#12') && !recruitIsExactQuery('NOC engineer'),
       '手机号/邮箱/#编号走关键字，自然语言走语义');
    $sim = recruitSimilarCandidates($pdo, $c1, $made['cands']);
    ok(array_keys($sim) === [$c2], '相似候选人不含本人');
} finally {
    $jids = implode(',', $made['jobs'] ?: [0]);
    $cids = implode(',', $made['cands'] ?: [0]);
    $pdo->exec("DELETE FROM recruit_sem_scores WHERE job_id IN ($jids) OR candidate_id IN ($cids)");
    $pdo->exec("DELETE FROM recruit_vectors WHERE (owner_type='job' AND owner_id IN ($jids)) OR (owner_type='cand' AND owner_id IN ($cids))");
    $pdo->exec("DELETE FROM recruit_candidates WHERE id IN ($cids)");
    $pdo->exec("DELETE FROM recruit_jobs WHERE id IN ($jids)");
    if ($made['proj']) $pdo->exec("DELETE FROM recruit_projects WHERE id={$made['proj']}");
    $pdo->exec("DELETE FROM ai_api_usage WHERE scene='recruit_embed' AND result_summary LIKE '% texts' AND called_at >= " . dbNowOffset('-10 minutes'));
    echo "\n清理完成\n";
}

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
