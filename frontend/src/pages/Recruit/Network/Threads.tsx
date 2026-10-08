/**
 * 关系网络 · 丝线图（lieflat-charts B3「Thread Triptych, Interactive」，templates/big-threads.html 的骨架；
 * 「还是好乱，用 lieflat 那个 skill」）。
 * 一人一根线：① 学校 → ② 上一个行业 → ③ 现在的行业。悬停一根线 = 这个人的整条路；悬停标签 = 经过它的整束人；点击钉住。
 * 点线 = 打开这个人的简历；下方状态栏列出当前高亮的人，点名字也开简历。
 * 与模板的差别只有两处：暗卡改浅卡（不要黑底，用 wire 预设：灰阶 = 数据、橙 = 唯一高亮），行业标签前加行业图标。
 * 数据：recruitNetwork type=threads（includes/recruit_network.php recruitNetworkThreads）。
 */
import React, { useEffect, useMemo, useState } from 'react';
import { Select, Space, Typography, Spin, Empty, Tag, Alert } from 'antd';
import { ReadOutlined, SearchOutlined } from '@ant-design/icons';
import { recruitNetwork } from '@/services/api';
import { showErr, industryColor, IndustryArt, type T } from '../common';

const { Text } = Typography;
// wire 预设（lieflat color-presets.js）：灰阶承载数据，HERO 橙只给当前高亮
const W = { BG: '#F0F0EE', TXT: '#1F1E1C', MUT: 'rgba(31,30,28,.60)', LAB: 'rgba(31,30,28,.72)', FAINT: 'rgba(31,30,28,.32)', GRID: 'rgba(31,30,28,.16)', DATA: '#22211F', HERO: '#F5572F' };
const TOP_SCHOOLS = 24;
type Row = { id: number; name: string; code: string; title: string; company: string; prev_company: string; cur: string; prev: string; current: boolean; school_key: string; school: string; favorited?: boolean };
type Node = { key: string; label: string; n: number; ind?: string };
type Sel = { ids: Set<number>; label: React.ReactNode } | null;

const Threads: React.FC<{ t: T; industries: Record<string, number>; onResume: (id: number) => void }> = ({ t, industries, onResume }) => {
  const [rows, setRows] = useState<Row[]>([]);
  const [truncated, setTruncated] = useState(false);
  const [loading, setLoading] = useState(false);
  const [inds, setInds] = useState<string[]>([]);
  const [hover, setHover] = useState<Sel>(null);
  const [pinned, setPinned] = useState<Sel>(null);

  useEffect(() => {
    let alive = true;
    setLoading(true); setPinned(null); setHover(null);
    recruitNetwork({ type: 'threads', ref: inds.join(',') })
      .then((r: any) => { if (!alive) return; if (r?.success) { setRows(r.data.rows || []); setTruncated(!!r.data.truncated); } else showErr(r, t); })
      .finally(() => alive && setLoading(false));
    return () => { alive = false; };
  }, [inds, t]);

  // 三列节点：学校取人数最多的 24 所，其余并成「其他学校」，没填的单列；行业按人数排
  const layout = useMemo(() => {
    const cnt = (f: (r: Row) => string) => { const m: Record<string, number> = {}; rows.forEach((r) => { const k = f(r); m[k] = (m[k] || 0) + 1; }); return m; };
    const sc = cnt((r) => r.school_key);
    const top = Object.entries(sc).filter(([k]) => k !== '').sort((a, b) => b[1] - a[1]).slice(0, TOP_SCHOOLS).map(([k]) => k);
    const topSet = new Set(top);
    const schoolOf = (r: Row) => (r.school_key === '' ? '__none' : topSet.has(r.school_key) ? r.school_key : '__other');
    const sName: Record<string, string> = {};
    rows.forEach((r) => { if (r.school_key) sName[r.school_key] = r.school; });
    const sCnt = cnt(schoolOf);
    const S: Node[] = [...top.map((k) => ({ key: k, label: sName[k], n: sCnt[k] })),
      ...(sCnt.__other ? [{ key: '__other', label: t('pages.recruit.net.th.otherSchool'), n: sCnt.__other }] : []),
      ...(sCnt.__none ? [{ key: '__none', label: t('pages.recruit.net.th.noSchool'), n: sCnt.__none }] : [])];
    const indNodes = (m: Record<string, number>, emptyLabel: string): Node[] => Object.entries(m).sort((a, b) => (a[0] === '' ? 1 : b[0] === '' ? -1 : b[1] - a[1]))
      .map(([k, n]) => ({ key: k, label: k ? t(`pages.recruit.industry.${k}`) : emptyLabel, n, ind: k || undefined }));
    const P = indNodes(cnt((r) => r.prev), t('pages.recruit.net.th.firstJob'));
    const C = indNodes(cnt((r) => r.cur), '-');
    const H = Math.max(560, Math.max(S.length, P.length, C.length) * 26 + 70);
    const colY = (list: Node[]) => { const gap = Math.min(44, (H - 70) / Math.max(1, list.length - 1)); const top0 = (H - gap * (list.length - 1)) / 2; const m: Record<string, number> = {}; list.forEach((n, i) => { m[n.key] = top0 + i * gap; }); return m; };
    return { S, P, C, H, sy: colY(S), py: colY(P), cy: colY(C), schoolOf };
  }, [rows, t]);

  // 几何照 big-threads：三列 X，两段三次贝塞尔
  const X1 = 300; const X2 = 640; const X3 = 960;
  const path = (r: Row) => {
    const a = layout.sy[layout.schoolOf(r)]; const b = layout.py[r.prev]; const c = layout.cy[r.cur];
    return `M${X1 + 6} ${a} C${(X1 + X2) / 2} ${a} ${(X1 + X2) / 2} ${b} ${X2} ${b} C${(X2 + X3) / 2} ${b} ${(X2 + X3) / 2} ${c} ${X3 - 6} ${c}`;
  };
  const sel = pinned || hover;
  const personLabel = (r: Row) => <span><b>{r.name}</b> · {r.school || t('pages.recruit.net.th.noSchool')} → {r.prev ? t(`pages.recruit.industry.${r.prev}`) : t('pages.recruit.net.th.firstJob')} → <b>{t(`pages.recruit.industry.${r.cur}`)}</b>{r.company ? ` · ${r.company}` : ''}</span>;
  const bundle = (col: 's' | 'p' | 'c', key: string, label: string): Sel => {
    const ids = new Set(rows.filter((r) => (col === 's' ? layout.schoolOf(r) : col === 'p' ? r.prev : r.cur) === key).map((r) => r.id));
    return { ids, label: <span><b>{label}</b> · {t('pages.recruit.net.nPeople', { n: ids.size })}</span> };
  };
  const one = (r: Row): Sel => ({ ids: new Set([r.id]), label: personLabel(r) });
  const hotNode = (col: 's' | 'p' | 'c', key: string) => !!sel && rows.some((r) => sel.ids.has(r.id) && (col === 's' ? layout.schoolOf(r) : col === 'p' ? r.prev : r.cur) === key);
  const selRows = sel ? rows.filter((r) => sel.ids.has(r.id)) : [];

  const label = (col: 's' | 'p' | 'c', n: Node, x: number, y: number, anchor: 'end' | 'start') => {
    const hot = hotNode(col, n.key);
    const fill = n.ind ? industryColor(n.ind) : W.LAB;
    const tx = anchor === 'end' ? x - 10 : x + (n.ind ? 30 : 10);
    return (
      <g key={`${col}:${n.key}`} className={`th-lab${hot ? ' hot' : ''}`} style={{ cursor: 'pointer' }}
        onMouseEnter={() => !pinned && setHover(bundle(col, n.key, n.label))}
        onClick={(e) => { e.stopPropagation(); setPinned(bundle(col, n.key, n.label)); }}>
        {col === 's' ? <rect x={x - 3} y={y - 1.6} width={6} height={3.2} fill={W.DATA} opacity={0.85} /> : <circle cx={x} cy={y} r={3.4} fill={n.ind ? fill : W.FAINT} />}
        {n.ind && <image href={`/recruit/art/ind-${n.ind}.svg`} x={x + 8} y={y - 10} width={20} height={20} />}
        <text x={tx} y={y + 4} fontSize={col === 's' ? 11 : 12.5} fontWeight={col === 's' ? 500 : 700} fill={col === 's' ? W.LAB : fill} textAnchor={anchor}>
          {String(n.label).slice(0, col === 's' ? 34 : 20)} <tspan fill={W.MUT} fontWeight={500}>{n.n}</tspan>
        </text>
        <rect x={anchor === 'end' ? x - 250 : x - 8} y={y - 10} width={anchor === 'end' ? 258 : 170} height={20} fill="#000" fillOpacity={0} />
      </g>);
  };

  return (
    <div>
      <style>{`.th-thread{transition:opacity .18s ease,stroke-width .18s ease}
        svg.th-focused .th-thread:not(.hot){opacity:.04 !important}
        .th-thread.hot{opacity:.95 !important;stroke:${W.HERO} !important;stroke-width:2.2px !important}
        .th-lab{transition:opacity .18s ease} svg.th-focused .th-lab:not(.hot){opacity:.25}
        .th-person{cursor:pointer;padding:4px 10px;border-radius:10px;border:1px solid #eee;background:#fff;font-size:12px;transition:border-color .12s}
        .th-person:hover{border-color:${W.HERO}}`}</style>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, alignItems: 'center', marginBottom: 10 }}>
        <Select mode="multiple" allowClear style={{ minWidth: 280, maxWidth: 520 }} placeholder={t('pages.recruit.net.fIndustry')} value={inds} onChange={setInds} maxTagCount="responsive"
          options={Object.entries(industries).map(([k, n]) => ({ value: k, label: <Space size={6}><IndustryArt code={k} size={18} /><span style={{ color: industryColor(k) }}>{t(`pages.recruit.industry.${k}`)}</span><Text type="secondary">{n}</Text></Space> }))} />
        <Select showSearch allowClear style={{ width: 260 }} placeholder={<span><ReadOutlined /> {t('pages.recruit.net.fSchool')}</span>} optionFilterProp="label" value={null}
          options={layout.S.filter((n) => !n.key.startsWith('__')).map((n) => ({ value: n.key, label: `${n.label} (${n.n})` }))}
          onChange={(k: any) => { const n = layout.S.find((x) => x.key === k); if (n) setPinned(bundle('s', n.key, n.label)); }} />
        <Select showSearch allowClear style={{ width: 260 }} placeholder={<span><SearchOutlined /> {t('pages.recruit.net.fCandidate')}</span>} optionFilterProp="label" value={null}
          options={rows.map((r) => ({ value: r.id, label: `${r.name} · ${r.code}` }))}
          onChange={(id: any) => { const r = rows.find((x) => x.id === id); if (r) setPinned(one(r)); }} />
        <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.net.th.stat', { p: rows.length, s: layout.S.length })}</Text>
      </div>
      {truncated && <Alert type="info" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.net.th.truncated', { n: rows.length })} />}
      <Spin spinning={loading}>
        <div style={{ background: W.BG, borderRadius: 24, padding: '22px 26px 14px' }}>
          <div style={{ fontWeight: 700, fontSize: 17, color: W.TXT, letterSpacing: '-.02em' }}>{t('pages.recruit.net.th.title')}</div>
          <div style={{ fontSize: 12, color: W.MUT, marginBottom: 4 }}>{t('pages.recruit.net.th.sub')}</div>
          {rows.length === 0 && !loading ? <Empty style={{ padding: 60 }} description={t('pages.recruit.net.noLinks')} /> : (
            <svg viewBox={`0 0 1240 ${layout.H}`} style={{ width: '100%', display: 'block', fontFamily: 'Inter, sans-serif' }} className={sel ? 'th-focused' : ''}
              onMouseLeave={() => setHover(null)} onClick={() => setPinned(null)}>
              {[[X1, t('pages.recruit.net.th.col1')], [X2, t('pages.recruit.net.th.col2')], [X3, t('pages.recruit.net.th.col3')]].map(([x, s]) => (
                <text key={String(s)} x={Number(x)} y={16} fontSize={11} fontWeight={800} fill={W.TXT} letterSpacing=".14em" textAnchor="middle">{s}</text>))}
              <g>
                {rows.map((r) => {
                  const d = path(r);
                  const hot = !!sel && sel.ids.has(r.id);
                  return (
                    <g key={r.id}>
                      <path d={d} fill="none" stroke={r.current ? W.DATA : W.FAINT} strokeWidth={1.1} strokeLinecap="round" className={`th-thread${hot ? ' hot' : ''}`} opacity={0.16} />
                      {/* 看不见的粗孪生线 = 悬停 / 点击热区（模板做法） */}
                      <path d={d} fill="none" stroke="#000" strokeOpacity={0} strokeWidth={9} style={{ cursor: 'pointer' }}
                        onMouseEnter={() => !pinned && setHover(one(r))}
                        onClick={(e) => { e.stopPropagation(); setPinned(one(r)); onResume(r.id); }} />
                    </g>);
                })}
              </g>
              {layout.S.map((n) => label('s', n, X1, layout.sy[n.key], 'end'))}
              {layout.P.map((n) => label('p', n, X2, layout.py[n.key], 'start'))}
              {layout.C.map((n) => label('c', n, X3, layout.cy[n.key], 'start'))}
            </svg>)}
          {/* 状态栏：模板里只有一行读数；这里把高亮的人列出来，点名字开简历 */}
          <div style={{ borderTop: `1px solid ${W.GRID}`, marginTop: 6, paddingTop: 8, minHeight: 34 }}>
            <div style={{ fontSize: 12, color: W.MUT, marginBottom: selRows.length ? 8 : 0 }}>
              {sel ? sel.label : t('pages.recruit.net.th.hint')}
              {pinned && <Tag style={{ marginLeft: 8, fontSize: 10, letterSpacing: '.08em' }}>{t('pages.recruit.net.th.pinned')}</Tag>}
            </div>
            {selRows.length > 0 && (
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, maxHeight: 180, overflow: 'auto' }}>
                {selRows.slice(0, 80).map((r) => (
                  <div key={r.id} className="th-person" onClick={() => onResume(r.id)} title={t('pages.recruit.net.clickResume')}>
                    <b>{r.favorited ? '★ ' : ''}{r.name}</b> <span style={{ color: W.MUT }}>{r.title}{r.company ? ` @ ${r.company}` : ''}</span>
                  </div>))}
                {selRows.length > 80 && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.net.th.more', { n: selRows.length - 80 })}</Text>}
              </div>)}
          </div>
          <div style={{ fontSize: 9.5, color: W.FAINT, marginTop: 10, letterSpacing: '.08em', fontWeight: 500 }}>{t('pages.recruit.net.th.src')}</div>
        </div>
      </Spin>
    </div>
  );
};

export default Threads;
