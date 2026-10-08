<?php
/**
 * 推荐材料测试：输出清洗 + worker 逻辑（假 LLM，测试库，自清理）+ Word 导出。
 *   php tests/recruit_reco_test.php
 * 要守住的：只发事实不发联系方式；抬头「致」按 PT/CV 规则取；LLM 失败标 failed 不留半截；docx 是合法包且匹配点加了底纹。
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/openai_vision.php';
require_once $root . '/includes/recruit_reco.php';
require_once $root . '/includes/recruit_docx.php';

$fails = 0;
function ok($c, string $n) { global $fails; echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $fails++; }

echo "一、输出清洗\n";
ok(!recruitValidateReco(['x' => 1])['ok'], 'resume 与 letter 都缺 → 失败');
$v = recruitValidateReco(['resume' => ['name' => 'Budi', 'skills' => 'Zabbix, MikroTik', 'experience' => [['title' => 'NOC', 'bullets' => '监控; 排障']]],
                          'letter' => ['match' => [['requirement' => 'S1', 'met' => 'maybe']], 'paragraphs' => 'P1']]);
ok($v['ok'] && $v['data']['resume']['skills'] === ['Zabbix', 'MikroTik'] && $v['data']['resume']['experience'][0]['bullets'] === ['监控', '排障'], '字符串写的列表被拆开');
ok($v['data']['letter']['match'][0]['met'] === 'unknown', '非法 met → unknown');
ok(recruitRecoAddressee(['customer_name' => 'PT Maju Jaya', 'group_name' => '[ACME11]群']) === 'PT Maju Jaya', '注册名是 PT → 用注册名');
ok(recruitRecoAddressee(['customer_name' => '张总', 'group_name' => '[ACME-11] 合作群']) === '合作群', '注册名不是 PT/CV → 用群名，去掉内部编号前缀');
$prof = recruitRecoProfile(['person' => ['full_name' => 'Budi', 'gender' => 'male', 'birth_date' => '1990'], 'contacts' => ['phones' => ['0812']],
                            'skills' => ['Zabbix']], ['name' => 'Budi', 'years_exp' => 5]);
ok(!isset($prof['contacts']) && !isset($prof['gender']) && !isset($prof['birth_date']) && $prof['skills'] === ['Zabbix'], '发给模型的档案不含联系方式、性别、生日');
ok(strpos(recruitRecoSystemPrompt('id'), 'Bahasa Indonesia') !== false, '按目标语言出提示词');

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$made = ['proj' => 0, 'job' => 0, 'cand' => 0, 'reco' => []];
try {
    echo "\n二、worker（假 LLM）\n";
    $pdo->prepare("INSERT INTO recruit_projects (name, kind, status, created_at, updated_at) VALUES ('reco-test', 'client', 'open', " . dbNow() . ", " . dbNow() . ")")->execute();
    $made['proj'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recruit_jobs (project_id, title, jd_text, status, jd_rev, jd_req_source, jd_requirements_json, created_at, updated_at)
                   VALUES (?, 'NOC Engineer', 'x', 'open', 3, 'manual', ?, " . dbNow() . ", " . dbNow() . ")")
        ->execute([$made['proj'], json_encode([['id' => 'R1', 'text' => 'Zabbix', 'level' => 'core']])]);
    $made['job'] = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO recruit_candidates (name, phone_display, profile_json, profile_rev, status, created_at, updated_at) VALUES ('Budi', '081234567890', ?, 2, 'new', " . dbNow() . ", " . dbNow() . ")")
        ->execute([json_encode(['person' => ['full_name' => 'Budi'], 'contacts' => ['phones' => ['081234567890']], 'skills' => ['Zabbix']])]);
    $made['cand'] = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO recruit_recommendations (candidate_id, job_id, lang, status, created_at, updated_at) VALUES (?, ?, ?, 'generating', " . dbNow() . ", " . dbNow() . ")");
    $ins->execute([$made['cand'], $made['job'], 'zh']); $made['reco'][] = $ok = (int)$pdo->lastInsertId();
    $ins->execute([$made['cand'], $made['job'], 'en']); $made['reco'][] = $bad = (int)$pdo->lastInsertId();

    $seen = '';
    $good = function ($sys, $content) use (&$seen) {
        $seen = $content[0]['text'];
        return ['ok' => true, 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 800, 'total_tokens' => 1800], 'elapsed' => 1.0, 'data' => [
            'resume' => ['name' => 'Budi', 'summary' => '熟悉 **Zabbix**', 'highlights' => [['title' => 'Zabbix', 'text' => '**3 年 Zabbix**']]],
            'letter' => ['subject' => '推荐 Budi', 'match' => [['requirement' => 'Zabbix', 'met' => 'yes', 'evidence' => '**3 年**']], 'paragraphs' => ['理由']]]];
    };
    ok(recruitRecoRun($pdo, $ok, $good) === 'ready', '生成成功 → ready');
    ok(strpos($seen, '081234567890') === false, '请求里没有候选人电话');
    $row = $pdo->query("SELECT * FROM recruit_recommendations WHERE id=$ok")->fetch(PDO::FETCH_ASSOC);
    $c = json_decode($row['content_json'], true);
    ok((int)$row['cand_rev'] === 2 && (int)$row['job_rev'] === 3, '记下生成时的档案/JD 版本（之后变了页面提示重新生成）');
    ok($c['letter']['to'] === '', '「致」由系统按客户填（本例没挂客户 → 空），不用模型写的');
    ok(recruitRecoRun($pdo, $ok, $good) === 'ready' && true, '非 generating 状态不重复跑');
    ok(recruitRecoRun($pdo, $bad, fn() => ['ok' => false, 'error_kind' => 'bad_output', 'error' => 'x']) === 'failed', 'LLM 失败 → failed');
    ok($pdo->query("SELECT content_json FROM recruit_recommendations WHERE id=$bad")->fetchColumn() === null, '失败不留半截内容');

    echo "\n三、Word 导出\n";
    $bin = recruitRecoDocx('letter', $c, ['lang' => 'zh', 'position' => 'NOC Engineer', 'recruiter_name' => 'alice']);
    $tmp = tempnam(sys_get_temp_dir(), 'd'); file_put_contents($tmp, $bin);
    $z = new ZipArchive(); $z->open($tmp);
    $xml = (string)$z->getFromName('word/document.xml');
    ok(strpos($xml, 'w:fill="' . RECRUIT_MARK_FILL . '"') !== false && strpos($xml, '**') === false, '匹配点加底纹，** 标记不外露');
    ok(@simplexml_load_string($xml) !== false, 'document.xml 是合法 XML');
    $z->close(); @unlink($tmp);
} finally {
    if ($made['reco']) $pdo->exec("DELETE FROM recruit_recommendations WHERE id IN (" . implode(',', $made['reco']) . ")");
    if ($made['cand']) $pdo->exec("DELETE FROM recruit_candidates WHERE id={$made['cand']}");
    if ($made['job']) $pdo->exec("DELETE FROM recruit_jobs WHERE id={$made['job']}");
    if ($made['proj']) $pdo->exec("DELETE FROM recruit_projects WHERE id={$made['proj']}");
    $pdo->exec("DELETE FROM ai_api_usage WHERE biz_type='recruit_reco' AND biz_id IN (" . implode(',', $made['reco'] ?: [0]) . ")");
    echo "\n清理完成\n";
}
echo "\n" . ($fails === 0 ? "全部通过\n" : "失败 $fails 项\n");
exit($fails === 0 ? 0 : 1);
