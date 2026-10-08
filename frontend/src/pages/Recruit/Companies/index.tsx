/**
 * 企业库 /recruit/companies（「以公司为主体挖人：美妆第一是谁、我们有几个人在那待过；分印尼本地与海外」）。
 * 候选人简历里出现的公司自动建档、挂人、归职能，定时任务补全官网 / 规模 / 组织架构（includes/recruit_company.php）；
 * 名次与梯队只由管理员定。每个人数可点，点开的名单行数 = 数字（后端同一份 SQL）。
 * 页签：企业库（瀑布卡片 / 表格两种看法）/ 职位归类 / 赛道（管理员）/ 不建档名单（管理员）/ 查看权限（管理员）。
 * ⛔ 只有「企业库查看名单」里的人能进（admin 角色也不例外），改资料要再是招聘管理员（后端 recruitCompanyCanView / CanEdit）。
 */
import React, { useCallback, useEffect, useState } from 'react';
import {
  Card, Tabs, Table, Tag, Space, Button, Input, Select, Segmented, Typography, Checkbox, Tooltip, Drawer, Form, InputNumber, Switch, Alert, Popconfirm, message,
  Spin, Empty, Pagination, Menu, Steps,
} from 'antd';
import { PlusOutlined, GlobalOutlined, SearchOutlined, SyncOutlined, LinkedinOutlined, EditOutlined, AppstoreOutlined, UnorderedListOutlined, TeamOutlined, FireFilled, RobotOutlined, SendOutlined } from '@ant-design/icons';
import { useIntl } from '@umijs/max';
import { DetailTable, DETAIL_WIDTH } from '@/components/DetailPanel';
import {
  recruitMeta, recruitCompanies, recruitSegments, recruitSaveSegment, recruitTitleFunctions, recruitSetTitleFunction, recruitCompanyIgnored,
  recruitUnignoreCompany, recruitCompanyRelink, recruitSetCompanyEnrich, recruitCompanyViewers, recruitSaveCompanyViewers, recruitCompanyResearch, recruitCompanyResearchStatus,
  recruitCompanyRecent, recruitCompanyClassifyNow, recruitSegmentChat, recruitSegmentApply,
} from '@/services/api';
import { showErr, fmtDay, NO_HSCROLL, TIER_COLOR, REGION_COLOR, segName, T, industryColor, IndustryArt, heatLevel } from '../common';
import CompanyDrawer, { CompanyForm } from '../components/CompanyDrawer';

const { Text } = Typography;
/** 归类来源配色：简历推断（灰）< AI 快速归类（紫）< 联网核实（绿）< 人工（蓝） */
const CLASS_COLOR: Record<string, string> = { resume: 'default', ai: 'purple', web: 'green', manual: 'blue' };

// ====================================================================== 说明条：它什么时候跑、每次多少、归到哪了
const StatusBar: React.FC<{ t: T; st: any; canAdmin: boolean; onChanged: () => void }> = ({ t, st, canAdmin, onChanged }) => {
  const [busy, setBusy] = useState(false);
  if (!st) return null;
  const by = st.by_source || {};
  const done = Number(by.resume || 0) + Number(by.ai || 0) + Number(by.web || 0) + Number(by.manual || 0);
  return (
    <Alert type="info" showIcon style={{ marginBottom: 12 }}
      message={<Space wrap size={[12, 4]}>
        <Text strong>{t('pages.recruit.co.st.classified', { n: done, total: st.companies })}</Text>
        {['resume', 'ai', 'web', 'manual'].map((k) => <Tag key={k} color={CLASS_COLOR[k]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.co.cls.${k}`)} {by[k] || 0}</Tag>)}
        {!!st.last_run && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.co.st.lastRun', { at: String(st.last_run.at).slice(5, 16), text: st.last_run.text })}</Text>}
      </Space>}
      description={<div style={{ fontSize: 12, lineHeight: 1.8 }}>
        <div>{t('pages.recruit.co.st.how1', { link: st.per_run.link })}</div>
        <div>{t('pages.recruit.co.st.how2', { n: st.per_run.classify, titles: st.per_run.titles })}
          {!st.ai_on && <Text type="warning">（{t('pages.recruit.co.st.aiOff')}）</Text>}</div>
        <div>{t('pages.recruit.co.st.how4')}</div>
        <div>{t('pages.recruit.co.st.how3', { n: st.per_run.enrich, daily: st.enrich_daily, today: st.enrich_today })}
          {canAdmin ? (
            <Popconfirm title={t(st.enrich_enabled ? 'pages.recruit.co.st.enrichOffConfirm' : 'pages.recruit.co.st.enrichOnConfirm', { daily: st.enrich_daily })}
              onConfirm={async () => { const r = await recruitSetCompanyEnrich(!st.enrich_enabled); if (r?.success) onChanged(); else showErr(r, t); }}>
              <Switch size="small" checked={!!st.enrich_enabled} style={{ marginLeft: 8 }} checkedChildren={t('pages.recruit.co.st.on')} unCheckedChildren={t('pages.recruit.co.st.off')} />
            </Popconfirm>
          ) : <Tag color={st.enrich_enabled ? 'green' : 'default'} style={{ marginLeft: 6 }}>{t(st.enrich_enabled ? 'pages.recruit.co.st.on' : 'pages.recruit.co.st.off')}</Tag>}</div>
      </div>}
      action={canAdmin && (
        <Space direction="vertical">
          <Button size="small" type="primary" loading={busy} onClick={async () => {
            setBusy(true);
            try {
              const r = await recruitCompanyClassifyNow();
              if (r?.success) {
                const ai = r.data.ai || {};
                message.success(t('pages.recruit.co.st.classifyDone', { n: r.data.linked || 0, c: r.data.created || 0, inf: r.data.inferred, ai: ai.ok || 0 }) + (ai.abort ? ` · ${t('pages.recruit.co.st.aiUnavailable')}` : ''));
                onChanged();
              } else showErr(r, t);
            } finally { setBusy(false); }
          }}>{t('pages.recruit.co.st.classifyNow')}</Button>
        </Space>)} />
  );
};

/** 左侧行业树：行业（数字）→ 下挂该行业的赛道；未归类单列。点哪个，右边列表的条数就是它上面的数字 */
const IndustryTree: React.FC<{ t: T; facets: any; segments: any[]; value: string; onChange: (k: string) => void }> = ({ t, facets, segments, value, onChange }) => {
  const intl = useIntl();
  if (!facets) return null;
  const byId = Object.fromEntries(segments.map((sg) => [String(sg.id), sg]));
  const items: any[] = [
    { key: 'all', label: <span>{t('pages.recruit.co.allIndustries')} <Text type="secondary">{facets.all}</Text></span> },
    ...Object.entries(facets.industries || {}).map(([ind, n]: any) => {
      // 计数按 (行业, 赛道) 分：点开后同时按行业 + 赛道筛，条数 = 数字；赛道没标行业 / 已停用的也照样列出来，公司不会找不到
      const children = Object.entries(facets.by_industry?.[ind] || {}).filter(([sid]) => sid !== '0').sort((a: any, b: any) => b[1] - a[1])
        .map(([sid, c]: any) => ({ key: `seg:${ind}:${sid}`, label: <span>{byId[sid] ? segName(byId[sid], intl.locale) : `#${sid}`} <Text type="secondary">{c}</Text></span> }));
      const label = <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}><IndustryArt code={ind} size={20} />
        <span style={{ color: industryColor(ind), fontWeight: 500 }}>{t(`pages.recruit.industry.${ind}`)}</span> <Text type="secondary">{n}</Text></span>;
      // 有赛道的行业做成可展开的组：组本身用 grp: 键（只展开不筛），第一项「本行业全部」才是 ind: 筛选
      return children.length ? { key: `grp:${ind}`, label, children: [{ key: `ind:${ind}`, label: t('pages.recruit.co.allInIndustry') }, ...children] } : { key: `ind:${ind}`, label };
    }),
    ...(facets.unclassified ? [{ key: 'none', label: <span>{t('pages.recruit.co.unclassified')} <Text type="secondary">{facets.unclassified}</Text></span> }] : []),
  ];
  return (
    <Card size="small" style={{ width: 230, flexShrink: 0, alignSelf: 'flex-start', position: 'sticky', top: 12 }} bodyStyle={{ padding: 4, maxHeight: 'calc(100vh - 160px)', overflow: 'auto' }}>
      <Menu mode="inline" selectedKeys={[value]} items={items} onClick={(e) => onChange(String(e.key))} style={{ borderInlineEnd: 0, fontSize: 13 }} />
    </Card>
  );
};

// ====================================================================== 企业表
const CompanyList: React.FC<{ t: T; meta: any; segments: any[]; enums: any; onCanEdit: (v: boolean) => void; reloadKey: number }> =
({ t, meta, segments, enums, onCanEdit, reloadKey }) => {
  const intl = useIntl();
  const lang = intl.locale === 'id-ID' ? 'id' : intl.locale === 'en-US' ? 'en' : 'zh';
  const [view, setView] = useState<string>(() => localStorage.getItem('recruit.companies.view') || 'cards');   // 瀑布卡片 / 表格，记住上次的
  const [f, setF] = useState<any>({ region: 'all', has_people: true, sort: '', node: 'all' });
  const [page, setPage] = useState(1);
  const [data, setData] = useState<any>({ rows: [], total: 0 });
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState<{ id: number; bucket?: string; func?: string; edit?: boolean } | null>(null);
  const [creating, setCreating] = useState(false);
  const load = useCallback(async () => {
    setLoading(true);
    try {
      const node = String(f.node || 'all');
      const r = await recruitCompanies({ page, pageSize: 30, region: f.region === 'all' ? undefined : f.region, tier: f.tier,
        industry: node === 'none' ? 'none' : node.startsWith('ind:') ? node.slice(4) : node.startsWith('seg:') ? node.split(':')[1] : undefined,
        segment_id: node.startsWith('seg:') ? node.split(':')[2] : undefined,
        bench: f.bench ? 1 : undefined, enrich: f.enrich, keyword: f.keyword, sort: f.sort, has_people: f.has_people ? '1' : '0' });
      if (r?.success) { setData(r.data); onCanEdit(!!r.data.can_admin); } else showErr(r, t);
    } finally { setLoading(false); }
  }, [f, page, t, onCanEdit]);
  const canAdmin = !!data.can_admin;
  const segs = data.segments || segments;   // 列表接口带了各赛道企业数
  useEffect(() => { load(); }, [load, reloadKey]);
  const set = (p: any) => { setPage(1); setF((x: any) => ({ ...x, ...p })); };
  const num = (r: any, b: string, color?: string) => {
    const n = Number(r.counts?.[b] || 0);
    return n ? <a style={{ color, fontWeight: 600 }} onClick={(e) => { e.stopPropagation(); setOpen({ id: Number(r.id), bucket: b }); }}>{n}</a> : <Text type="secondary">0</Text>;
  };

  return (
    <>
      <style>{`.recruit-row:hover > td, .recruit-row > td.ant-table-cell-row-hover { background:#eef4fb !important; cursor:pointer }`}</style>
      <StatusBar t={t} st={data.status} canAdmin={canAdmin} onChanged={load} />
      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
      <IndustryTree t={t} facets={data.facets} segments={segs} value={f.node} onChange={(k) => set({ node: k })} />
      <div style={{ flex: 1, minWidth: 0 }}>
      <Space wrap style={{ marginBottom: 12 }}>
        <Segmented value={f.region} onChange={(v) => set({ region: v })}
          options={['all', 'local', 'overseas'].map((x) => ({ value: x, label: x === 'all' ? t('pages.recruit.co.allRegions') : t(`pages.recruit.region.${x}`) }))} />
        <Select allowClear style={{ width: 120 }} placeholder={t('pages.recruit.co.tier')} value={f.tier} onChange={(v) => set({ tier: v })}
          options={(enums?.tier || []).map((x: string) => ({ value: x, label: t(`pages.recruit.tier.${x}`) }))} />
        <Select allowClear style={{ width: 130 }} placeholder={t('pages.recruit.co.enrich')} value={f.enrich} onChange={(v) => set({ enrich: v })}
          options={['queued', 'done', 'failed'].map((x) => ({ value: x, label: t(`pages.recruit.co.enrichStatus.${x}`) }))} />
        <Select style={{ width: 150 }} value={f.sort} onChange={(v) => set({ sort: v })}
          options={[{ value: '', label: t('pages.recruit.co.sortPeople') }, { value: 'heat', label: t('pages.recruit.co.sortHeat') }, { value: 'rank', label: t('pages.recruit.co.sortRank') }, { value: 'name', label: t('pages.recruit.co.sortName') }]} />
        <Input allowClear prefix={<SearchOutlined />} style={{ width: 220 }} placeholder={t('pages.recruit.co.searchPh')}
          onPressEnter={(e) => set({ keyword: (e.target as HTMLInputElement).value || undefined })} onChange={(e) => { if (!e.target.value) set({ keyword: undefined }); }} />
        <Checkbox checked={!!f.bench} onChange={(e) => set({ bench: e.target.checked })}>{t('pages.recruit.co.onlyBench')}</Checkbox>
        <Checkbox checked={f.has_people} onChange={(e) => set({ has_people: e.target.checked })}>{t('pages.recruit.co.hasPeople')}</Checkbox>
        <Segmented value={view} onChange={(v) => { setView(String(v)); localStorage.setItem('recruit.companies.view', String(v)); }}
          options={[{ value: 'cards', icon: <AppstoreOutlined />, label: t('pages.recruit.co.viewCards') }, { value: 'table', icon: <UnorderedListOutlined />, label: t('pages.recruit.co.viewTable') }]} />
        {canAdmin && <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreating(true)}>{t('pages.recruit.co.new')}</Button>}
      </Space>
      {view === 'cards' ? (
        <Spin spinning={loading}>
          <style>{`.co-masonry{column-count:4;column-gap:16px}
            @media (max-width:1900px){.co-masonry{column-count:3}} @media (max-width:1450px){.co-masonry{column-count:2}} @media (max-width:1000px){.co-masonry{column-count:1}}
            .co-card{break-inside:avoid;margin-bottom:16px;border-radius:10px;transition:all .15s}
            .co-card:hover{border-color:#c9d2ff;box-shadow:0 4px 14px rgba(26,42,214,.08)}
            .co-sum{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;font-size:12px;color:#595959;line-height:1.6}
            .co-stat{flex:1;text-align:center;padding:6px 0;border-radius:6px;background:#f7f8fc;cursor:pointer;transition:background .15s}
            .co-stat:hover{background:#eef1ff}
            .co-stat b{display:block;font-size:18px;line-height:1.3}.co-stat span{font-size:12px;color:#8c8c8c}`}</style>
          {data.rows.length === 0 && !loading ? <Empty /> : (
            <div className="co-masonry">
              {data.rows.map((r: any) => {
                const c = r.counts || {};
                const leaders = (r.org?.leaders || []).slice(0, 2);
                const stat = (b: string, color?: string) => (
                  <div className="co-stat" onClick={(e) => { e.stopPropagation(); setOpen({ id: Number(r.id), bucket: b }); }}>
                    <b style={{ color: Number(c[b]) > 0 ? color : '#bfbfbf' }}>{c[b] ?? 0}</b><span>{t(`pages.recruit.co.bucket.${b}`)}</span>
                  </div>);
                const color = industryColor(r.industry);
                const hl = heatLevel(Number(r.heat || 0));
                return (
                  <Card key={r.id} size="small" hoverable className="co-card" onClick={() => setOpen({ id: Number(r.id) })}
                    style={{ borderTop: `3px solid ${color}`, overflow: 'hidden' }}
                    bodyStyle={{ display: 'flex', flexDirection: 'column', gap: 10, background: `linear-gradient(180deg, ${color}14 0, #fff 72px)` }}>
                    {/* 行业插画 + 热度火苗（人多 / 在职多 / 正在我们流程里的多 → 越热） */}
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <IndustryArt code={r.industry} size={44} />
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <Text style={{ fontSize: 12, color, fontWeight: 600 }}>{r.industry ? t(`pages.recruit.industry.${r.industry}`) : t('pages.recruit.co.unclassified')}</Text>
                        {r.segment && <Text type="secondary" style={{ fontSize: 12 }}> · {segName(r.segment, intl.locale)}</Text>}
                      </div>
                      {hl > 0 && (
                        <Tooltip title={t('pages.recruit.co.heatTip', { heat: r.heat, pipe: r.pipeline || 0 })}>
                          <span style={{ whiteSpace: 'nowrap' }}>{Array.from({ length: hl }).map((_, i) => <FireFilled key={i} style={{ color: hl === 3 ? '#F5222D' : hl === 2 ? '#FA541C' : '#FAAD14' }} />)}</span>
                        </Tooltip>)}
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                      <div style={{ minWidth: 0 }}>
                        <Text strong style={{ fontSize: 15, wordBreak: 'break-word' }}>{r.name}</Text>
                        <Space size={6} style={{ marginLeft: 6 }}>
                          {!!r.website && <a href={r.website} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()}><GlobalOutlined /></a>}
                          {!!r.linkedin_url && <a href={r.linkedin_url} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()}><LinkedinOutlined /></a>}
                        </Space>
                      </div>
                      {r.rank_in_segment ? <Tag color="gold" style={{ marginInlineEnd: 0, height: 'fit-content' }}>#{r.rank_in_segment}</Tag> : null}
                    </div>
                    <Space size={[4, 4]} wrap>
                      {r.region && <Tag color={REGION_COLOR[r.region]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.region.${r.region}`)}{r.country ? ` · ${r.country}` : ''}</Tag>}
                      {r.tier && <Tag color={TIER_COLOR[r.tier]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.tier.${r.tier}`)}</Tag>}
                      {r.class_conf === 'notco' && <Tag color="red" style={{ marginInlineEnd: 0 }}>{t('pages.recruit.co.notCompany')}</Tag>}
                      {!!r.suggest_segment && !r.segment && <Tooltip title={t('pages.recruit.co.suggestSegTip')}><Tag color="cyan" style={{ marginInlineEnd: 0 }}>{t('pages.recruit.co.suggestSeg', { s: r.suggest_segment })}</Tag></Tooltip>}
                    </Space>
                    {(r.employee_range || r.hq_city || r.parent_group) && (
                      <Text type="secondary" style={{ fontSize: 12 }}>
                        {[r.employee_range ? `${t('pages.recruit.co.size')} ${r.employee_range}` : '', r.hq_city || '', r.parent_group].filter(Boolean).join(' · ')}
                      </Text>)}
                    {(r.summary?.[lang] || r.summary?.zh || r.summary?.en) ? <div className="co-sum">{r.summary?.[lang] || r.summary?.zh || r.summary?.en}</div> : null}
                    <div style={{ display: 'flex', gap: 6 }}>{stat('current', '#389e0d')}{stat('former', color)}{stat('long', '#1a2ad6')}</div>
                    {Number(r.pipeline) > 0 && <Text style={{ fontSize: 12, color: '#722ed1' }}>{t('pages.recruit.co.inPipeline', { n: r.pipeline })}</Text>}
                    {Object.keys(r.counts?.funcs || {}).length > 0 && (
                      <Space size={[4, 4]} wrap>
                        {Object.entries(r.counts.funcs).map(([k, n]: any) => (
                          <Tag key={k} style={{ cursor: 'pointer', marginInlineEnd: 0 }} onClick={(e) => { e.stopPropagation(); setOpen({ id: Number(r.id), func: k }); }}>
                            {t(`pages.recruit.func.${k}`)} {n}</Tag>))}
                      </Space>)}
                    {leaders.length > 0 && (
                      <div style={{ fontSize: 12 }}><TeamOutlined style={{ color: '#8c8c8c', marginRight: 4 }} />
                        {leaders.map((l: any) => `${l.name}${l.title ? ` · ${l.title}` : ''}`).join('；')}</div>)}
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderTop: '1px solid #f0f0f0', paddingTop: 8, fontSize: 12 }}>
                      <Text type="secondary">
                        {!!r.class_source && <Tag color={CLASS_COLOR[r.class_source]} style={{ fontSize: 11, marginInlineEnd: 4 }}>{t(`pages.recruit.co.cls.${r.class_source}`)}</Tag>}
                        {r.enriched_at ? `${t('pages.recruit.co.updated')} ${fmtDay(r.enriched_at)}` : t(`pages.recruit.co.enrichStatus.${r.enrich_status}`)}
                        {r.enrich_meta?.cost_usd !== undefined ? ` · $${Number(r.enrich_meta.cost_usd).toFixed(3)}` : ''}</Text>
                      {canAdmin && <Button size="small" type="link" icon={<EditOutlined />} onClick={(e) => { e.stopPropagation(); setOpen({ id: Number(r.id), edit: true }); }}>{t('pages.recruit.edit')}</Button>}
                    </div>
                  </Card>);
              })}
            </div>)}
          {data.total > 30 && (
            <div style={{ textAlign: 'right' }}>
              <Pagination current={page} pageSize={30} total={data.total} showSizeChanger={false} onChange={setPage} showTotal={(n) => t('pages.recruit.total', { n })} />
            </div>)}
        </Spin>
      ) : (
      <Table rowKey="id" loading={loading} dataSource={data.rows} size="middle" tableLayout="fixed"
        rowClassName={() => 'recruit-row'} onRow={(r: any) => ({ onClick: () => setOpen({ id: Number(r.id) }) })}
        pagination={{ current: page, pageSize: 30, total: data.total, showSizeChanger: false, onChange: setPage, showTotal: (n) => t('pages.recruit.total', { n }) }}
        columns={[
          { title: t('pages.recruit.co.rank'), width: 70, align: 'center', render: (_: any, r: any) => r.rank_in_segment ? <Tag color="gold" style={{ marginInlineEnd: 0 }}>#{r.rank_in_segment}</Tag> : '' },
          { title: t('pages.recruit.co.company'), render: (_: any, r: any) => (
              <div style={{ wordBreak: 'break-word' }}>
                <Text strong>{r.name}</Text>
                {!!r.website && <a href={r.website} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()} style={{ marginLeft: 6 }}><GlobalOutlined /></a>}
                <div style={{ marginTop: 2 }}>
                  <Space size={4} wrap>
                    {r.region && <Tag color={REGION_COLOR[r.region]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.region.${r.region}`)}{r.country ? ` · ${r.country}` : ''}</Tag>}
                    {r.tier && <Tag color={TIER_COLOR[r.tier]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.tier.${r.tier}`)}</Tag>}
                    {Number(r.alias_count) > 1 && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.co.aliasN', { n: r.alias_count })}</Text>}
                  </Space>
                </div>
              </div>) },
          { title: t('pages.recruit.co.segment'), width: 170, render: (_: any, r: any) => (
              <div style={{ fontSize: 12 }}>
                <div>{r.segment ? segName(r.segment, intl.locale) : <Text type="secondary">-</Text>}</div>
                {r.industry && <Text type="secondary">{t(`pages.recruit.industry.${r.industry}`)}</Text>}
                {r.employee_range && <div><Text type="secondary">{t('pages.recruit.co.size')} {r.employee_range}</Text></div>}
              </div>) },
          { title: t('pages.recruit.co.bucket.current'), width: 70, align: 'center', render: (_: any, r: any) => num(r, 'current', '#389e0d') },
          { title: t('pages.recruit.co.bucket.former'), width: 80, align: 'center', render: (_: any, r: any) => num(r, 'former') },
          { title: <Tooltip title={t('pages.recruit.co.longTip', { n: data.long_months })}>{t('pages.recruit.co.bucket.long')}</Tooltip>, width: 80, align: 'center',
            render: (_: any, r: any) => num(r, 'long', '#1a2ad6') },
          { title: t('pages.recruit.co.funcs'), width: 230, render: (_: any, r: any) => (
              <Space size={[4, 4]} wrap>
                {Object.entries(r.counts?.funcs || {}).slice(0, 4).map(([k, n]: any) => (
                  <Tag key={k} style={{ cursor: 'pointer', marginInlineEnd: 0 }} onClick={(e) => { e.stopPropagation(); setOpen({ id: Number(r.id), func: k }); }}>
                    {t(`pages.recruit.func.${k}`)} {n}</Tag>))}
              </Space>) },
          { title: t('pages.recruit.co.enrich'), width: 130, render: (_: any, r: any) => (
              <div style={{ fontSize: 12 }}>
                <Tag color={{ done: 'green', failed: 'red', queued: 'default', running: 'processing' }[r.enrich_status as string]} style={{ marginInlineEnd: 0 }}>
                  {t(`pages.recruit.co.enrichStatus.${r.enrich_status}`)}</Tag>
                {!!r.enriched_at && <div><Text type="secondary">{fmtDay(r.enriched_at)}{r.enrich_meta?.cost_usd !== undefined ? ` · $${Number(r.enrich_meta.cost_usd).toFixed(3)}` : ''}</Text></div>}
              </div>) },
        ]} />
      )}
      </div>
      </div>
      <CompanyDrawer open={!!open} companyId={open?.id || null} initBucket={open?.bucket} initFunc={open?.func} initEdit={open?.edit} meta={meta} segments={segments} enums={enums}
        onClose={() => setOpen(null)} onChanged={load} />
      <CompanyForm open={creating} segments={segments} meta={{ ...enums, industry: meta?.enums?.industry }} t={t} onClose={() => setCreating(false)}
        onSaved={(id) => { setCreating(false); load(); setOpen({ id }); }} />
    </>
  );
};

// ====================================================================== 职位归类
const TitlesTab: React.FC<{ t: T; enums: any }> = ({ t, enums }) => {
  const [f, setF] = useState<any>({});
  const [page, setPage] = useState(1);
  const [data, setData] = useState<any>({ rows: [], total: 0 });
  const [loading, setLoading] = useState(false);
  const load = useCallback(async () => {
    setLoading(true);
    try { const r = await recruitTitleFunctions({ ...f, page, pageSize: 50 }); if (r?.success) setData(r.data); else showErr(r, t); } finally { setLoading(false); }
  }, [f, page, t]);
  useEffect(() => { load(); }, [load]);
  const save = async (r: any, patch: any) => {
    const res = await recruitSetTitleFunction({ title_key: r.title_key, job_function: patch.job_function ?? r.job_function ?? 'other', seniority: patch.seniority ?? r.seniority ?? 'staff' });
    if (res?.success) { message.success(t('pages.recruit.msg.saved')); load(); } else showErr(res, t);
  };
  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <Input allowClear prefix={<SearchOutlined />} style={{ width: 240 }} placeholder={t('pages.recruit.co.titlePh')}
          onPressEnter={(e) => { setPage(1); setF({ ...f, keyword: (e.target as HTMLInputElement).value || undefined }); }} />
        <Select allowClear style={{ width: 160 }} placeholder={t('pages.recruit.co.func')} value={f.func} onChange={(v) => { setPage(1); setF({ ...f, func: v }); }}
          options={(enums?.job_function || []).map((x: string) => ({ value: x, label: t(`pages.recruit.func.${x}`) }))} />
        <Select allowClear style={{ width: 140 }} placeholder={t('pages.recruit.co.source')} value={f.source} onChange={(v) => { setPage(1); setF({ ...f, source: v }); }}
          options={['ai', 'manual', 'pending'].map((x) => ({ value: x, label: t(`pages.recruit.co.src.${x}`) }))} />
        <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.co.titlesHint')}</Text>
      </Space>
      <DetailTable rowKey="title_key" loading={loading} dataSource={data.rows} {...NO_HSCROLL}
        pagination={{ current: page, pageSize: 50, total: data.total, showSizeChanger: false, onChange: setPage }}
        columns={[
          { title: t('pages.recruit.co.jobTitle'), render: (_: any, r: any) => <Text style={{ wordBreak: 'break-word' }}>{r.title}</Text> },
          { title: t('pages.recruit.co.peopleN'), width: 80, align: 'center' as const, dataIndex: 'people' },
          { title: t('pages.recruit.co.func'), width: 190, render: (_: any, r: any) => (
              <Select size="small" style={{ width: 170 }} value={r.source === 'fallback' ? undefined : (r.job_function || undefined)} placeholder={t('pages.recruit.co.src.pending')}
                onChange={(v) => save(r, { job_function: v })} options={(enums?.job_function || []).map((x: string) => ({ value: x, label: t(`pages.recruit.func.${x}`) }))} />) },
          { title: t('pages.recruit.co.seniority'), width: 150, render: (_: any, r: any) => (
              <Select size="small" style={{ width: 130 }} value={r.seniority || undefined} placeholder="-" disabled={!r.job_function || r.source === 'fallback'}
                onChange={(v) => save(r, { seniority: v })} options={(enums?.seniority || []).map((x: string) => ({ value: x, label: t(`pages.recruit.seniority.${x}`) }))} />) },
          { title: t('pages.recruit.co.source'), width: 90, render: (_: any, r: any) => (
              <Tag color={r.source === 'manual' ? 'blue' : r.source === 'ai' ? 'purple' : 'default'}>{t(`pages.recruit.co.src.${r.source === 'fallback' ? 'pending' : (r.source || 'pending')}`)}</Tag>) },
        ]} />
    </>
  );
};

// ====================================================================== AI 生成赛道（对话）
type Msg = { role: 'user' | 'ai'; text: string };
const SegmentChat: React.FC<{ t: T; meta: any; onApplied: () => void }> = ({ t, meta, onApplied }) => {
  const [msgs, setMsgs] = useState<Msg[]>([]);
  const [input, setInput] = useState('');
  const [industry, setIndustry] = useState<string>();
  const [busy, setBusy] = useState(false);
  const [plan, setPlan] = useState<any>(null);   // {segments, companies}
  const [applying, setApplying] = useState(false);
  const send = async (text: string) => {
    setBusy(true);
    const history = msgs;
    if (text) setMsgs((m) => [...m, { role: 'user', text }]);
    try {
      const r = await recruitSegmentChat({ message: text, industry, history });
      if (r?.success) {
        setPlan(r.data.empty ? null : r.data);
        setMsgs((m) => [...m, { role: 'ai', text: r.data.empty ? t('pages.recruit.co.seg.nothing') : (r.data.reply || t('pages.recruit.co.seg.planReady', { n: r.data.segments.length })) }]);
      } else showErr(r, t);
    } finally { setBusy(false); setInput(''); }
  };
  const dropMember = (si: number, cid: number) => setPlan((p: any) => ({ ...p, segments: p.segments.map((sg: any, i: number) => (i === si ? { ...sg, members: sg.members.filter((x: number) => x !== cid) } : sg)) }));
  const dropSeg = (si: number) => setPlan((p: any) => ({ ...p, segments: p.segments.filter((_: any, i: number) => i !== si) }));
  return (
    <Card size="small" style={{ marginBottom: 16, background: 'linear-gradient(180deg,#f5f7ff 0,#fff 120px)' }}
      title={<Space><RobotOutlined style={{ color: '#1a2ad6' }} />{t('pages.recruit.co.seg.title')}</Space>}>
      <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.co.seg.hint')}</Text>
      <div style={{ maxHeight: 260, overflow: 'auto', margin: '10px 0' }}>
        {msgs.map((m, i) => (
          <div key={i} style={{ display: 'flex', justifyContent: m.role === 'user' ? 'flex-end' : 'flex-start', marginBottom: 6 }}>
            <div style={{ maxWidth: '80%', padding: '6px 10px', borderRadius: 10, whiteSpace: 'pre-wrap', fontSize: 13,
              background: m.role === 'user' ? '#1a2ad6' : '#fff', color: m.role === 'user' ? '#fff' : '#262626', border: m.role === 'user' ? 'none' : '1px solid #e8ecff' }}>{m.text}</div>
          </div>))}
      </div>
      <Space.Compact style={{ width: '100%' }}>
        <Select allowClear style={{ width: 170 }} placeholder={t('pages.recruit.co.seg.allIndustry')} value={industry} onChange={setIndustry}
          options={(meta?.enums?.industry || []).map((x: string) => ({ value: x, label: t(`pages.recruit.industry.${x}`) }))} />
        <Input value={input} onChange={(e) => setInput(e.target.value)} onPressEnter={() => !busy && send(input.trim())} placeholder={t('pages.recruit.co.seg.ph')} disabled={busy} />
        <Button type="primary" icon={<SendOutlined />} loading={busy} onClick={() => send(input.trim())}>{input.trim() ? t('pages.recruit.co.seg.send') : t('pages.recruit.co.seg.auto')}</Button>
      </Space.Compact>
      {plan?.segments?.length > 0 && (
        <div style={{ marginTop: 12 }}>
          <Space direction="vertical" style={{ width: '100%' }} size={8}>
            {plan.segments.map((sg: any, i: number) => (
              <Card key={i} size="small" style={{ borderLeft: `3px solid ${industryColor(sg.industry)}` }}
                title={<Space size={6} wrap><IndustryArt code={sg.industry} size={22} />
                  <Text strong>{sg.existing_id ? t('pages.recruit.co.seg.existing') : ''}{sg.name_zh}</Text>
                  <Text type="secondary" style={{ fontSize: 12 }}>{[sg.name_en, sg.name_id].filter(Boolean).join(' / ')}</Text>
                  {sg.industry && <Tag color={industryColor(sg.industry)}>{t(`pages.recruit.industry.${sg.industry}`)}</Tag>}</Space>}
                extra={<Button size="small" type="link" danger onClick={() => dropSeg(i)}>{t('pages.recruit.co.seg.drop')}</Button>}>
                {!!sg.description && <div style={{ fontSize: 12, color: '#595959', marginBottom: 6 }}>{sg.description}</div>}
                <Space size={[4, 4]} wrap>
                  {sg.members.map((cid: number) => <Tag key={cid} closable onClose={(e) => { e.preventDefault(); dropMember(i, cid); }}>{plan.companies?.[cid] || `#${cid}`}</Tag>)}
                </Space>
              </Card>))}
          </Space>
          <div style={{ marginTop: 10, textAlign: 'right' }}>
            <Button type="primary" loading={applying} onClick={async () => {
              setApplying(true);
              try {
                const r = await recruitSegmentApply(plan.segments);
                if (r?.success) { message.success(t('pages.recruit.co.seg.applied', { c: r.data.created, a: r.data.assigned, s: r.data.skipped })); setPlan(null); onApplied(); }
                else showErr(r, t);
              } finally { setApplying(false); }
            }}>{t('pages.recruit.co.seg.apply')}</Button>
          </div>
        </div>)}
    </Card>
  );
};

// ====================================================================== 赛道
const SegmentsTab: React.FC<{ t: T; meta: any; segments: any[]; onChanged: () => void }> = ({ t, meta, segments, onChanged }) => {
  const intl = useIntl();
  const [edit, setEdit] = useState<any>(null);
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (!edit) return;
    form.resetFields();
    form.setFieldsValue({ name_zh: edit.name_zh, name_en: edit.name_en, name_id: edit.name_id, industry: edit.industry || undefined, sort: Number(edit.sort || 0), active: edit.id ? !!Number(edit.active) : true });
  }, [edit, form]);
  const submit = async () => {
    const v = await form.validateFields();
    setSaving(true);
    try {
      const r = await recruitSaveSegment({ id: edit.id || 0, ...v, industry: v.industry || '', inactive: v.active ? 0 : 1 });
      if (r?.success) { message.success(t('pages.recruit.msg.saved')); setEdit(null); onChanged(); } else showErr(r, t);
    } finally { setSaving(false); }
  };
  return (
    <>
      <SegmentChat t={t} meta={meta} onApplied={onChanged} />
      <div style={{ marginBottom: 12 }}><Button icon={<PlusOutlined />} onClick={() => setEdit({})}>{t('pages.recruit.co.newSegment')}</Button>
        <Text type="secondary" style={{ fontSize: 12, marginLeft: 8 }}>{t('pages.recruit.co.segmentHint')}</Text></div>
      <DetailTable rowKey="id" dataSource={segments} pagination={false} {...NO_HSCROLL} rowClassName={() => 'recruit-row'} onRow={(r: any) => ({ onClick: () => setEdit(r) })}
        columns={[
          { title: t('pages.recruit.co.segment'), render: (_: any, r: any) => (
              <div><Space size={6}><IndustryArt code={r.industry} size={20} /><Text strong>{segName(r, intl.locale)}</Text>
                {r.source === 'ai' && <Tag color="purple">AI</Tag>}{!Number(r.active) && <Tag>{t('pages.recruit.co.inactive')}</Tag>}</Space>
                {!!r.description && <div style={{ fontSize: 12, color: '#8c8c8c' }}>{r.description}</div>}</div>) },
          { title: '中文 / English / Indonesia', render: (_: any, r: any) => <Text type="secondary" style={{ fontSize: 12 }}>{[r.name_zh, r.name_en, r.name_id].filter(Boolean).join(' / ')}</Text> },
          { title: t('pages.recruit.p.industries'), width: 180, render: (_: any, r: any) => (r.industry ? t(`pages.recruit.industry.${r.industry}`) : '-') },
          { title: t('pages.recruit.co.companiesN'), width: 90, align: 'center' as const, dataIndex: 'companies' },
          { title: t('pages.recruit.co.sort'), width: 70, align: 'center' as const, dataIndex: 'sort' },
        ]} />
      <Drawer open={!!edit} onClose={() => setEdit(null)} width={DETAIL_WIDTH.form} destroyOnClose title={edit?.id ? t('pages.recruit.co.editSegment') : t('pages.recruit.co.newSegment')}
        extra={<Space><Button onClick={() => setEdit(null)}>{t('pages.recruit.cancel')}</Button><Button type="primary" loading={saving} onClick={submit}>{t('pages.recruit.save')}</Button></Space>}>
        <Form form={form} layout="vertical">
          <Form.Item name="name_zh" label="中文" rules={[{ required: true, message: t('pages.recruit.err.nameRequired') }]}><Input maxLength={64} placeholder="美妆" /></Form.Item>
          <Form.Item name="name_en" label="English"><Input maxLength={64} placeholder="Beauty & Cosmetics" /></Form.Item>
          <Form.Item name="name_id" label="Indonesia"><Input maxLength={64} placeholder="Kecantikan & Kosmetik" /></Form.Item>
          <Form.Item name="industry" label={t('pages.recruit.p.industries')}>
            <Select allowClear showSearch optionFilterProp="label" options={(meta?.enums?.industry || []).map((x: string) => ({ value: x, label: t(`pages.recruit.industry.${x}`) }))} />
          </Form.Item>
          <Space>
            <Form.Item name="sort" label={t('pages.recruit.co.sort')}><InputNumber min={0} max={999} /></Form.Item>
            <Form.Item name="active" label={t('pages.recruit.co.active')} valuePropName="checked"><Switch /></Form.Item>
          </Space>
        </Form>
      </Drawer>
    </>
  );
};

// ====================================================================== 不建档名单
const IgnoredTab: React.FC<{ t: T; onChanged: () => void }> = ({ t, onChanged }) => {
  const [rows, setRows] = useState<any[]>([]);
  const load = useCallback(() => { recruitCompanyIgnored().then((r: any) => (r?.success ? setRows(r.data || []) : showErr(r, t))); }, [t]);
  useEffect(() => { load(); }, [load]);
  return (
    <>
      <Text type="secondary" style={{ display: 'block', marginBottom: 10, fontSize: 12 }}>{t('pages.recruit.co.ignoredHint')}</Text>
      <DetailTable rowKey="id" dataSource={rows} pagination={false} {...NO_HSCROLL}
        columns={[
          { title: t('pages.recruit.co.name'), dataIndex: 'sample' },
          { title: t('pages.recruit.co.addedAt'), width: 140, render: (_: any, r: any) => fmtDay(r.created_at) },
          { title: '', width: 100, render: (_: any, r: any) => (
              <Popconfirm title={t('pages.recruit.co.restoreConfirm')} onConfirm={async () => { const x = await recruitUnignoreCompany(Number(r.id)); if (x?.success) { load(); onChanged(); } else showErr(x, t); }}>
                <Button size="small" type="link">{t('pages.recruit.co.restore')}</Button>
              </Popconfirm>) },
        ]} />
    </>
  );
};

// ====================================================================== 检索（照 AI 销售助手·客户情报：输入 → 逐步进度 → 入库）
const STAGES = ['queued', 'search', 'scrape', 'analyze', 'done'];
const ResearchTab: React.FC<{ t: T; meta: any; segments: any[]; enums: any }> = ({ t, meta, segments, enums }) => {
  const intl = useIntl();
  const [name, setName] = useState('');
  const [job, setJob] = useState<any>(null);   // {id, status, stage, error, name}
  const [recent, setRecent] = useState<any[]>([]);
  const [open, setOpen] = useState<number | null>(null);
  const loadRecent = useCallback(() => { recruitCompanyRecent().then((r: any) => r?.success && setRecent(r.data || [])); }, []);
  useEffect(() => { loadRecent(); }, [loadRecent]);
  useEffect(() => {
    if (!job?.id || ['done', 'failed'].includes(job.status)) return;
    const started = Date.now();
    const timer = setInterval(async () => {
      const r = await recruitCompanyResearchStatus(job.id);
      if (!r?.success) return;
      const d = r.data;
      const status = d.enrich_status === 'done' ? 'done' : d.enrich_status === 'failed' ? 'failed' : 'running';
      setJob((j: any) => ({ ...j, status, stage: d.enrich_meta?.stage || j.stage, error: d.enrich_error, meta: d.enrich_meta, name: d.name }));
      if (status !== 'running') { clearInterval(timer); loadRecent(); if (status === 'done') setOpen(job.id); }
      else if (Date.now() - started > 240000) { clearInterval(timer); setJob((j: any) => ({ ...j, status: 'slow' })); }
    }, 2000);
    return () => clearInterval(timer);
  }, [job?.id, job?.status, loadRecent]);   // eslint-disable-line react-hooks/exhaustive-deps
  const go = async () => {
    if (!name.trim()) return;
    const r = await recruitCompanyResearch({ name: name.trim() });
    if (r?.success && r.data.recent) { message.info(t('pages.recruit.co.researchRecent')); setOpen(Number(r.data.id)); }   // 30 天内查过：直接看结果，不再花钱
    else if (r?.success) setJob({ id: r.data.id, status: 'running', stage: 'queued', spawned: r.data.spawned, name: name.trim() });
    else showErr(r, t);
  };
  const cur = job?.status === 'done' ? 4 : Math.max(0, STAGES.indexOf(job?.stage || 'queued'));
  return (
    <div>
      <Card size="small" style={{ marginBottom: 16 }}>
        <Space.Compact style={{ width: '100%', maxWidth: 720 }}>
          <Input size="large" value={name} onChange={(e) => setName(e.target.value)} onPressEnter={go} placeholder={t('pages.recruit.co.researchPh')} allowClear />
          <Button size="large" type="primary" icon={<SearchOutlined />} disabled={!name.trim() || job?.status === 'running'} onClick={go}>{t('pages.recruit.co.research')}</Button>
        </Space.Compact>
        <div style={{ marginTop: 6 }}><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.co.researchHint')}</Text></div>
        {job && (
          <div style={{ marginTop: 16, maxWidth: 720 }}>
            <Steps direction="vertical" size="small" current={cur} status={job.status === 'failed' ? 'error' : job.status === 'done' ? 'finish' : 'process'}
              items={STAGES.map((s) => ({ title: t(`pages.recruit.co.stage.${s}`), description: s === 'queued' && job.spawned === false ? t('pages.recruit.co.stage.queuedCron') : undefined }))} />
            {job.status === 'failed' && <Alert type="error" showIcon message={t(`pages.recruit.co.enrichErr.${['ambiguous', 'not_found', 'no_results'].includes(job.error) ? job.error : 'other'}`)} description={job.error} />}
            {job.status === 'slow' && <Alert type="warning" showIcon message={t('pages.recruit.co.researchSlow')} />}
            {job.status === 'done' && job.meta?.cost_usd !== undefined && (
              <Text type="secondary">{t('pages.recruit.co.enrichCost', { cost: Number(job.meta.cost_usd).toFixed(3), s: job.meta.searches, p: job.meta.pages, sec: Math.round((job.meta.elapsed_ms || 0) / 1000) })}</Text>)}
          </div>)}
      </Card>
      <Text strong>{t('pages.recruit.co.recent')}</Text>
      <DetailTable rowKey="id" dataSource={recent} pagination={false} {...NO_HSCROLL} style={{ marginTop: 8 }}
        rowClassName={() => 'recruit-row'} onRow={(r: any) => ({ onClick: () => setOpen(Number(r.id)) })}
        columns={[
          { title: t('pages.recruit.co.company'), render: (_: any, r: any) => (
              <div><Text strong>{r.name}</Text>
                <div style={{ fontSize: 12, color: '#595959' }}>{r.summary?.[intl.locale === 'id-ID' ? 'id' : intl.locale === 'en-US' ? 'en' : 'zh'] || ''}</div></div>) },
          { title: t('pages.recruit.co.region'), width: 140, render: (_: any, r: any) => r.region ? <Tag color={REGION_COLOR[r.region]}>{t(`pages.recruit.region.${r.region}`)}{r.country ? ` · ${r.country}` : ''}</Tag> : '-' },
          { title: t('pages.recruit.p.industries'), width: 160, render: (_: any, r: any) => (r.industry ? t(`pages.recruit.industry.${r.industry}`) : '-') },
          { title: t('pages.recruit.co.enrich'), width: 170, render: (_: any, r: any) => (
              <div style={{ fontSize: 12 }}><Tag color={r.enrich_status === 'done' ? 'green' : r.enrich_status === 'failed' ? 'red' : 'default'}>{t(`pages.recruit.co.enrichStatus.${r.enrich_status}`)}</Tag>
                {fmtDay(r.enriched_at)}{r.enrich_meta?.cost_usd !== undefined ? ` · $${Number(r.enrich_meta.cost_usd).toFixed(3)}` : ''}</div>) },
        ]} />
      <CompanyDrawer open={open !== null} companyId={open} meta={meta} segments={segments} enums={enums} onClose={() => setOpen(null)} onChanged={loadRecent} />
    </div>
  );
};

// ====================================================================== 查看权限（名单制，admin 不自动放行）
const ViewersTab: React.FC<{ t: T }> = ({ t }) => {
  const [ids, setIds] = useState<number[]>([]);
  const [users, setUsers] = useState<any[]>([]);
  const [saving, setSaving] = useState(false);
  useEffect(() => { recruitCompanyViewers().then((r: any) => { if (r?.success) { setIds(r.data.ids || []); setUsers(r.data.users || []); } else showErr(r, t); }); }, [t]);
  return (
    <div style={{ maxWidth: 720 }}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.co.viewersHint')} />
      <Select mode="multiple" style={{ width: '100%' }} value={ids} onChange={setIds} optionFilterProp="label" showSearch
        options={users.map((u) => ({ value: Number(u.id), label: `${u.name}（${u.username}）` }))} />
      <div style={{ marginTop: 12 }}>
        <Button type="primary" loading={saving} onClick={async () => {
          setSaving(true);
          try { const r = await recruitSaveCompanyViewers(ids); if (r?.success) message.success(t('pages.recruit.msg.saved')); else showErr(r, t); } finally { setSaving(false); }
        }}>{t('pages.recruit.save')}</Button>
      </div>
    </div>
  );
};

const Companies: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [meta, setMeta] = useState<any>(null);
  const [segments, setSegments] = useState<any[]>([]);
  const [reloadKey, setReloadKey] = useState(0);
  const [relinking, setRelinking] = useState(false);
  useEffect(() => { recruitMeta().then((r: any) => (r?.success ? setMeta(r.data) : showErr(r, t))); }, [t]);
  const loadSegments = useCallback(() => { recruitSegments().then((r: any) => r?.success && setSegments(r.data || [])); }, []);
  useEffect(() => { loadSegments(); }, [loadSegments]);
  const enums = meta?.co || {};
  const [canAdmin, setCanAdmin] = useState(false);   // 名单里 + 招聘管理员（列表接口返回）
  if (meta && !enums.ready) return <Card><Alert type="warning" showIcon message={t('pages.recruit.err.companyPending')} /></Card>;
  return (
    <Card title={t('menu.recruit.companies')} extra={canAdmin && (
      <Tooltip title={t('pages.recruit.co.relinkTip')}>
        <Button icon={<SyncOutlined spin={relinking} />} loading={relinking} onClick={async () => {
          setRelinking(true);
          try { const r = await recruitCompanyRelink(); if (r?.success) { message.success(t('pages.recruit.co.relinkDone', { n: r.data.candidates, c: r.data.created })); setReloadKey((k) => k + 1); } else showErr(r, t); }
          finally { setRelinking(false); }
        }}>{t('pages.recruit.co.relink')}</Button>
      </Tooltip>)}>
      <Tabs items={[
        { key: 'list', label: t('pages.recruit.co.tabList'), children: meta && <CompanyList t={t} meta={meta} segments={segments} enums={enums} onCanEdit={setCanAdmin} reloadKey={reloadKey} /> },
        { key: 'research', label: t('pages.recruit.co.tabResearch'), children: meta && <ResearchTab t={t} meta={meta} segments={segments} enums={enums} /> },
        { key: 'titles', label: t('pages.recruit.co.tabTitles'), children: meta && <TitlesTab t={t} enums={enums} /> },
        ...(canAdmin ? [
          { key: 'segments', label: t('pages.recruit.co.tabSegments'), children: <SegmentsTab t={t} meta={meta} segments={segments} onChanged={loadSegments} /> },
          { key: 'ignored', label: t('pages.recruit.co.tabIgnored'), children: <IgnoredTab t={t} onChanged={() => setReloadKey((k) => k + 1)} /> },
          { key: 'viewers', label: t('pages.recruit.co.tabViewers'), children: <ViewersTab t={t} /> },
        ] : []),
      ]} />
    </Card>
  );
};

export default Companies;
