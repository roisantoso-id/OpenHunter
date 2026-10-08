<?php
/**
 * 提示词自我迭代（recruit_prompts.php）：php tests/recruit_prompts_test.php
 * 测试库集成，整段包在事务里、跑完回滚；模型全是假的（不花钱、不发企微）。
 * 要守住的：内置版本入库幂等、代码升版本入库成草稿；同一问题只记一条累加 hits、人工纠正自动 gold；
 * 评测：更好 → 自动启用并通知、变差 → 不启用并标 rejected、用例不够 → 页面草稿不自动启用；指标算对（F1 / MAE / 编造率）。
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/recruit_prompts.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

echo "一、纯函数\n";
ok(recruitTextSim('至少 3 年签证办理经验', '3 年以上签证办理经验') >= 0.5, '中文近义表述相似度 ≥0.5');
ok(recruitTextSim('Experience with RPTKA', 'experience with rptka!') === 1.0, '大小写 / 标点不影响');
ok(recruitTextSim('Mandarin', 'Driving license') === 0.0, '无关 → 0');
$g = [['text' => 'RPTKA experience', 'level' => 'core'], ['text' => 'Mandarin', 'level' => 'nice']];
$x = recruitReqF1([['text' => 'RPTKA experience', 'level' => 'core'], ['text' => 'Mandarin', 'level' => 'core']], $g);
ok(abs($x['f1'] - 1.0) < 1e-9 && $x['level_hits'] === 1, 'F1=1、必须/加分判对 1 条');
$x = recruitReqF1([['text' => 'RPTKA experience', 'level' => 'core'], ['text' => 'Excel', 'level' => 'nice'], ['text' => 'SIM A', 'level' => 'nice']], $g);
ok(abs($x['f1'] - 0.4) < 1e-9, '对上 1/3、召回 1/2 → F1=0.4');
ok(recruitReqsSame([['text' => 'A  b', 'level' => 'core']], [['text' => 'a b', 'level' => 'core']]) && !recruitReqsSame($g, array_reverse($g)), '要求清单比较（忽略空格大小写、顺序有意义）');
$cmp = recruitEvalCompare(['fail_rate' => 0.2, 'mae' => 1.0], ['fail_rate' => 0.1, 'mae' => 1.05]);
ok($cmp['better'] === ['fail_rate'] && $cmp['worse'] === [], '比较：容差内算持平');
$cmp = recruitEvalCompare(['f1' => 0.8], ['f1' => 0.7]);
ok($cmp['worse'] === ['f1'], 'F1 下降 = 变差');
[$b, $a] = recruitEvidenceHalluc(['matches' => [['requirements' => [
    ['met' => 'yes', 'evidence' => 'handled RPTKA for 30 companies'], ['met' => 'yes', 'evidence' => 'fluent Mandarin'], ['met' => 'no']]]]],
    json_encode(['exp' => 'Handled RPTKA for 30 companies at PT X']));
ok($b === 1 && $a === 2, '证据编造率：2 条声称满足、1 条在档案里找不到');

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!recruitPromptTablesOk($pdo)) { echo "\n测试库没有提示词表：先跑 create_recruit_tables_20260923.php --apply\n"; exit(1); }
$pdo->beginTransaction();
try {
    $pdo->exec("DELETE FROM recruit_prompt_evals"); $pdo->exec("DELETE FROM recruit_ai_feedback"); $pdo->exec("DELETE FROM recruit_prompts");
    recruitPromptCacheReset();

    echo "\n二、版本\n";
    ok(recruitPromptSeed($pdo) === [], '首次入库：内置版本直接 active，不产生草稿');
    ok((int)$pdo->query("SELECT COUNT(*) FROM recruit_prompts WHERE status='active' AND source='builtin'")->fetchColumn() === count(RECRUIT_PROMPT_SCENES), count(RECRUIT_PROMPT_SCENES) . ' 个场景各一个 active');
    ok(recruitPromptSeed($pdo) === [], '重跑幂等');
    ok(recruitPrompt($pdo, 'jd_req')['ver'] === RECRUIT_JDREQ_PROMPT_VER && recruitPrompt($pdo, 'jd_req')['id'] > 0, '生效版本来自库');
    // 模拟「代码升了版本号」：把库里那条改成旧号，内置号就成了新版本
    $pdo->exec("UPDATE recruit_prompts SET ver='r0', body='OLD' WHERE scene='jd_req'");
    recruitPromptCacheReset();
    $d = recruitPromptSeed($pdo);
    ok(count($d) === 1 && $pdo->query("SELECT status FROM recruit_prompts WHERE id={$d[0]}")->fetchColumn() === 'draft', '代码升版本 → 入库成草稿（等评测）');
    ok(recruitPrompt($pdo, 'jd_req')['ver'] === 'r0', '评测前仍用现行版本');
    ok(recruitPromptNextVer($pdo, 'jd_req') === 'r0.1', '页面新版本号 = 现行号.1');
    $pdo->exec("INSERT INTO recruit_prompts (scene, ver, body, status, source) VALUES ('jd_req', 'r0.1', 'x', 'rejected', 'manual')");
    ok(recruitPromptNextVer($pdo, 'jd_req') === 'r0.2', '再下一个 = .2');
    $pdo->exec("DELETE FROM recruit_prompts WHERE ver='r0.1'");

    echo "\n三、反馈\n";
    $mk = fn(string $t) => ['text' => recruitJdReqUserText('Visa Staff', $t)];
    recruitFeedback($pdo, 'jd_req', 'fail', $mk('JD A'), null, null, 'bad', 'recruit_job', 1);
    recruitFeedback($pdo, 'jd_req', 'fail', $mk('JD A'), null, null, 'bad again', 'recruit_job', 1);
    $r = $pdo->query("SELECT COUNT(*) n, MAX(hits) h, MAX(status) s, MAX(prompt_ver) v FROM recruit_ai_feedback")->fetch(PDO::FETCH_ASSOC);
    ok((int)$r['n'] === 1 && (int)$r['h'] === 2, '同一输入（数据标签每次随机）只记一条，hits 累加');
    ok($r['s'] === 'open' && $r['v'] === 'r0', '失败类 = open，记下当时的提示词版本');
    recruitFeedback($pdo, 'jd_req', 'corrected', $mk('JD B'), ['requirements' => []], ['requirements' => $g]);
    ok($pdo->query("SELECT status FROM recruit_ai_feedback WHERE kind='corrected'")->fetchColumn() === 'gold', '人工纠正 → 自动 gold');
    recruitFeedback($pdo, 'match', 'removed', ['text' => 'x'], null, ['max' => 3]);
    ok($pdo->query("SELECT status FROM recruit_ai_feedback WHERE kind='removed'")->fetchColumn() === 'open', '移除匹配不自动当标准答案（原因可能与档案无关）');
    $pdo->exec("DELETE FROM recruit_ai_feedback");

    echo "\n四、评测 · 自动启用\n";
    // 10 条 gold：假模型见到提示词里有 GOOD 就照标准答案拆，否则拆得一塌糊涂
    for ($i = 1; $i <= 10; $i++) {
        $want = [['text' => "Requirement $i alpha", 'level' => 'core'], ['text' => "Skill $i beta", 'level' => 'nice']];
        recruitFeedback($pdo, 'jd_req', 'corrected', ['text' => recruitJdReqUserText('T', "JD $i") . "\nWANT:" . json_encode($want)],
            ['requirements' => []], ['requirements' => $want]);
    }
    $fake = function (array $req) {
        $out = [];
        foreach ($req as $k => $r) {
            preg_match('/WANT:(.*)$/s', $r['content'][0]['text'], $m);
            $want = json_decode($m[1], true);
            $out[$k] = str_contains($r['system'], 'GOOD') ? ['ok' => true, 'data' => ['requirements' => $want]]
                     : (str_contains($r['system'], 'BROKEN') ? ['ok' => false, 'error_kind' => 'bad_output', 'error' => 'x']
                     : ['ok' => true, 'data' => ['requirements' => [['text' => 'Something unrelated', 'level' => 'nice']]]]);
        }
        return $out;
    };
    $pdo->prepare("INSERT INTO recruit_prompts (scene, ver, body, status, source) VALUES ('jd_req', 'r0.1', 'GOOD prompt', 'draft', 'manual')")->execute();
    $good = (int)$pdo->lastInsertId();
    $sent = [];
    $res = recruitPromptEvaluate($pdo, $good, $fake, function ($p) use (&$sent) { $sent[] = $p; });
    ok($res['decision'] === 'promoted' && $res['promoted'], '候选版本 F1 更高 → 自动启用（' . $res['reason'] . '）');
    ok(recruitPrompt($pdo, 'jd_req')['ver'] === 'r0.1', '生效版本已切换');
    ok((int)$pdo->query("SELECT COUNT(*) FROM recruit_prompts WHERE scene='jd_req' AND status='active'")->fetchColumn() === 1, '同场景只有一个 active');
    ok(count($sent) === 1 && $sent[0]['from'] === 'r0' && $sent[0]['to'] === 'r0.1' && str_contains($sent[0]['summary'], 'f1'), '发了一条通知，带指标变化');
    ok($res['candidate']['metrics']['f1'] == 1.0 && $res['active']['metrics']['f1'] == 0.0, '指标：F1 1.0 vs 0.0');

    $pdo->prepare("INSERT INTO recruit_prompts (scene, ver, body, status, source) VALUES ('jd_req', 'r0.2', 'BROKEN prompt', 'draft', 'manual')")->execute();
    $bad = (int)$pdo->lastInsertId();
    $sent = [];
    $res = recruitPromptEvaluate($pdo, $bad, $fake, function ($p) use (&$sent) { $sent[] = $p; });
    ok($res['decision'] === 'kept' && !$sent && recruitPrompt($pdo, 'jd_req')['ver'] === 'r0.1', '候选版本失败率更高 → 不启用、不通知');
    ok($pdo->query("SELECT status FROM recruit_prompts WHERE id=$bad")->fetchColumn() === 'rejected', '被否决的草稿标 rejected');
    ok(recruitPromptActivate($pdo, $d[0])['to'] === RECRUIT_JDREQ_PROMPT_VER && recruitPrompt($pdo, 'jd_req')['id'] === $d[0], '手动启用 / 回退任一版本');

    echo "\n五、用例不够\n";
    recruitFeedback($pdo, 'query', 'fail', ['text' => 'q1', 'q' => 'q1'], null, null);
    $pdo->prepare("INSERT INTO recruit_prompts (scene, ver, body, status, source) VALUES ('query', 'q1.1', 'GOOD', 'draft', 'manual')")->execute();
    $qid = (int)$pdo->lastInsertId();
    $okQ = fn(array $req) => array_map(fn() => ['ok' => true, 'data' => ['conditions' => [['type' => 'skill', 'label' => ['zh' => 'a', 'en' => 'a', 'id' => 'a'], 'keywords' => ['a'], 'must' => true]], 'filters' => [], 'semantic_query' => 'a']], $req);
    $res = recruitPromptEvaluate($pdo, $qid, $okQ, fn() => null);
    ok($res['decision'] === 'insufficient' && recruitPrompt($pdo, 'query')['ver'] === RECRUIT_QUERY_PROMPT_VER, '页面写的草稿、用例 < ' . RECRUIT_EVAL_MIN_CASES . ' 条 → 不自动启用，等手动');

    echo "\n六、匹配指标\n";
    $spec = ['J7' => ['jid' => 7, 'reqs' => [['id' => 'R1', 'text' => 'RPTKA', 'level' => 'core']], 'rev' => 1]];
    $profile = json_encode(['exp' => 'Handled RPTKA for 30 companies']);
    $cases = [['id' => 1, 'kind' => 'score_corrected', 'gold' => true, 'human' => ['score' => 2.0],
               'input' => ['text' => 'x', 'ctx' => ['spec' => $spec, 'profile' => $profile, 'job_key' => 'J7']]],
              ['id' => 2, 'kind' => 'removed', 'gold' => true, 'human' => ['max' => 3],
               'input' => ['text' => 'y', 'ctx' => ['spec' => $spec, 'profile' => $profile, 'job_key' => 'J7']]]];
    $mk = fn(float $s, string $ev) => ['ok' => true, 'data' => ['matches' => [['job_key' => 'J7', 'score' => $s,
        'requirements' => [['id' => 'R1', 'met' => 'yes', 'evidence' => $ev]], 'reason' => ['zh' => 'a', 'en' => 'a', 'id' => 'a'], 'gaps' => [], 'transferable' => false]]]];
    $r = recruitEvalRun('match', $cases, 'sys', fn($req) => [1 => $mk(4.0, 'Handled RPTKA for 30 companies'), 2 => $mk(4.0, 'invented fact here')]);
    // 第 2 条证据是编的 → 校验时核心要求降为 unknown、分数封顶 3.5（线上存的就是校验后的分）→ 超出上限 0.5
    ok($r['gold'] === 2 && abs($r['metrics']['mae'] - 1.25) < 1e-9, 'MAE：|4-2|=2 与 超出上限 3.5-3=0.5 → 1.25（按校验后的分算）');
    ok(abs($r['metrics']['halluc_rate'] - 0.5) < 1e-9 && $r['metrics']['fail_rate'] == 0, '编造率 1/2、失败率 0');
} finally {
    $pdo->rollBack();
    recruitPromptCacheReset();
}

echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
