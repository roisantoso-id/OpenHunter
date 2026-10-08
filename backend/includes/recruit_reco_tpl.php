<?php
/**
 * OpenHunter · 推荐信模板（2026-09-24「推荐信要有我们固定的格式、固定的模板，AI 预填，HR 能改」）
 *
 * 一个模板 = 标题 + 按顺序的块。四种块：
 *   fixed   固定文字：公司的固定段落（保密声明、公司介绍、条款…），可带变量 {{client}} {{position}} {{candidate}}
 *           {{candidate_code}} {{consultant}} {{consultant_email}} {{consultant_phone}} {{date}} {{company}}。
 *           单封信可以微调（content.fixed[key]），模板本身不变，可一键恢复模板原文
 *   ai      AI 段落：取 AI 生成的推荐信对应部分（slot：subject 标题 / greeting 称呼 / intro 推荐概述 / match 要求对照表 /
 *           paragraphs 补充段落 / availability 到岗与期望 / closing 结束语）。AI 生成不用改，模板只决定放哪、放不放
 *   manual  手填项：AI 不能编的内容（期望薪资、面谈印象…），招聘专员填（content.manual[key]），required 的没填不许导出
 *   field   档案字段：取推荐简历里的 education / languages / certificates / skills，或 signature 顾问落款
 * 抬头（logo + 公司名）、「致 / 推荐职位 / 候选人编号 / 日期」一行是版式，所有模板都有，不做成块。
 *
 * 模板存 recruit_reco_templates（按语言）；表里某语言没有默认模板时用内置默认（与模板系统上线前的推荐信版式一模一样）。
 * 每封信生成 / 换模板时把模板**快照**进 content.tpl：日后改模板不影响已写好的信（§6.7.1「读快照」同理）。
 * ⛔ 前端 pages/Recruit/components/RecoDoc.tsx 按同一套规则渲染（预览 / PDF），Word 在 recruit_docx.php，改规则三处一起改。
 */

require_once __DIR__ . '/recruit_docx.php';   // recruitRecoLabels：默认模板的标题 / 保密声明用同一份文案

const RECRUIT_TPL_AI_SLOTS = ['subject', 'greeting', 'intro', 'match', 'paragraphs', 'availability', 'closing'];
const RECRUIT_TPL_FIELDS = ['education', 'languages', 'certificates', 'skills', 'signature'];
const RECRUIT_TPL_VARS = ['client', 'position', 'candidate', 'candidate_code', 'consultant', 'consultant_email', 'consultant_phone', 'date', 'company'];
const RECRUIT_TPL_MAX_BLOCKS = 30;

/** 内置默认模板：与模板系统上线前的推荐信完全一致（AI 各段 + 顾问落款 + 保密声明），另加一个选填的「顾问面谈评价」 */
function recruitRecoDefaultTemplate(string $lang): array {
    $lb = recruitRecoLabels($lang);
    $interview = ['zh' => '顾问面谈评价', 'en' => 'Consultant\'s Interview Notes', 'id' => 'Catatan Wawancara Konsultan'][$lang] ?? '顾问面谈评价';
    $hint = ['zh' => '面谈 / 电话沟通后的印象（沟通能力、求职动机、稳定性等），AI 不会填写', 'en' => 'Impressions from the interview / call (communication, motivation, stability); AI never fills this',
             'id' => 'Kesan dari wawancara / telepon (komunikasi, motivasi, stabilitas); AI tidak mengisi ini'][$lang] ?? '';
    return [
        'id' => 0, 'builtin' => true, 'lang' => $lang, 'name' => ['zh' => '系统默认', 'en' => 'System default', 'id' => 'Bawaan sistem'][$lang] ?? '系统默认',
        'title' => $lb['letter_title'],
        'blocks' => [
            ['key' => 'subject', 'type' => 'ai', 'slot' => 'subject'],
            ['key' => 'greeting', 'type' => 'ai', 'slot' => 'greeting'],
            ['key' => 'intro', 'type' => 'ai', 'slot' => 'intro'],
            ['key' => 'match', 'type' => 'ai', 'slot' => 'match', 'title' => $lb['match']],
            ['key' => 'paragraphs', 'type' => 'ai', 'slot' => 'paragraphs'],
            ['key' => 'availability', 'type' => 'ai', 'slot' => 'availability', 'title' => $lb['availability']],
            ['key' => 'interview', 'type' => 'manual', 'title' => $interview, 'hint' => $hint, 'required' => false],
            ['key' => 'closing', 'type' => 'ai', 'slot' => 'closing'],
            ['key' => 'signature', 'type' => 'field', 'source' => 'signature'],
            ['key' => 'confidential', 'type' => 'fixed', 'text' => $lb['confidential'], 'small' => true],
        ],
    ];
}

/** 块清洗（设置页保存的、信里快照回传的都走这里）：类型 / slot / source 白名单，key 唯一，文字限长 */
function recruitNormalizeTplBlocks($blocks): array {
    $out = []; $keys = [];
    foreach (array_slice(is_array($blocks) ? array_values($blocks) : [], 0, RECRUIT_TPL_MAX_BLOCKS) as $i => $b) {
        if (!is_array($b)) continue;
        $type = (string)($b['type'] ?? '');
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($b['key'] ?? ''))) ?: "b$i";
        while (isset($keys[$key])) $key .= '_' . $i;
        $row = ['key' => $key, 'type' => $type];
        $title = mb_substr(trim((string)($b['title'] ?? '')), 0, 100);
        if ($title !== '') $row['title'] = $title;
        if ($type === 'fixed') {
            $row['text'] = mb_substr(trim((string)($b['text'] ?? '')), 0, 3000);
            if ($row['text'] === '') continue;
            if (!empty($b['small'])) $row['small'] = true;
        } elseif ($type === 'ai') {
            if (!in_array($b['slot'] ?? '', RECRUIT_TPL_AI_SLOTS, true)) continue;
            $row['slot'] = $b['slot'];
        } elseif ($type === 'manual') {
            if ($title === '') continue;
            $row['hint'] = mb_substr(trim((string)($b['hint'] ?? '')), 0, 300);
            $row['required'] = !empty($b['required']);
        } elseif ($type === 'field') {
            if (!in_array($b['source'] ?? '', RECRUIT_TPL_FIELDS, true)) continue;
            $row['source'] = $b['source'];
        } else continue;
        $keys[$key] = true;
        $out[] = $row;
    }
    return $out;
}

/** 模板快照（存进信里的那份）：只留渲染要用的 */
function recruitTplSnapshot(array $t): array {
    return ['id' => (int)($t['id'] ?? 0), 'name' => (string)($t['name'] ?? ''), 'title' => (string)($t['title'] ?? ''),
            'blocks' => recruitNormalizeTplBlocks($t['blocks'] ?? [])];
}

function recruitTplRow(array $r): array {
    return ['id' => (int)$r['id'], 'builtin' => false, 'lang' => $r['lang'], 'name' => $r['name'], 'title' => $r['title'],
            'blocks' => json_decode((string)$r['blocks_json'], true) ?: [], 'is_default' => (int)$r['is_default'] === 1,
            'status' => $r['status'], 'updated_at' => $r['updated_at'], 'updated_by_name' => $r['updated_by_name'] ?? ''];
}

/** 某语言可用的模板：表里在用的 + 内置默认。表没建（脚本没重跑）时只有内置默认 */
function recruitRecoTemplates(PDO $pdo, string $lang, bool $withArchived = false): array {
    $list = [];
    try {
        $st = $pdo->prepare("SELECT * FROM recruit_reco_templates WHERE lang=?" . ($withArchived ? '' : " AND status='active'") . " ORDER BY is_default DESC, id");
        $st->execute([$lang]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $list[] = recruitTplRow($r);
    } catch (PDOException $e) {}
    $builtin = recruitRecoDefaultTemplate($lang);
    $builtin['is_default'] = !array_filter($list, fn($t) => $t['is_default'] && $t['status'] === 'active');
    $builtin['status'] = 'active';
    $list[] = $builtin;
    return $list;
}

/** 该语言的默认模板（表里设了默认的，否则内置） */
function recruitRecoDefaultTpl(PDO $pdo, string $lang): array {
    foreach (recruitRecoTemplates($pdo, $lang) as $t) if ($t['is_default']) return $t;
    return recruitRecoDefaultTemplate($lang);
}

/** 按 id 取模板（0 = 内置默认）；不存在 / 语言不对 → null */
function recruitRecoTemplateById(PDO $pdo, int $id, string $lang): ?array {
    if ($id === 0) return recruitRecoDefaultTemplate($lang);
    foreach (recruitRecoTemplates($pdo, $lang) as $t) if ((int)$t['id'] === $id) return $t;
    return null;
}

/** 变量值：来自 recruitRecoGet 的 meta（职位、客户、候选人、编号、顾问）+ 日期 */
function recruitTplVars(array $meta, string $lang, string $date): array {
    $lb = recruitRecoLabels($lang);
    return ['client' => (string)($meta['client'] ?? ''), 'position' => (string)($meta['position'] ?? ''),
            'candidate' => (string)($meta['candidate_name'] ?? ''), 'candidate_code' => (string)($meta['candidate_code'] ?? ''),
            'consultant' => (string)($meta['recruiter_name'] ?? ''), 'consultant_email' => (string)($meta['recruiter_email'] ?? ''),
            'consultant_phone' => (string)($meta['recruiter_phone'] ?? ''), 'date' => $date, 'company' => $lb['company']];
}

/** {{var}} 替换；不认识的变量原样留着（页面能看出来填错了） */
function recruitTplFill(string $text, array $vars): string {
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn($m) => array_key_exists($m[1], $vars) ? $vars[$m[1]] : $m[0], $text);
}

/** 必填手填项里还空着的（导出前拦；返回块标题） */
function recruitRecoMissingManual(array $content): array {
    $miss = [];
    foreach ($content['tpl']['blocks'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'manual' && !empty($b['required']) && trim((string)($content['manual'][$b['key']] ?? '')) === '') $miss[] = (string)($b['title'] ?? $b['key']);
    }
    return $miss;
}

/** 信里的模板部分清洗：tpl 快照、手填值、固定段落的单封微调（只留模板里有的 key） */
function recruitNormalizeRecoTplPart(array $c): array {
    $tpl = is_array($c['tpl'] ?? null) ? recruitTplSnapshot($c['tpl']) : null;
    $keys = $tpl ? array_column($tpl['blocks'], 'type', 'key') : [];
    $pick = function ($m, string $type, int $max) use ($keys) {
        $out = [];
        foreach (is_array($m) ? $m : [] as $k => $v) if (($keys[$k] ?? '') === $type && is_scalar($v)) $out[$k] = mb_substr(trim((string)$v), 0, $max);
        return $out;
    };
    return ['tpl' => $tpl, 'manual' => $pick($c['manual'] ?? [], 'manual', 3000), 'fixed' => $pick($c['fixed'] ?? [], 'fixed', 3000)];
}

/**
 * 推荐信 → 渲染条目（Word 导出用；网页预览 / PDF 的 RecoDoc.tsx letterItems 同一套规则）。
 * 条目：title 大标题 / to 致 / meta 职位·编号·日期 / heading 小节标题 / subject 主题行 / para 段落 / small 小字 /
 *       match 要求对照表 / lines 多行（落款）。手填项空着的不出（必填的导出前已拦）。
 */
function recruitRecoLetterItems(array $c, array $meta, string $lang, string $date): array {
    $lb = recruitRecoLabels($lang);
    $tpl = (is_array($c['tpl'] ?? null) && !empty($c['tpl']['blocks'])) ? $c['tpl'] : recruitTplSnapshot(recruitRecoDefaultTemplate($lang));
    $l = $c['letter'] ?? []; $r = $c['resume'] ?? [];
    $vars = recruitTplVars(['client' => $l['to'] ?? ($meta['client'] ?? '')] + $meta, $lang, $date);
    $fill = fn(string $s) => recruitTplFill($s, $vars);
    $items = [
        ['kind' => 'title', 'text' => $fill((string)($tpl['title'] ?: $lb['letter_title']))],
        ['kind' => 'to', 'text' => $lb['to'] . '：' . ($l['to'] ?? '')],
        ['kind' => 'meta', 'text' => $lb['position'] . '：' . ($meta['position'] ?? '') . '    ' . $lb['ref'] . '：' . ($meta['candidate_code'] ?? '') . '    ' . $lb['date'] . '：' . $date],
    ];
    foreach ($tpl['blocks'] as $b) {
        $head = isset($b['title']) && $b['title'] !== '' ? ['kind' => 'heading', 'text' => $fill($b['title'])] : null;
        $add = function (array $body) use (&$items, $head) { if (!$body) return; if ($head) $items[] = $head; foreach ($body as $x) $items[] = $x; };
        switch ($b['type']) {
            case 'fixed':
                $txt = $fill((string)($c['fixed'][$b['key']] ?? $b['text']));
                $add($txt !== '' ? [['kind' => !empty($b['small']) ? 'small' : 'para', 'text' => $txt]] : []);
                break;
            case 'manual':
                $v = trim((string)($c['manual'][$b['key']] ?? ''));
                $add($v !== '' ? [['kind' => 'para', 'text' => $v]] : []);
                break;
            case 'ai':
                $slot = $b['slot'];
                if ($slot === 'match') {
                    $rows = array_map(fn($m) => [$m['requirement'] ?? '', $lb['met'][$m['met'] ?? 'unknown'] ?? ($m['met'] ?? ''), $m['evidence'] ?? '', $m['met'] ?? 'unknown'], $l['match'] ?? []);
                    $add($rows ? [['kind' => 'match', 'head' => [$lb['req'], $lb['result'], $lb['evidence']], 'rows' => $rows]] : []);
                } elseif ($slot === 'paragraphs') {
                    $add(array_map(fn($p) => ['kind' => 'para', 'text' => $p], array_values(array_filter($l['paragraphs'] ?? [], fn($p) => trim((string)$p) !== ''))));
                } else {
                    $v = trim((string)($l[$slot] ?? ''));
                    $add($v !== '' ? [['kind' => $slot === 'subject' ? 'subject' : 'para', 'text' => $v]] : []);
                }
                break;
            case 'field':
                $src = $b['source'];
                if ($src === 'signature') {
                    $add([['kind' => 'lines', 'lines' => array_values(array_filter([$lb['consultant'] . '：' . ($meta['recruiter_name'] ?? ''),
                        $meta['recruiter_email'] ?? '', $meta['recruiter_phone'] ?? '', $lb['company']]))]]);
                } elseif ($src === 'education') {
                    $add(array_map(fn($e) => ['kind' => 'para', 'text' => implode(' · ', array_filter([$e['school'] ?? '', $e['degree'] ?? '', $e['major'] ?? '', $e['period'] ?? '']))], $r['education'] ?? []));
                } else {
                    $v = implode('  ·  ', $r[$src] ?? []);
                    $add($v !== '' ? [['kind' => 'para', 'text' => $v]] : []);
                }
                break;
        }
    }
    return $items;
}
