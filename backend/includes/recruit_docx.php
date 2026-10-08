<?php
/**
 * 推荐材料排版：固定文案（按**文档语言**，不按界面语言——发给印尼客户的中文界面用户也要出印尼文信）
 * + Word 导出（纯 PHP ZipArchive 拼 docx，不引入任何依赖；生产机禁止装软件）。
 *
 * ⛔ recruitRecoLabels() 同时下发给前端预览用（recruitRecoGet 返回 labels），网页预览、PDF、Word
 *    三处的抬头/标题/判定文字只有这一份，改这里三处一起变（§6.7.3 同一份内容一份实现）。
 * 匹配点：正文里 **…** 包起来的片段 → Word 里加粗 + 品牌蓝 + 浅蓝底纹；网页/PDF 同色高亮。
 */

const RECRUIT_BRAND_HEX = '1A2AD6';
const RECRUIT_MARK_FILL = 'E6EBFF';

/**
 * 推荐材料抬头上的公司信息，环境变量配置（backend/.env）：
 *   OPENHUNTER_BRAND_NAME       公司名（默认 OpenHunter）
 *   OPENHUNTER_BRAND_NAME_EN    中文抬头下面的英文/法定名称（可空）
 *   OPENHUNTER_BRAND_SLOGAN     抬头标语（可空）
 *   OPENHUNTER_BRAND_LOGO       logo 图片路径（PNG，可空）
 */
function recruitBrand(): array {
    $env = fn(string $k, string $d = '') => function_exists('ohEnv') ? ohEnv($k, $d) : ((string)getenv($k) ?: $d);
    return ['name' => $env('OPENHUNTER_BRAND_NAME', 'OpenHunter'), 'name_en' => $env('OPENHUNTER_BRAND_NAME_EN'),
            'slogan' => $env('OPENHUNTER_BRAND_SLOGAN'), 'logo' => $env('OPENHUNTER_BRAND_LOGO')];
}

function recruitRecoLabels(string $lang): array {
    $br = recruitBrand();
    $L = [
        'zh' => ['company' => $br['name'], 'company_en' => $br['name_en'], 'slogan' => $br['slogan'],
                 'letter_title' => '人才推荐信', 'resume_title' => '候选人推荐简历', 'to' => '致', 'date' => '日期', 'position' => '推荐职位', 'ref' => '候选人编号',
                 'match' => '职位要求对照', 'req' => '职位要求', 'evidence' => '依据', 'result' => '判定',
                 'met' => ['yes' => '符合', 'partial' => '部分符合', 'no' => '不符合', 'unknown' => '待面试确认'],
                 'summary' => '个人概述', 'highlights' => '岗位匹配亮点', 'experience' => '工作经历', 'projects' => '项目经历',
                 'education' => '教育背景', 'skills' => '技能', 'certs' => '证书', 'langs' => '语言', 'availability' => '到岗与期望',
                 'consultant' => '推荐顾问', 'legend' => '蓝色标记为与职位要求对应的匹配点',
                 'confidential' => "本材料由{$br['name']}提供，仅供贵司本次招聘使用，请勿外传。"],
        'en' => ['company' => $br['name_en'] ?: $br['name'], 'company_en' => '', 'slogan' => $br['slogan'],
                 'letter_title' => 'Candidate Recommendation', 'resume_title' => 'Candidate Profile', 'to' => 'To', 'date' => 'Date', 'position' => 'Position', 'ref' => 'Candidate Ref.',
                 'match' => 'Requirement Match', 'req' => 'Requirement', 'evidence' => 'Evidence', 'result' => 'Result',
                 'met' => ['yes' => 'Meets', 'partial' => 'Partly meets', 'no' => 'Does not meet', 'unknown' => 'To confirm at interview'],
                 'summary' => 'Summary', 'highlights' => 'Key Match Highlights', 'experience' => 'Work Experience', 'projects' => 'Projects',
                 'education' => 'Education', 'skills' => 'Skills', 'certs' => 'Certifications', 'langs' => 'Languages', 'availability' => 'Availability & Expectations',
                 'consultant' => 'Consultant', 'legend' => 'Highlighted in blue: facts matching the job requirements',
                 'confidential' => 'Provided by ' . ($br['name_en'] ?: $br['name']) . ' for this recruitment only. Please do not distribute.'],
        'id' => ['company' => $br['name_en'] ?: $br['name'], 'company_en' => '', 'slogan' => $br['slogan'],
                 'letter_title' => 'Surat Rekomendasi Kandidat', 'resume_title' => 'Profil Kandidat', 'to' => 'Kepada', 'date' => 'Tanggal', 'position' => 'Posisi', 'ref' => 'No. Kandidat',
                 'match' => 'Kesesuaian Persyaratan', 'req' => 'Persyaratan', 'evidence' => 'Bukti', 'result' => 'Hasil',
                 'met' => ['yes' => 'Sesuai', 'partial' => 'Sebagian sesuai', 'no' => 'Tidak sesuai', 'unknown' => 'Dikonfirmasi saat wawancara'],
                 'summary' => 'Ringkasan', 'highlights' => 'Poin Kesesuaian Utama', 'experience' => 'Pengalaman Kerja', 'projects' => 'Proyek',
                 'education' => 'Pendidikan', 'skills' => 'Keahlian', 'certs' => 'Sertifikasi', 'langs' => 'Bahasa', 'availability' => 'Ketersediaan & Harapan',
                 'consultant' => 'Konsultan', 'legend' => 'Ditandai biru: fakta yang sesuai dengan persyaratan posisi',
                 'confidential' => 'Disediakan oleh ' . ($br['name_en'] ?: $br['name']) . ' khusus untuk rekrutmen ini. Mohon tidak disebarluaskan.'],
    ];
    return $L[$lang] ?? $L['zh'];
}

// ---------------------------------------------------------------- docx 底层

function recruitDocxEsc(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

/** 一段文字 → runs；**…** 片段加粗 + 品牌蓝 + 底纹 */
function recruitDocxRuns(string $text, array $o = []): string {
    $sz = (int)($o['size'] ?? 20);   // 半磅：20 = 10pt
    $base = '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:eastAsia="Microsoft YaHei"/>'
          . (!empty($o['bold']) ? '<w:b/>' : '') . (!empty($o['color']) ? '<w:color w:val="' . $o['color'] . '"/>' : '')
          . "<w:sz w:val=\"$sz\"/><w:szCs w:val=\"$sz\"/>";
    $out = '';
    foreach (preg_split('/(\*\*.+?\*\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $seg) {
        $mark = preg_match('/^\*\*(.+)\*\*$/su', $seg, $m);
        $t = $mark ? $m[1] : $seg;
        $rpr = $mark ? $base . '<w:b/><w:color w:val="' . RECRUIT_BRAND_HEX . '"/><w:shd w:val="clear" w:color="auto" w:fill="' . RECRUIT_MARK_FILL . '"/>' : $base;
        $out .= '<w:r><w:rPr>' . $rpr . '</w:rPr><w:t xml:space="preserve">' . recruitDocxEsc($t) . '</w:t></w:r>';
    }
    return $out;
}

function recruitDocxP(string $text, array $o = []): string {
    $ppr = '<w:spacing w:before="' . (int)($o['before'] ?? 0) . '" w:after="' . (int)($o['after'] ?? 80) . '"/>'
         . (!empty($o['align']) ? '<w:jc w:val="' . $o['align'] . '"/>' : '')
         . (!empty($o['bullet']) ? '<w:ind w:left="360" w:hanging="220"/>' : '')
         . (!empty($o['rule']) ? '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="2" w:color="' . RECRUIT_BRAND_HEX . '"/></w:pBdr>' : '');
    return '<w:p><w:pPr>' . $ppr . '</w:pPr>' . recruitDocxRuns((!empty($o['bullet']) ? '• ' : '') . $text, $o) . '</w:p>';
}

function recruitDocxH(string $text): string {
    return recruitDocxP($text, ['bold' => true, 'size' => 24, 'color' => RECRUIT_BRAND_HEX, 'before' => 200, 'after' => 80, 'rule' => true]);
}

/** 表格：$rows 第一行为表头；$widths 为各列宽（twip） */
function recruitDocxTable(array $rows, array $widths): string {
    $grid = implode('', array_map(fn($w) => "<w:gridCol w:w=\"$w\"/>", $widths));
    $x = '<w:tbl><w:tblPr><w:tblLayout w:type="fixed"/><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:tblBorders>'
       . implode('', array_map(fn($b) => "<w:$b w:val=\"single\" w:sz=\"4\" w:color=\"D9D9D9\"/>", ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']))
       . '</w:tblBorders><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>';
    foreach ($rows as $i => $cells) {
        $x .= '<w:tr>';
        foreach ($cells as $j => $c) {
            $x .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$j] . '" w:type="dxa"/>'
                . ($i === 0 ? '<w:shd w:val="clear" w:color="auto" w:fill="EEF1FF"/>' : '') . '</w:tcPr>'
                . recruitDocxP((string)$c, ['bold' => $i === 0, 'size' => 18, 'after' => 40]) . '</w:tc>';
        }
        $x .= '</w:tr>';
    }
    return $x . '</w:tbl>' . recruitDocxP('', ['after' => 0]);
}

/** 抬头：logo + 公司名 + 标语，下面一条品牌色细线 */
function recruitDocxHeader(array $lb, bool $hasLogo): string {
    $img = $hasLogo ? '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="540000" cy="540000"/><wp:docPr id="1" name="logo"/>'
        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="1" name="logo.png"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="540000" cy="540000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
        . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r><w:r><w:t xml:space="preserve">  </w:t></w:r>' : '';
    return '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>' . $img
         . recruitDocxRuns($lb['company'], ['bold' => true, 'size' => 32, 'color' => RECRUIT_BRAND_HEX])
         . ($lb['company_en'] !== '' ? recruitDocxRuns('  ' . $lb['company_en'], ['size' => 18, 'color' => '595959']) : '') . '</w:p>'
         . recruitDocxP($lb['slogan'], ['size' => 18, 'color' => '8C8C8C', 'rule' => true, 'after' => 200]);
}

/** 打包成 docx 二进制 */
function recruitDocxPack(string $body, ?string $logoBin): string {
    $tmp = tempnam(sys_get_temp_dir(), 'rdocx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . ($logoBin !== null ? '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/>' : '')
        . '</Relationships>');
    if ($logoBin !== null) $z->addFromString('word/media/logo.png', $logoBin);
    $z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="1100" w:bottom="1000" w:left="1100" w:header="500" w:footer="500" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>');
    $z->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

// ---------------------------------------------------------------- 两份文档

/**
 * @param string $kind letter | resume
 * @param array  $c    content_json（recruitValidateReco 的结构）
 * @param array  $meta lang / date / position / client / recruiter_name / recruiter_email / recruiter_phone
 */
function recruitRecoDocx(string $kind, array $c, array $meta): string {
    $lb = recruitRecoLabels((string)($meta['lang'] ?? 'zh'));
    $logoPath = recruitBrand()['logo'];
    $logo = $logoPath !== '' && is_file($logoPath) ? (string)file_get_contents($logoPath) : null;
    $b = recruitDocxHeader($lb, $logo !== null);
    $r = $c['resume'] ?? []; $l = $c['letter'] ?? [];
    if ($kind === 'letter') {
        // 推荐信按模板块排（includes/recruit_reco_tpl.php recruitRecoLetterItems；网页预览 / PDF 是 RecoDoc.tsx 同一套规则）
        require_once __DIR__ . '/recruit_reco_tpl.php';
        foreach (recruitRecoLetterItems($c, $meta, (string)($meta['lang'] ?? 'zh'), (string)($meta['date'] ?? '')) as $it) {
            switch ($it['kind']) {
                case 'title':   $b .= recruitDocxP($it['text'], ['bold' => true, 'size' => 30, 'align' => 'center', 'before' => 120, 'after' => 160]); break;
                case 'to':      $b .= recruitDocxP($it['text'], ['bold' => true]); break;
                // 候选人编号印在推荐信上：日后客户绕开我们直接录用时，这是「人是我们推荐的」的凭据
                case 'meta':    $b .= recruitDocxP($it['text'], ['color' => '595959', 'after' => 160]); break;
                case 'heading': $b .= recruitDocxH($it['text']); break;
                case 'subject': $b .= recruitDocxP($it['text'], ['bold' => true, 'size' => 22]); break;
                case 'para':    foreach (preg_split('/\n+/', $it['text']) as $ln) $b .= recruitDocxP($ln, ['after' => 120]); break;
                case 'small':   $b .= recruitDocxP($it['text'], ['size' => 16, 'color' => '8C8C8C', 'before' => 200]); break;
                case 'match':   $b .= recruitDocxTable(array_merge([$it['head']], array_map(fn($r) => array_slice($r, 0, 3), $it['rows'])), [2900, 1400, 5400]); break;
                case 'lines':
                    foreach ($it['lines'] as $k => $ln) $b .= recruitDocxP($ln, ['bold' => $k === 0, 'color' => $k === 0 ? null : '595959', 'before' => $k === 0 ? 240 : 0, 'after' => 20]);
                    break;
            }
        }
    } else {
        $b .= recruitDocxP(($r['name'] ?? ''), ['bold' => true, 'size' => 32, 'align' => 'center', 'before' => 120, 'after' => 40]);
        $b .= recruitDocxP(trim(($r['headline'] ?? '') . ((($r['location'] ?? '') !== '') ? ' · ' . $r['location'] : '')), ['align' => 'center', 'color' => '595959']);
        $b .= recruitDocxP($lb['position'] . '：' . ($meta['position'] ?? '') . '    ' . $lb['ref'] . '：' . ($meta['candidate_code'] ?? ''),
            ['align' => 'center', 'color' => RECRUIT_BRAND_HEX, 'after' => 160]);
        if (!empty($r['summary'])) { $b .= recruitDocxH($lb['summary']); $b .= recruitDocxP($r['summary']); }
        if (!empty($r['highlights'])) {
            $b .= recruitDocxH($lb['highlights']);
            foreach ($r['highlights'] as $h) $b .= recruitDocxP('**' . $h['title'] . '** — ' . $h['text'], ['bullet' => true]);
        }
        if (!empty($r['experience'])) {
            $b .= recruitDocxH($lb['experience']);
            foreach ($r['experience'] as $x) {
                $b .= recruitDocxP($x['title'] . ((($x['company'] ?? '') !== '') ? ' · ' . $x['company'] : '') . ((($x['period'] ?? '') !== '') ? '    ' . $x['period'] : ''), ['bold' => true, 'before' => 80, 'after' => 40]);
                foreach ($x['bullets'] ?? [] as $bl) $b .= recruitDocxP($bl, ['bullet' => true, 'after' => 20]);
            }
        }
        if (!empty($r['projects'])) {
            $b .= recruitDocxH($lb['projects']);
            foreach ($r['projects'] as $p) $b .= recruitDocxP('**' . $p['name'] . '**' . ((($p['period'] ?? '') !== '') ? ' (' . $p['period'] . ')' : '') . ((($p['text'] ?? '') !== '') ? ' — ' . $p['text'] : ''), ['bullet' => true]);
        }
        if (!empty($r['education'])) {
            $b .= recruitDocxH($lb['education']);
            foreach ($r['education'] as $e) $b .= recruitDocxP(implode(' · ', array_filter([$e['school'], $e['degree'], $e['major'], $e['period']])));
        }
        foreach (['skills' => 'skills', 'certificates' => 'certs', 'languages' => 'langs'] as $k => $lk) {
            if (!empty($r[$k])) { $b .= recruitDocxH($lb[$lk]); $b .= recruitDocxP(implode('  ·  ', $r[$k])); }
        }
    }
    // 推荐信的保密声明是模板里的固定块（可改）；推荐简历仍固定带
    $b .= recruitDocxP($kind === 'letter' ? $lb['legend'] : $lb['legend'] . '  |  ' . $lb['confidential'], ['size' => 16, 'color' => '8C8C8C', 'before' => 300]);
    return recruitDocxPack($b, $logo);
}
