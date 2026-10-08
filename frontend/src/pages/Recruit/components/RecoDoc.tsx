/**
 * 推荐材料的 A4 排版（网页预览 + 导出 PDF 用同一个 DOM）。公司抬头 + logo + 品牌蓝。
 * logo：后端 recruitRecoLabels 可返回 logo_url，缺省用模块徽章。
 * 固定文案（抬头、标题、判定文字）来自后端 recruitRecoLabels——与 Word 导出同一份（includes/recruit_docx.php）。
 * 正文里 **…** = 与职位要求对应的匹配点，渲染成品牌蓝加粗 + 浅蓝底。
 * 推荐信按模板块排（2026-09-24 模板系统）：letterItems 与后端 recruitRecoLetterItems（includes/recruit_reco_tpl.php）
 * 同一套规则——⛔ 改一处必须改另一处，否则网页 / PDF 与 Word 长得不一样。
 */
import React from 'react';

export const BRAND = '#1a2ad6';
const MARK_BG = '#e6ebff';

/** **…** → <mark> */
export const Marked: React.FC<{ text?: string }> = ({ text }) => (
  <>
    {String(text || '').split(/(\*\*.+?\*\*)/g).filter(Boolean).map((seg, i) => {
      const m = seg.match(/^\*\*(.+)\*\*$/s);
      return m ? <b key={i} style={{ color: BRAND, background: MARK_BG, padding: '0 2px', borderRadius: 2 }}>{m[1]}</b> : <span key={i}>{seg}</span>;
    })}
  </>
);

/** {{var}} 替换；不认识的变量原样留着（与后端 recruitTplFill 同规则） */
export const fillVars = (text: string, vars: Record<string, string>) =>
  String(text || '').replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k) => (k in vars ? vars[k] : m));

/** 必填手填项还空着的（导出 / 标记已发送前拦；与后端 recruitRecoMissingManual 同口径） */
export const missingManual = (content: any): string[] => (content?.tpl?.blocks || [])
  .filter((b: any) => b.type === 'manual' && b.required && !String(content?.manual?.[b.key] || '').trim()).map((b: any) => b.title || b.key);

type Item = { kind: string; text?: string; head?: string[]; rows?: any[]; lines?: string[]; missing?: boolean };
/** 推荐信 → 渲染条目（与后端 recruitRecoLetterItems 一一对应）。placeholders：必填手填项空着时出红字占位（仅预览，导出前已拦） */
export function letterItems(content: any, meta: any, lb: any, date: string, fallbackTpl: any, placeholders = true): Item[] {
  const tpl = content?.tpl?.blocks?.length ? content.tpl : fallbackTpl;
  const l = content?.letter || {}; const r = content?.resume || {};
  const vars: Record<string, string> = {
    client: l.to || meta?.client || '', position: meta?.position || '', candidate: meta?.candidate_name || '', candidate_code: meta?.candidate_code || '',
    consultant: meta?.recruiter_name || '', consultant_email: meta?.recruiter_email || '', consultant_phone: meta?.recruiter_phone || '', date, company: lb.company || '',
  };
  const out: Item[] = [
    { kind: 'title', text: fillVars(tpl?.title || lb.letter_title, vars) },
    { kind: 'to', text: `${lb.to}：${l.to || ''}` },
    { kind: 'meta', text: `${lb.position}：${meta?.position || ''}　　${lb.ref}：${meta?.candidate_code || ''}　　${lb.date}：${date}` },
  ];
  (tpl?.blocks || []).forEach((b: any) => {
    const body: Item[] = [];
    if (b.type === 'fixed') {
      const txt = fillVars(content?.fixed?.[b.key] ?? b.text ?? '', vars);
      if (txt) body.push({ kind: b.small ? 'small' : 'para', text: txt });
    } else if (b.type === 'manual') {
      const v = String(content?.manual?.[b.key] || '').trim();
      if (v) body.push({ kind: 'para', text: v });
      else if (b.required && placeholders) body.push({ kind: 'para', text: `〔${b.title}〕`, missing: true });
    } else if (b.type === 'ai') {
      if (b.slot === 'match') {
        const rows = (l.match || []).map((m: any) => [m.requirement, lb.met?.[m.met] || m.met, m.evidence, m.met]);
        if (rows.length) body.push({ kind: 'match', head: [lb.req, lb.result, lb.evidence], rows });
      } else if (b.slot === 'paragraphs') {
        (l.paragraphs || []).filter((p: string) => String(p || '').trim()).forEach((p: string) => body.push({ kind: 'para', text: p }));
      } else {
        const v = String(l[b.slot] || '').trim();
        if (v) body.push({ kind: b.slot === 'subject' ? 'subject' : 'para', text: v });
      }
    } else if (b.type === 'field') {
      if (b.source === 'signature') {
        body.push({ kind: 'lines', lines: [`${lb.consultant}：${meta?.recruiter_name || ''}`, meta?.recruiter_email, meta?.recruiter_phone, lb.company].filter(Boolean) });
      } else if (b.source === 'education') {
        (r.education || []).forEach((e: any) => body.push({ kind: 'para', text: [e.school, e.degree, e.major, e.period].filter(Boolean).join(' · ') }));
      } else if ((r[b.source] || []).length) body.push({ kind: 'para', text: r[b.source].join('  ·  ') });
    }
    if (body.length) { if (b.title) out.push({ kind: 'heading', text: fillVars(b.title, vars) }); out.push(...body); }
  });
  return out;
}

const H: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <div style={{ color: BRAND, fontWeight: 700, fontSize: 14, borderBottom: `1.5px solid ${BRAND}`, margin: '16px 0 6px', paddingBottom: 2 }}>{children}</div>
);

interface Props { kind: 'letter' | 'resume'; content: any; labels: any; meta: any; date: string; fallbackTpl?: any }

const MET_COLOR: Record<string, string> = { yes: '#389e0d', no: '#cf1322', partial: '#d48806' };
const cell = { border: '1px solid #d9d9d9', padding: '4px 6px' };

const RecoDoc = React.forwardRef<HTMLDivElement, Props>(({ kind, content, labels: lb, meta, date, fallbackTpl }, ref) => {
  const r = content?.resume || {};
  const l = content?.letter || {};
  return (
    <div ref={ref} style={{ width: 794, minHeight: 1123, padding: '40px 48px', background: '#fff', color: '#262626', fontSize: 13, lineHeight: 1.65,
      fontFamily: 'Arial, "PingFang SC", "Microsoft YaHei", sans-serif', boxShadow: '0 0 0 1px #f0f0f0', margin: '0 auto' }}>
      {/* 抬头 */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, borderBottom: `2px solid ${BRAND}`, paddingBottom: 8 }}>
        <img src={lb.logo_url || '/recruit/art/mark-64.jpg'} alt="" style={{ width: 46, height: 46 }} crossOrigin="anonymous" />
        <div>
          <div style={{ color: BRAND, fontSize: 20, fontWeight: 700, lineHeight: 1.2 }}>
            {lb.company}{!!lb.company_en && <span style={{ color: '#595959', fontSize: 11, fontWeight: 400, marginLeft: 8 }}>{lb.company_en}</span>}
          </div>
          <div style={{ color: '#8c8c8c', fontSize: 11 }}>{lb.slogan}</div>
        </div>
      </div>

      {kind === 'letter' ? (
        <>
          {letterItems(content, meta, lb, date, fallbackTpl).map((it, i) => {
            switch (it.kind) {
              case 'title': return <div key={i} style={{ textAlign: 'center', fontSize: 20, fontWeight: 700, margin: '18px 0 12px' }}>{it.text}</div>;
              case 'to': return <div key={i}><b>{it.text}</b></div>;
              case 'meta': return <div key={i} style={{ color: '#595959', marginBottom: 12 }}>{it.text}</div>;
              case 'heading': return <H key={i}>{it.text}</H>;
              case 'subject': return <div key={i} style={{ fontWeight: 700, fontSize: 14, marginBottom: 6 }}><Marked text={it.text} /></div>;
              case 'small': return <div key={i} style={{ marginTop: 14, color: '#8c8c8c', fontSize: 10, whiteSpace: 'pre-wrap' }}>{it.text}</div>;
              case 'lines': return (
                <div key={i} style={{ marginTop: 20 }}>
                  {(it.lines || []).map((ln, k) => (k === 0 ? <b key={k}>{ln}</b> : <div key={k} style={{ color: '#595959' }}>{ln}</div>))}
                </div>);
              case 'match': return (
                <table key={i} style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                  <thead><tr style={{ background: '#eef1ff' }}>
                    {(it.head || []).map((x, k) => <th key={k} style={{ ...cell, textAlign: 'left', width: k === 0 ? '28%' : k === 1 ? '16%' : undefined }}>{x}</th>)}
                  </tr></thead>
                  <tbody>{(it.rows || []).map((rw: any[], k) => (
                    <tr key={k}>
                      <td style={cell}>{rw[0]}</td>
                      <td style={{ ...cell, color: MET_COLOR[rw[3]] || '#8c8c8c' }}>{rw[1]}</td>
                      <td style={cell}><Marked text={rw[2]} /></td>
                    </tr>))}</tbody>
                </table>);
              default: return (
                <p key={i} style={{ margin: '0 0 8px', whiteSpace: 'pre-wrap', color: it.missing ? '#cf1322' : undefined }}>
                  <Marked text={it.text} /></p>);
            }
          })}
        </>
      ) : (
        <>
          <div style={{ textAlign: 'center', fontSize: 22, fontWeight: 700, marginTop: 16 }}>{r.name}</div>
          <div style={{ textAlign: 'center', color: '#595959' }}>{[r.headline, r.location].filter(Boolean).join(' · ')}</div>
          <div style={{ textAlign: 'center', color: BRAND, marginBottom: 6 }}>{lb.position}：{meta?.position}　　{lb.ref}：{meta?.candidate_code}</div>
          {!!r.summary && <><H>{lb.summary}</H><div><Marked text={r.summary} /></div></>}
          {(r.highlights || []).length > 0 && (
            <><H>{lb.highlights}</H>
              <ul style={{ margin: 0, paddingLeft: 18 }}>
                {r.highlights.map((h: any, i: number) => <li key={i}><b style={{ color: BRAND }}>{h.title}</b> — <Marked text={h.text} /></li>)}
              </ul></>
          )}
          {(r.experience || []).length > 0 && (
            <><H>{lb.experience}</H>
              {r.experience.map((x: any, i: number) => (
                <div key={i} style={{ marginBottom: 6 }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <b>{x.title}{x.company ? ` · ${x.company}` : ''}</b><span style={{ color: '#8c8c8c', fontSize: 12 }}>{x.period}</span>
                  </div>
                  <ul style={{ margin: 0, paddingLeft: 18 }}>{(x.bullets || []).map((b: string, j: number) => <li key={j}><Marked text={b} /></li>)}</ul>
                </div>))}</>
          )}
          {(r.projects || []).length > 0 && (
            <><H>{lb.projects}</H>
              <ul style={{ margin: 0, paddingLeft: 18 }}>
                {r.projects.map((p: any, i: number) => <li key={i}><b>{p.name}</b>{p.period ? ` (${p.period})` : ''}{p.text ? ' — ' : ''}<Marked text={p.text} /></li>)}
              </ul></>
          )}
          {(r.education || []).length > 0 && (
            <><H>{lb.education}</H>
              {r.education.map((e: any, i: number) => <div key={i}>{[e.school, e.degree, e.major, e.period].filter(Boolean).join(' · ')}</div>)}</>
          )}
          {([['skills', 'skills'], ['certificates', 'certs'], ['languages', 'langs']] as const).map(([k, lk]) => ((r[k] || []).length > 0 && (
            <div key={k}><H>{lb[lk]}</H><div>{r[k].join('  ·  ')}</div></div>)))}
        </>
      )}
      <div style={{ marginTop: 24, color: '#8c8c8c', fontSize: 10, borderTop: '1px solid #f0f0f0', paddingTop: 6 }}>
        {/* 推荐信的保密声明是模板里的固定块（可改）；推荐简历仍固定带（与 Word 一致） */}
        <b style={{ color: BRAND, background: MARK_BG, padding: '0 3px' }}>■</b> {lb.legend}{kind === 'resume' ? `　|　${lb.confidential}` : ''}
      </div>
    </div>
  );
});

export default RecoDoc;
