/**
 * 关系网络 /recruit/network（「站在用户角度：选行业 → 细分赛道 → 公司 → 人，点人直接看简历」）。
 *
 * 招聘专员的真实动线是「逐个击破」，不是看一团星云：
 *   ① 选行业（带插画的行业标签）→ ② 这个行业的细分赛道（矿产：冶炼加工 / 煤矿开采 / 矿山服务…）
 *   → ③ 赛道里的公司（行业 logo、在职 / 曾任人数、热度）→ ④ 公司里的人（头像、职位、任职时间、在职还是已离开，一键看简历）
 *   → ⑤ 某个人的「关系图」：他待过的公司、同期同事、校友、在我们哪些项目里（浅色、每个点都有名字，点同事直接切过去）
 * 数据全部来自已有接口：recruitCompanies（行业 / 赛道计数与公司列表，计数 = 点开的条数）、recruitCompanyPeople、recruitNetwork。
 * 与企业库同一查看名单（后端 recruitCompanyAuth）；看不到全部的招聘专员只看到自己名下的人。
 */
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Card, Space, AutoComplete, Input, Tag, Typography, Button, Empty, Spin, Segmented, Avatar, Drawer, Tooltip, Tabs } from 'antd';
import { SearchOutlined, FileTextOutlined, ApartmentOutlined, FireFilled, TeamOutlined, BankOutlined, ReadOutlined, StarFilled } from '@ant-design/icons';
import { history, useIntl, useLocation } from '@umijs/max';
import * as echarts from 'echarts';
import { recruitMeta, recruitNetwork, recruitNetworkSearch, recruitCompanies, recruitSegments, recruitCompanyPeople } from '@/services/api';
import { showErr, industryColor, IndustryArt, segName, REGION_COLOR, heatLevel, candCode, T } from '../common';
import CandidateDrawer from '../components/CandidateDrawer';
import CompanyDrawer from '../components/CompanyDrawer';
import FavStar from '../components/FavStar';
import Threads from './Threads';

const { Text, Title } = Typography;
const initials = (s: string) => (String(s || '?').trim().split(/\s+/).map((w) => w[0]).join('').slice(0, 2) || '?').toUpperCase();
const CURRENT = '#52C41A';
const FORMER = '#8C8C8C';
const esc = (x: any) => String(x ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// ====================================================================== 某个人的关系图（浅色、每个点都有名字）
const PersonMap: React.FC<{ id: number | null; t: T; onClose: () => void; onPerson: (id: number) => void; onResume: (id: number) => void; onCompany: (id: number) => void }> = ({ id, t, onClose, onPerson, onResume, onCompany }) => {
  const box = useRef<HTMLDivElement>(null);
  const [g, setG] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    if (!id) return;
    setLoading(true); setG(null);
    recruitNetwork({ type: 'candidate', ref: String(id), with: 'school,project', max: 200 })
      .then((r: any) => (r?.success ? setG(r.data) : showErr(r, t))).finally(() => setLoading(false));
  }, [id, t]);
  const me = g?.nodes?.find((n: any) => n.focus);
  const nodesById = useMemo(() => Object.fromEntries((g?.nodes || []).map((n: any) => [n.id, n])), [g]);
  // 分组：我待过的公司（带我在那的任职边）、同期同事（挂在哪家）、学校、校友、我们的项目
  const groups = useMemo(() => {
    if (!g || !me) return null;
    const out = { companies: [] as any[], colleagues: [] as any[], schools: [] as any[], alumni: [] as any[], projects: [] as any[] };
    const myWork: Record<string, any> = {};
    const colleagueAt: Record<string, string> = {};
    for (const e of g.edges) {
      if (e.rel === 'worked' && e.source === me.id) myWork[e.target] = e;
      if (e.rel === 'colleague') colleagueAt[e.target] = `co:${e.company_id}`;
    }
    const mySchools = new Set(g.edges.filter((e: any) => e.rel === 'studied' && e.source === me.id).map((e: any) => e.target));
    for (const n of g.nodes) {
      if (n.focus) continue;
      if (n.type === 'company' && myWork[n.id]) out.companies.push({ ...n, work: myWork[n.id] });
      else if (n.type === 'candidate' && colleagueAt[n.id]) out.colleagues.push({ ...n, at: colleagueAt[n.id] });
      else if (n.type === 'school' && mySchools.has(n.id)) out.schools.push(n);
      else if (n.type === 'project') out.projects.push({ ...n, stage: g.edges.find((e: any) => e.rel === 'pipeline' && e.target === n.id && e.source === me.id)?.stage });
      else if (n.type === 'candidate') out.alumni.push({ ...n, school: g.edges.find((e: any) => e.rel === 'studied' && e.source === n.id && mySchools.has(e.target))?.target });
    }
    out.companies.sort((a, b) => Number(!!b.work.current) - Number(!!a.work.current) || Number(b.work.months || 0) - Number(a.work.months || 0));
    return out;
  }, [g, me]);

  useEffect(() => {
    if (!box.current || !groups || !me) return;
    const chart = echarts.init(box.current);
    const W = box.current.clientWidth || 900;
    const H = box.current.clientHeight || 520;
    const cx = W / 2;
    const cy = H / 2;
    const data: any[] = [];
    const links: any[] = [];
    // 画布宽比高大得多：按椭圆排，横向拉开，公司多了标签才不挤
    const RX1 = W * 0.29; const RY1 = H * 0.32;
    const RX2 = W * 0.45; const RY2 = H * 0.44;
    const at = (a: number, k = 1) => ({ x: cx + RX1 * k * Math.cos(a), y: cy + RY1 * k * Math.sin(a) });
    const side = (a: number) => (Math.cos(a) < -0.55 ? 'left' : Math.cos(a) > 0.55 ? 'right' : undefined);
    // 公司放上半圈，学校 / 项目放下半圈；同事挂在自己那家公司外侧，校友挂在学校外侧
    const place = (list: any[], from: number, to: number) => list.map((n, i) => ({ n, a: list.length === 1 ? (from + to) / 2 : from + ((to - from) * i) / (list.length - 1) }));
    data.push({ id: me.id, name: me.label, x: cx, y: cy, symbol: 'circle', symbolSize: 72, raw: me,
      itemStyle: { color: '#1a2ad6', borderColor: '#fff', borderWidth: 3 },
      label: { show: true, position: 'inside', formatter: initials(me.label), color: '#fff', fontSize: 20, fontWeight: 800 } });
    const angleOf: Record<string, number> = {};
    const many = groups.companies.length > 5;
    for (const [i, { n, a }] of place(groups.companies, Math.PI * 0.96, Math.PI * 2.04).entries()) {
      angleOf[n.id] = a;
      const cur = !!n.work.current;
      const pos = side(a);
      data.push({ id: n.id, name: n.label, ...at(a, many && i % 2 ? 0.72 : 1), raw: n,   // 公司多时内外两圈交错
        symbol: `image:///recruit/art/ind-${n.industry || 'other'}.svg`, symbolSize: 46,
        label: { show: true, position: pos || 'top', distance: 4, color: '#262626', fontSize: 12, fontWeight: 600, align: pos === 'left' ? 'right' : pos === 'right' ? 'left' : 'center',
          formatter: `${String(n.label).slice(0, 20)}\n{s|${cur ? t('pages.recruit.co.bucket.current') : t('pages.recruit.co.bucket.former')}${n.work.months ? ' · ' + t('pages.recruit.co.months', { n: n.work.months }) : ''}}`,
          rich: { s: { color: cur ? CURRENT : FORMER, fontSize: 11, fontWeight: 500, padding: [2, 0, 0, 0] } } } });
      links.push({ source: me.id, target: n.id, lineStyle: { color: cur ? CURRENT : '#bfbfbf', width: 1.5 + Math.min(3, Number(n.work.months || 0) / 24), type: cur ? 'solid' : 'dashed' } });
    }
    for (const { n, a } of place([...groups.schools, ...groups.projects], Math.PI * 0.2, Math.PI * 0.8)) {
      angleOf[n.id] = a;
      const isSchool = n.type === 'school';
      data.push({ id: n.id, name: n.label, ...at(a), raw: n,
        symbol: isSchool ? 'triangle' : 'roundRect', symbolSize: isSchool ? 34 : 30, itemStyle: { color: isSchool ? '#9254DE' : '#EB2F96' },
        label: { show: true, position: 'bottom', color: '#262626', fontSize: 12,
          formatter: isSchool ? String(n.label).slice(0, 26) : `${String(n.label).slice(0, 22)}\n{s|${n.stage ? t(`pages.recruit.stage.${n.stage}`) : ''}}`,
          rich: { s: { color: '#EB2F96', fontSize: 11 } } } });
      links.push({ source: me.id, target: n.id, lineStyle: { color: isSchool ? '#d3adf7' : '#ffadd2', width: 1.5 } });
    }
    const around = (list: any[], key: 'at' | 'school') => {
      const by: Record<string, any[]> = {};
      for (const p of list) if (p[key]) (by[p[key]] = by[p[key]] || []).push(p);
      Object.entries(by).forEach(([anchor, ps]) => {
        const base = angleOf[anchor];
        if (base === undefined) return;
        const shown = ps.slice(0, 8);
        shown.forEach((p, i) => {
          const a = base + (i - (shown.length - 1) / 2) * 0.13;
          data.push({ id: p.id, name: p.label, x: cx + RX2 * Math.cos(a), y: cy + RY2 * Math.sin(a), raw: p, symbol: 'circle', symbolSize: 30,
            itemStyle: { color: key === 'at' ? '#FA8C16' : '#B37FEB', borderColor: '#fff', borderWidth: 2 },
            label: { show: true, position: 'inside', formatter: initials(p.label), color: '#fff', fontSize: 11, fontWeight: 700 } });
          links.push({ source: anchor, target: p.id, lineStyle: { color: key === 'at' ? '#ffd591' : '#efdbff', width: 1 } });
        });
      });
    };
    around(groups.colleagues, 'at');
    around(groups.alumni, 'school');
    // layout:'none' 会把节点包围盒拉满画布，只有一家公司时它会贴到顶边、标签被裁。两个隐形角点把坐标钉成 1:1 像素
    data.push({ id: '__tl', x: 0, y: 0, symbolSize: 0, tooltip: { show: false } }, { id: '__br', x: W, y: H, symbolSize: 0, tooltip: { show: false } });
    chart.setOption({
      tooltip: { formatter: (p: any) => {
        const d = p.data?.raw;
        if (!d) return '';
        if (d.type === 'candidate') return `<b>${esc(d.label)}</b><br/>${esc(d.sub || '')}${d.focus ? '' : `<br/><span style="color:#8c8c8c">${esc(t('pages.recruit.net.clickPerson'))}</span>`}`;
        if (d.type === 'company') return `<b>${esc(d.label)}</b><br/>${esc(d.work?.title || '')}<br/><span style="color:#8c8c8c">${esc(t('pages.recruit.net.clickCompany'))}</span>`;
        return `<b>${esc(d.label)}</b>`;
      } },
      series: [{ type: 'graph', layout: 'none', left: 0, top: 0, right: 0, bottom: 0, roam: true, data, links, edgeSymbol: ['none', 'none'],
        emphasis: { focus: 'adjacency', lineStyle: { width: 3 } }, blur: { itemStyle: { opacity: 0.25 }, lineStyle: { opacity: 0.1 } } }],
    });
    chart.on('click', (p: any) => {   // 点人 = 切到他；点公司 = 打开这家公司（在那待过的人，每人可看简历）
      const d = p.data?.raw;
      if (d?.type === 'candidate' && !d.focus) onPerson(Number(d.ref));
      else if (d?.type === 'company') onCompany(Number(d.ref));
    });
    const onResize = () => chart.resize();
    window.addEventListener('resize', onResize);
    return () => { window.removeEventListener('resize', onResize); chart.dispose(); };
  }, [groups, me, t, onPerson, onCompany]);

  const list = (title: React.ReactNode, items: any[], render: (x: any) => React.ReactNode) => items.length > 0 && (
    <div style={{ marginTop: 12 }}>
      <Text strong>{title} <Text type="secondary">{items.length}</Text></Text>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 6 }}>{items.map(render)}</div>
    </div>);
  const personChip = (p: any, color: string, extra?: string) => (
    <div key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 10px', border: '1px solid #f0f0f0', borderRadius: 10, background: '#fff' }}>
      <Avatar size={28} style={{ background: color, fontSize: 12 }}>{initials(p.label)}</Avatar>
      <div style={{ lineHeight: 1.3 }}><a onClick={() => onPerson(Number(p.ref))}>{p.label}</a>
        <div style={{ fontSize: 12, color: '#8c8c8c', maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{extra || p.sub}</div></div>
      <Tooltip title={t('pages.recruit.net.resume')}><Button size="small" type="text" icon={<FileTextOutlined />} onClick={() => onResume(Number(p.ref))} /></Tooltip>
    </div>);

  return (
    <Drawer open={!!id} onClose={onClose} width={Math.min(1100, (typeof window !== 'undefined' ? window.innerWidth : 1100) - 60)} destroyOnClose
      title={me ? <Space><Avatar style={{ background: '#1a2ad6' }}>{initials(me.label)}</Avatar><span>{me.label}</span>
        <Text type="secondary" style={{ fontSize: 13, fontWeight: 400 }}>{me.sub}</Text></Space> : t('pages.recruit.net.personMap')}
      extra={me && <Space><FavStar id={Number(me.ref)} on={!!me.favorited} t={t} withText />
        <Button type="primary" icon={<FileTextOutlined />} onClick={() => onResume(Number(me.ref))}>{t('pages.recruit.net.resume')}</Button></Space>}>
      <Spin spinning={loading}>
        <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.net.personHint')}</Text>
        <div ref={box} style={{ height: 520, marginTop: 8, background: '#fafbff', borderRadius: 16 }} />
        {groups && (
          <>
            {list(<><BankOutlined /> {t('pages.recruit.net.hisCompanies')}</>, groups.companies, (c) => (
              <div key={c.id} onClick={() => onCompany(Number(c.ref))} style={{ cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 8, padding: '6px 10px', border: `1px solid ${industryColor(c.industry)}40`, borderRadius: 10, background: `${industryColor(c.industry)}0d` }}>
                <IndustryArt code={c.industry} size={28} />
                <div style={{ lineHeight: 1.3 }}><Text strong>{c.label}</Text>
                  <div style={{ fontSize: 12, color: c.work.current ? CURRENT : FORMER }}>{c.work.title || '-'} · {c.work.current ? t('pages.recruit.co.bucket.current') : t('pages.recruit.co.bucket.former')}
                    {c.work.months ? ` · ${t('pages.recruit.co.months', { n: c.work.months })}` : ''}</div></div>
              </div>))}
            {list(<><TeamOutlined /> {t('pages.recruit.net.colleagues')}</>, groups.colleagues, (p) => personChip(p, '#FA8C16', `${nodesById[p.at]?.label || ''}`))}
            {list(<><ReadOutlined /> {t('pages.recruit.net.alumni')}</>, groups.alumni, (p) => personChip(p, '#B37FEB', nodesById[p.school]?.label || p.sub))}
            {list(t('pages.recruit.net.inProjects'), groups.projects, (p) => <Tag key={p.id} color="magenta">{p.label}{p.stage ? ` · ${t(`pages.recruit.stage.${p.stage}`)}` : ''}</Tag>)}
            {!groups.companies.length && !groups.colleagues.length && !groups.alumni.length && <Empty style={{ marginTop: 16 }} description={t('pages.recruit.net.noLinks')} />}
          </>)}
      </Spin>
    </Drawer>
  );
};

// ====================================================================== 页面：行业 → 赛道 → 公司 → 人
const Network: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const location = useLocation();
  const [meta, setMeta] = useState<any>(null);
  const [segments, setSegments] = useState<any[]>([]);
  const [facets, setFacets] = useState<any>(null);
  const [ind, setInd] = useState<string>();
  const [seg, setSeg] = useState<string>('all');      // all / none（未细分）/ 赛道 id
  const [cos, setCos] = useState<any[]>([]);
  const [cosTotal, setCosTotal] = useState(0);
  const [cosPage, setCosPage] = useState(1);
  const [cosLoading, setCosLoading] = useState(false);
  const [coId, setCoId] = useState<number | null>(null);
  const [bucket, setBucket] = useState('all');
  const [people, setPeople] = useState<any[]>([]);
  const [pLoading, setPLoading] = useState(false);
  const [opts, setOpts] = useState<any[]>([]);
  const [person, setPerson] = useState<number | null>(null);     // 关系图
  const [resume, setResume] = useState<number | null>(null);     // 看简历
  const [openCo, setOpenCo] = useState<number | null>(null);
  const [tab, setTab] = useState('graph');   // 总图谱 / 按行业浏览

  useEffect(() => {
    recruitMeta().then((r: any) => (r?.success ? setMeta(r.data) : showErr(r, t)));
    recruitSegments().then((r: any) => r?.success && setSegments(r.data || []));
    recruitCompanies({ pageSize: 10 }).then((r: any) => {
      if (!r?.success) { showErr(r, t); return; }
      setFacets(r.data.facets);
      setInd((cur) => cur || Object.keys(r.data.facets?.industries || {}).find((k) => k !== 'other'));
    });
  }, [t]);
  // 地址栏 ?type=candidate&ref= / ?type=company&ref=（企业库、候选人抽屉里的「关系网络」按钮）
  useEffect(() => {
    const q = new URLSearchParams(location.search);
    if (q.get('type') === 'candidate' && q.get('ref')) { setResume(null); setPerson(Number(q.get('ref'))); }
    if (q.get('type') === 'company' && q.get('ref')) setOpenCo(Number(q.get('ref')));
  }, [location.search]);
  // 关掉后把 ?type=&ref= 清掉：否则在本页再点同一个人的「关系网络」，地址没变、effect 不触发，按钮就没反应了
  const clearQuery = () => { if (location.search) history.replace('/recruit/network'); };

  // 这个行业的细分赛道（计数来自同一接口的 facets：点开后公司条数 = 这个数字）
  const segList = useMemo(() => {
    if (!ind || !facets) return [];
    // 后端按 (行业, 赛道) 给的计数：点开后请求同样带 industry + segment_id，条数 = 数字
    const counts: Record<string, number> = facets.by_industry?.[ind] || {};
    const byId = Object.fromEntries(segments.map((x) => [String(x.id), x]));
    const list = Object.entries(counts).filter(([sid]) => sid !== '0')
      .map(([sid, n]) => ({ key: sid, label: byId[sid] ? segName(byId[sid], intl.locale) : `#${sid}`, n: Number(n) })).sort((a, b) => b.n - a.n);
    const total = Number(facets.industries?.[ind] || 0);
    const rest = Number(counts['0'] || 0);
    return [{ key: 'all', label: t('pages.recruit.co.allInIndustry'), n: total }, ...list, ...(rest ? [{ key: 'none', label: t('pages.recruit.net.unsegmented'), n: rest }] : [])];
  }, [ind, facets, segments, intl.locale, t]);

  useEffect(() => {
    if (!ind) return;
    let alive = true;
    setCosLoading(true);
    if (cosPage === 1) { setCoId(null); setPeople([]); }
    // 按热度分页，每页 100（后端上限）；表头显示 total，与左边赛道计数一致
    recruitCompanies({ industry: ind, segment_id: seg === 'all' ? undefined : seg, has_people: '1', sort: 'heat', pageSize: 100, page: cosPage })
      .then((r: any) => {
        if (!alive) return;
        if (!r?.success) { showErr(r, t); return; }
        const rows = r.data.rows || [];
        setCosTotal(Number(r.data.total || 0));
        if (cosPage === 1) { setCos(rows); setCoId(rows[0] ? Number(rows[0].id) : null); } else setCos((cur) => [...cur, ...rows]);
      })
      .finally(() => alive && setCosLoading(false));
    return () => { alive = false; };
  }, [ind, seg, cosPage, t]);
  useEffect(() => {
    if (!coId) return;
    let alive = true;
    setPLoading(true);
    recruitCompanyPeople({ id: coId, bucket }).then((r: any) => { if (alive) (r?.success ? setPeople(r.data || []) : showErr(r, t)); })
      .finally(() => alive && setPLoading(false));
    return () => { alive = false; };
  }, [coId, bucket, t]);
  const co = cos.find((c) => Number(c.id) === coId);
  const color = industryColor(ind);

  const search = async (q: string) => {
    if (!q.trim()) { setOpts([]); return; }
    const r = await recruitNetworkSearch(q);
    setOpts((r?.data || []).filter((x: any) => x.type === 'candidate' || x.type === 'company').map((x: any) => ({ value: `${x.type}|${x.ref}`, label: (
      <Space size={6}>{x.type === 'candidate' ? <Avatar size={20} style={{ background: '#1a2ad6', fontSize: 10 }}>{initials(x.label)}</Avatar> : <IndustryArt code={x.industry} size={20} />}
        <span>{x.label}</span>{x.sub && <Text type="secondary" style={{ fontSize: 12 }}>{x.sub}</Text>}</Space>) })));
  };
  const totalMonths = (p: any) => (p.stints || []).reduce((a: number, x: any) => a + Number(x.months || 0), 0);

  return (
    <div>
      <style>{`.nw-col{background:#fff;border-radius:14px;border:1px solid #f0f0f0;display:flex;flex-direction:column;min-height:0}
        .nw-col-h{padding:12px 14px;border-bottom:1px solid #f5f5f5;font-weight:600}
        .nw-scroll{overflow:auto;padding:8px;flex:1}
        .nw-item{padding:9px 12px;border-radius:10px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px;transition:background .12s}
        .nw-item:hover{background:#f5f7ff}
        .nw-co{padding:10px;border-radius:12px;cursor:pointer;display:flex;gap:10px;align-items:center;border:1px solid transparent;transition:all .12s;margin-bottom:6px}
        .nw-co:hover{background:#fafbff;border-color:#e8ecff}
        .nw-p{border:1px solid #f0f0f0;border-radius:12px;padding:12px;display:flex;gap:12px;align-items:flex-start;background:#fff;transition:all .12s}
        .nw-p:hover{border-color:#c9d2ff;box-shadow:0 2px 10px rgba(26,42,214,.06)}`}</style>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
        <Space align="center"><ApartmentOutlined style={{ fontSize: 20, color: '#1a2ad6' }} /><Title level={4} style={{ margin: 0 }}>{t('menu.recruit.network')}</Title>
          <Text type="secondary">{t('pages.recruit.net.subtitle3')}</Text></Space>
        <Space>
        <Button icon={<StarFilled style={{ color: '#faad14' }} />} onClick={() => history.push('/recruit/talent?fav=1')}>{t('pages.recruit.quick.myFav')}</Button>
        {tab === 'browse' && <AutoComplete style={{ width: 360 }} options={opts} onSearch={search}
          onSelect={(v: string) => { const [type, ref] = v.split('|'); if (type === 'candidate') setPerson(Number(ref)); else setOpenCo(Number(ref)); }}>
          <Input prefix={<SearchOutlined />} placeholder={t('pages.recruit.net.searchPh2')} allowClear />
        </AutoComplete>}
        </Space>
      </div>

      <Tabs activeKey={tab} onChange={setTab} items={[
        { key: 'graph', label: <span><ApartmentOutlined /> {t('pages.recruit.net.tabGraph')}</span>,
          children: tab === 'graph' && <Threads t={t} industries={facets?.industries || {}} onResume={setResume} /> },
        { key: 'browse', label: <span><BankOutlined /> {t('pages.recruit.net.tabBrowse')}</span>, children: (
          <>
      {/* ① 行业 */}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
        {Object.entries(facets?.industries || {}).map(([k, n]: any) => (
          <div key={k} onClick={() => { setInd(k); setSeg('all'); setCosPage(1); }}
            style={{ cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6, padding: '4px 12px 4px 4px', borderRadius: 20,
              border: `1.5px solid ${ind === k ? industryColor(k) : '#f0f0f0'}`, background: ind === k ? `${industryColor(k)}14` : '#fff', fontWeight: ind === k ? 600 : 400 }}>
            <IndustryArt code={k} size={26} /><span style={{ color: industryColor(k) }}>{t(`pages.recruit.industry.${k}`)}</span><Text type="secondary" style={{ fontSize: 12 }}>{n}</Text>
          </div>))}
      </div>

      {!ind ? <Card><Empty description={t('pages.recruit.net.pickFirst')} /></Card> : (
        <div style={{ display: 'grid', gridTemplateColumns: '230px 360px 1fr', gap: 12, height: 'calc(100vh - 260px)', minHeight: 520 }}>
          {/* ② 细分赛道 */}
          <div className="nw-col" style={{ borderTop: `3px solid ${color}` }}>
            <div className="nw-col-h"><Space><IndustryArt code={ind} size={22} />{t('pages.recruit.net.segments')}</Space></div>
            <div className="nw-scroll">
              {segList.map((s) => (
                <div key={s.key} className="nw-item" onClick={() => { setSeg(s.key); setCosPage(1); }} style={seg === s.key ? { background: `${color}18`, color, fontWeight: 600 } : undefined}>
                  <span>{s.label}</span><Text type="secondary" style={{ fontSize: 12 }}>{s.n}</Text>
                </div>))}
              {segList.length <= 2 && <Text type="secondary" style={{ fontSize: 12, display: 'block', padding: 12 }}>{t('pages.recruit.net.segHint')}</Text>}
            </div>
          </div>

          {/* ③ 公司 */}
          <div className="nw-col">
            <div className="nw-col-h">{t('pages.recruit.net.companies')} <Text type="secondary" style={{ fontWeight: 400 }}>{cosTotal}</Text></div>
            <div className="nw-scroll">
              <Spin spinning={cosLoading}>
                {cos.length === 0 && !cosLoading ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> : cos.map((c) => {
                  const hl = heatLevel(Number(c.heat || 0));
                  const sel = Number(c.id) === coId;
                  return (
                    <div key={c.id} className="nw-co" onClick={() => setCoId(Number(c.id))} style={sel ? { background: `${color}12`, borderColor: `${color}66` } : undefined}>
                      <div style={{ width: 44, height: 44, borderRadius: 10, background: `${industryColor(c.industry)}14`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                        <IndustryArt code={c.industry} size={34} />
                      </div>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={c.name}>{c.name}</div>
                        <Space size={6} style={{ fontSize: 12 }}>
                          <span style={{ color: CURRENT }}>{t('pages.recruit.co.bucket.current')} {c.counts?.current ?? 0}</span>
                          <span style={{ color: FORMER }}>{t('pages.recruit.co.bucket.former')} {c.counts?.former ?? 0}</span>
                          {c.region && <Tag color={REGION_COLOR[c.region]} style={{ fontSize: 11, lineHeight: '16px', marginInlineEnd: 0 }}>{t(`pages.recruit.region.${c.region}`)}</Tag>}
                        </Space>
                      </div>
                      {hl > 0 && <span>{Array.from({ length: hl }).map((_, i) => <FireFilled key={i} style={{ color: hl === 3 ? '#F5222D' : hl === 2 ? '#FA541C' : '#FAAD14', fontSize: 12 }} />)}</span>}
                    </div>);
                })}
                {cos.length < cosTotal && (
                  <div style={{ textAlign: 'center', padding: 8 }}>
                    <Button size="small" loading={cosLoading} onClick={() => setCosPage((p) => p + 1)}>{t('pages.recruit.net.loadMore', { n: cosTotal - cos.length })}</Button>
                  </div>)}
              </Spin>
            </div>
          </div>

          {/* ④ 人 */}
          <div className="nw-col">
            <div className="nw-col-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <Space>{co ? <><IndustryArt code={co.industry} size={24} /><span>{co.name}</span></> : t('pages.recruit.net.people')}</Space>
              {co && (
                <Space>
                  <Segmented size="small" value={bucket} onChange={(v) => setBucket(String(v))}
                    options={['all', 'current', 'former', 'long'].map((b) => ({ value: b, label: `${t(`pages.recruit.co.bucket.${b}`)} ${co.counts?.[b] ?? 0}` }))} />
                  <Button size="small" onClick={() => setOpenCo(Number(co.id))}>{t('pages.recruit.net.openCompany')}</Button>
                </Space>)}
            </div>
            <div className="nw-scroll">
              <Spin spinning={pLoading}>
                {!co ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('pages.recruit.net.pickCompany')} /> : people.length === 0 && !pLoading ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> : (
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))', gap: 10 }}>
                    {people.map((p) => {
                      const s = p.stints?.[0] || {};
                      const cur = (p.stints || []).some((x: any) => Number(x.is_current) === 1);
                      const m = totalMonths(p);
                      return (
                        <div key={p.id} className="nw-p" style={{ cursor: 'pointer' }} onClick={() => setResume(Number(p.id))}>
                          <Avatar size={44} style={{ background: cur ? CURRENT : FORMER, flexShrink: 0, fontWeight: 700 }}>{initials(p.name || p.code)}</Avatar>
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <Space size={6} wrap>
                              <Text strong>{p.name || p.code}</Text>
                              <FavStar id={Number(p.id)} on={!!p.favorited} t={t} onChange={(v) => setPeople((ps) => ps.map((x) => (Number(x.id) === Number(p.id) ? { ...x, favorited: v } : x)))} />
                              <Tag color={cur ? 'green' : 'default'} style={{ marginInlineEnd: 0 }}>{cur ? t('pages.recruit.co.bucket.current') : t('pages.recruit.co.bucket.former')}</Tag>
                            </Space>
                            <div style={{ fontSize: 13, marginTop: 2 }}>{s.title || '-'}</div>
                            <div style={{ fontSize: 12, color: '#8c8c8c' }}>
                              {s.start_ym || '?'} ~ {Number(s.is_current) === 1 ? t('pages.recruit.p.present') : (s.end_ym || '?')}
                              {m > 0 ? ` · ${t('pages.recruit.co.months', { n: m })}` : ''}
                              {s.job_function ? ` · ${t(`pages.recruit.func.${s.job_function}`)}` : ''}
                            </div>
                            {!!p.latest_company && !cur && <div style={{ fontSize: 12, color: '#595959' }}>{t('pages.recruit.net.nowAt', { co: p.latest_company })}</div>}
                            <Space size={4} style={{ marginTop: 6 }}>
                              <Button size="small" type="primary" ghost icon={<FileTextOutlined />} onClick={(e) => { e.stopPropagation(); setResume(Number(p.id)); }}>{t('pages.recruit.net.resume')}</Button>
                              <Button size="small" icon={<ApartmentOutlined />} onClick={(e) => { e.stopPropagation(); setPerson(Number(p.id)); }}>{t('pages.recruit.net.personMap')}</Button>
                            </Space>
                            <div style={{ fontSize: 11, color: '#bfbfbf', marginTop: 4 }}>{p.code || candCode(p.id)} · {p.owner_user_name || t('pages.recruit.unclaimed')}</div>
                          </div>
                        </div>);
                    })}
                  </div>)}
              </Spin>
            </div>
          </div>
        </div>)}

          </>) },
      ]} />
      <PersonMap id={person} t={t} onClose={() => { setPerson(null); clearQuery(); }} onPerson={setPerson} onResume={setResume} onCompany={setOpenCo} />
      <CandidateDrawer open={resume !== null} candidateId={resume} meta={meta} initTab="profile" onClose={() => setResume(null)} />
      <CompanyDrawer open={openCo !== null} companyId={openCo} meta={meta} segments={segments} enums={meta?.co || {}} onClose={() => { setOpenCo(null); clearQuery(); }} />
    </div>
  );
};

export default Network;
