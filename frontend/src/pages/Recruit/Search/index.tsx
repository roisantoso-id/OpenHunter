/**
 * 人才检索（「通过一个人去找其他合适的人」「先做人才检索页面」「对话框 + 语意拆分，拆出来的列出来，考虑中英印尼语」）。
 * 两个页签（§6.8 操作 / 列表）：
 *   检索：对话框里用任何语言说要找什么人 →「AI 拆解」成逐条条件（三语关键字 + 必须 / 加分，后端 includes/recruit_query.php），
 *         条件逐条列出来，可删 / 切必须加分 / 手动加，改完即重搜；学历、年限回填到筛选框。
 *         结果里每人逐条打勾命中了哪几条。号码 / 邮箱 / 编号直接精确查，不拆。
 *         也可以「以人找人」（从候选人抽屉「关联」页签跳来，URL 带 seed / facet）。
 *   人才池：点一行就在下面展开处理（PoolPanel），不跳页签。
 * 勾选结果 → 批量加入在招职位（AI 随后打分）/ 加入人才池。后端：includes/handlers/recruit_search.php。
 */
import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert, Button, Card, Form, Input, InputNumber, Modal, Popconfirm, Radio, Checkbox, Segmented, Select, Space, Table, Tabs, Tag,
  Tooltip, Typography, message,
} from 'antd';
import { SearchOutlined, FolderOpenOutlined, SaveOutlined, ReloadOutlined, SendOutlined, RobotOutlined } from '@ant-design/icons';
import { useIntl, useLocation, history } from '@umijs/max';
import { recruitMeta, recruitSearch, recruitPools, recruitSavePool, recruitDeletePool, recruitParseQuery } from '@/services/api';
import CandidateDrawer from '../components/CandidateDrawer';
import { showErr, fmtMin, candCode, parseCandCode } from '../common';
import { BulkBar, CondChips, ResultTable, type Cond, type Lang } from './parts';
import PoolPanel from './PoolPanel';
import DoodleEmpty from '../components/DoodleEmpty';

const { Text } = Typography;
const FACET_OPTS = ['all', 'skills', 'experience', 'industry', 'headline'];
const FILTER_KEYS = ['min_edu', 'industry', 'min_years', 'status', 'owner_id'];
/* 示例故意三种语言各一句：告诉招聘专员用哪种语言说都行（不是界面文案，不走 i18n） */
const EXAMPLES = ['做过医疗器械销售，会英语，3 年以上，本科', 'Data center engineer with CCNA, Jakarta', 'Staf HRD pengalaman 3 tahun, S1, bisa bahasa Mandarin'];
/** 号码 / 邮箱 / 编号：精确查询，不拆条件（与后端 recruitIsExactQuery 同口径） */
const isExact = (q: string) => q.length < 3 || q.includes('@') || !!parseCandCode(q) || /^[\d\s+\-().]{4,}$/.test(q);

type Parsed = { source: 'ai' | 'rule'; error_kind?: string; conditions: Cond[]; semantic_query: string; q: string };

const RecruitSearch: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: Record<string, any>) => intl.formatMessage({ id }, v), [intl]);
  const lang: Lang = intl.locale === 'id-ID' ? 'id' : intl.locale === 'en-US' ? 'en' : 'zh';
  const location = useLocation();
  const [meta, setMeta] = useState<any>(null);
  const [tab, setTab] = useState('search');
  const [cond, setCond] = useState<Record<string, any>>(() => {
    const q = new URLSearchParams(location.search);
    const c: Record<string, any> = {};
    ['q', 'facet', 'seed', ...FILTER_KEYS].forEach((k) => { const v = q.get(k); if (v) c[k] = v; });
    return c;
  });
  const [qInput, setQInput] = useState<string>(cond.q || '');
  const [parsed, setParsed] = useState<Parsed | null>(null);
  const [parsing, setParsing] = useState(false);
  const [pool, setPool] = useState<any>(null);   // 从人才池「在检索中编辑」带过来的池：可以把改过的条件存回去
  const [res, setRes] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [sel, setSel] = useState<number[]>([]);
  const [pools, setPools] = useState<any[] | null>(null);
  const [expanded, setExpanded] = useState<number[]>([]);
  const [openId, setOpenId] = useState<number | null>(null);
  const [saveOpen, setSaveOpen] = useState<null | { editing?: any }>(null);
  const [form] = Form.useForm();

  useEffect(() => { recruitMeta().then((r: any) => (r?.success ? setMeta(r.data) : showErr(r, t))); }, [t]);
  const loadPools = useCallback(() => recruitPools().then((r: any) => (r?.success ? setPools(r.data || []) : showErr(r, t))), [t]);
  useEffect(() => { loadPools(); }, [loadPools]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, any> = {};
      Object.entries(cond).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '' && !(k === 'facet' && v === 'all')) params[k] = v; });
      if (parsed?.conditions.length && !cond.seed) { params.conds = JSON.stringify(parsed.conditions); params.semantic_query = parsed.semantic_query; delete params.q; }
      const r = await recruitSearch(params);
      if (r?.success) { setRes(r); setSel((s) => s.filter((id) => (r.data || []).some((x: any) => Number(x.id) === id))); } else showErr(r, t);
    } finally { setLoading(false); }
  }, [cond, parsed, t]);
  useEffect(() => { load(); }, [load]);

  const set = (patch: Record<string, any>) => setCond((c) => ({ ...c, ...patch }));

  /** 发送：精确查询直接搜；其余先让 AI 拆条件（不可用时后端按规则拆），学历 / 年限回填筛选框 */
  const send = async (text?: string) => {
    const q = (text ?? qInput).trim();
    if (text !== undefined) setQInput(text);
    if (!q) { setParsed(null); set({ q: undefined, seed: undefined }); return; }
    if (isExact(q)) { setParsed(null); set({ q, seed: undefined }); return; }
    setParsing(true);
    try {
      const r = await recruitParseQuery(q);
      if (!r?.success) { showErr(r, t); return; }
      const d = r.data;
      setParsed({ source: d.source, error_kind: d.error_kind, conditions: d.conditions || [], semantic_query: d.semantic_query || q, q });
      setCond((c) => ({ ...c, q, seed: undefined, min_edu: d.filters?.min_edu ?? c.min_edu, min_years: d.filters?.min_years ?? c.min_years }));
    } finally { setParsing(false); }
  };
  const reset = () => { setCond({}); setQInput(''); setParsed(null); setPool(null); history.replace('/recruit/search'); };

  // ---- 人才池
  const condPayload = () => ({
    query_text: cond.seed ? '' : (parsed?.q || cond.q || ''), facet: cond.facet && cond.facet !== 'all' ? cond.facet : '', seed_id: Number(cond.seed || 0),
    filters: Object.fromEntries(FILTER_KEYS.filter((k) => cond[k] !== undefined && cond[k] !== null && cond[k] !== '').map((k) => [k, cond[k]])),
    conds: cond.seed ? [] : (parsed?.conditions || []), semantic_query: parsed?.semantic_query || '',
  });
  const openSave = (editing?: any) => {
    form.setFieldsValue(editing ? { name: editing.name, description: editing.description, visibility: editing.visibility }
      : { name: '', description: '', visibility: 'private', add_selected: sel.length > 0 });
    setSaveOpen({ editing });
  };
  const submitSave = async () => {
    const v = await form.validateFields();
    const ed = saveOpen?.editing;
    // 编辑已有池（人才池页签里点「编辑」）只改名称 / 说明 / 可见范围，条件保持原样
    const base = ed ? { id: ed.id, query_text: ed.query_text, facet: ed.facet, seed_id: ed.seed_id, filters: ed.filters, conds: ed.conds, semantic_query: ed.semantic_query }
      : condPayload();
    const r = await recruitSavePool({ ...base, name: v.name, description: v.description, visibility: v.visibility, add_members: !ed && v.add_selected ? sel : [] });
    if (!r?.success) { showErr(r, t); return; }
    message.success(t('pages.recruit.finder.pool.saved'));
    setSaveOpen(null); loadPools();
    if (!ed) { setSel([]); setTab('pools'); setExpanded([Number(r.data.id)]); }
  };
  const updatePoolCond = async () => {
    const r = await recruitSavePool({ id: pool.id, name: pool.name, description: pool.description, visibility: pool.visibility, ...condPayload() });
    if (!r?.success) { showErr(r, t); return; }
    message.success(t('pages.recruit.finder.pool.saved'));
    setPool(r.data); loadPools();
  };
  /** 池 →「在检索中编辑」：条件原样带回对话框（不再调模型） */
  const editPoolInSearch = (p: any) => {
    setPool(p);
    const c: Record<string, any> = { ...(p.filters || {}) };
    if (p.query_text) c.q = p.query_text;
    if (p.facet) c.facet = p.facet;
    if (Number(p.seed_id) > 0) c.seed = String(p.seed_id);
    setCond(c); setQInput(p.query_text || '');
    setParsed(p.conds?.length ? { source: 'ai', conditions: p.conds, semantic_query: p.semantic_query || p.query_text, q: p.query_text } : null);
    setTab('search');
  };

  const seed = res?.seed;
  const chatBox = (
    <Card style={{ marginBottom: 12, borderRadius: 12, boxShadow: '0 2px 12px rgba(26,42,214,.08)', border: '1px solid #d6dcff' }} bodyStyle={{ padding: '12px 14px 10px' }}>
      {!cond.seed ? (
        <Input.TextArea variant="borderless" autoSize={{ minRows: 2, maxRows: 6 }} value={qInput} placeholder={t('pages.recruit.finder.ph')}
          style={{ fontSize: 15, padding: 0 }} onChange={(e) => setQInput(e.target.value)}
          onPressEnter={(e) => { if (!e.shiftKey) { e.preventDefault(); send(); } }} />
      ) : (
        <Alert type={seed && !seed.has_vector ? 'warning' : 'info'} showIcon closable onClose={() => set({ seed: undefined })}
          message={t('pages.recruit.finder.seed', { id: candCode(cond.seed), name: seed?.name || '' })}
          description={seed && !seed.has_vector ? t('pages.recruit.finder.seedNoVector') : [seed?.latest_title, seed?.latest_company].filter(Boolean).join(' @ ') || undefined} />
      )}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginTop: 8 }}>
        <Space size={6} wrap>
          <Tooltip title={t('pages.recruit.finder.facetTip')}>
            <Segmented size="small" value={cond.facet || 'all'} onChange={(v) => set({ facet: v === 'all' ? undefined : String(v) })}
              options={FACET_OPTS.map((f) => ({ value: f, label: f === 'all' ? t('pages.recruit.related.facetAll') : t(`pages.recruit.facet.${f}`) }))} />
          </Tooltip>
          {!qInput && !cond.seed && EXAMPLES.map((ex) => (
            <Tag key={ex} style={{ cursor: 'pointer', borderRadius: 12 }} onClick={() => send(ex)}>{ex}</Tag>))}
        </Space>
        <Space>
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.finder.enterHint')}</Text>
          <Button type="primary" shape="round" icon={<SendOutlined />} loading={parsing} disabled={!!cond.seed} onClick={() => send()}>{t('pages.recruit.finder.go')}</Button>
        </Space>
      </div>
    </Card>
  );

  const ruleWhy = parsed?.error_kind && ['budget', 'not_configured', 'rejected'].includes(parsed.error_kind) ? parsed.error_kind : 'other';
  const condPanel = parsed && !cond.seed && (
    <Card size="small" style={{ marginBottom: 12, borderRadius: 10, background: '#fafbff' }}
      title={<Space><RobotOutlined style={{ color: '#1a2ad6' }} />{t(parsed.source === 'ai' ? 'pages.recruit.finder.parsedAi' : 'pages.recruit.finder.parsedRule')}
        {parsed.source === 'rule' && parsed.error_kind !== 'empty' &&
          <Tooltip title={t('pages.recruit.finder.ruleHint')}><Tag color="orange">{t(`pages.recruit.finder.ruleWhy.${ruleWhy}`)}</Tag></Tooltip>}
      </Space>}
      extra={<Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.finder.condHint')}</Text>}>
      <CondChips conds={parsed.conditions} lang={lang} t={t} onChange={(c) => setParsed((p) => (p ? { ...p, conditions: c } : p))} />
    </Card>
  );

  const filterBar = (
    <Space wrap style={{ marginBottom: 10 }}>
      <Select allowClear style={{ width: 130 }} placeholder={t('pages.recruit.minEdu')} value={cond.min_edu} onChange={(v) => set({ min_edu: v })}
        options={['sma', 'd3', 's1', 's2', 's3'].map((e) => ({ value: e, label: `≥ ${t(`pages.recruit.edu.${e}`)}` }))} />
      <Select allowClear showSearch optionFilterProp="label" style={{ width: 170 }} placeholder={t('pages.recruit.p.industries')} value={cond.industry}
        onChange={(v) => set({ industry: v })} options={(meta?.enums?.industry || []).map((x: string) => ({ value: x, label: t(`pages.recruit.industry.${x}`) }))} />
      <InputNumber min={0} max={40} style={{ width: 150 }} placeholder={t('pages.recruit.finder.minYears')} addonAfter={t('pages.recruit.finder.yearsUnit')}
        value={cond.min_years !== undefined && cond.min_years !== null ? Number(cond.min_years) : undefined} onChange={(v) => set({ min_years: v ?? undefined })} />
      <Select allowClear style={{ width: 140 }} placeholder={t('pages.recruit.col.status')} value={cond.status} onChange={(v) => set({ status: v })}
        options={(meta?.enums?.status || []).filter((s: string) => s !== 'blacklisted').map((s: string) => ({ value: s, label: t(`pages.recruit.status.${s}`) }))} />
      {meta?.scope_all && (
        <Select allowClear style={{ width: 150 }} placeholder={t('pages.recruit.col.owner')} value={cond.owner_id} onChange={(v) => set({ owner_id: v })}
          options={[{ value: 'unclaimed', label: t('pages.recruit.unclaimed') }, ...(meta?.owners || []).map((o: any) => ({ value: String(o.id), label: o.name }))]} />
      )}
      <Button icon={<ReloadOutlined />} onClick={reset}>{t('pages.recruit.reset')}</Button>
      {pool?.can_edit && <Button icon={<SaveOutlined />} onClick={updatePoolCond}>{t('pages.recruit.finder.updatePool', { name: pool.name })}</Button>}
      <Button type="primary" ghost icon={<FolderOpenOutlined />} onClick={() => openSave()}>{t('pages.recruit.finder.savePool')}</Button>
    </Space>
  );

  const searchPane = (
    <>
      {chatBox}
      {condPanel}
      {filterBar}
      {parseCandCode(cond.q)?.valid === false && <Alert type="warning" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.codeInvalid')} />}
      {res?.fallback && <Alert type="warning" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.finder.fallback')} />}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 8, minHeight: 32 }}>
        <Space>
          {res && <Tag color={['semantic', 'seed', 'conds'].includes(res.mode) ? 'cyan' : 'default'}>
            {t(`pages.recruit.finder.mode.${res.mode}`)}{res.mode === 'conds' && res.semantic ? ` + ${t('pages.recruit.finder.mode.semantic')}` : ''}</Tag>}
          {res && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.finder.count', { n: (res.data || []).length })}</Text>}
        </Space>
        <BulkBar sel={sel} setSel={setSel} t={t} meta={meta} pools={pools || []} onDone={() => { load(); loadPools(); }} />
      </div>
      <ResultTable rows={res?.data || []} loading={loading || parsing} res={res} conds={parsed && !cond.seed ? parsed.conditions : []} lang={lang} t={t}
        sel={sel} setSel={setSel} onOpen={setOpenId} />
    </>
  );

  const condSummary = (p: any) => {
    const out: React.ReactNode[] = [];
    if (Number(p.seed_id) > 0) out.push(<Tag key="s" color="purple">{t('pages.recruit.finder.pool.condSeed', { name: p.seed_name || candCode(p.seed_id) })}</Tag>);
    else if (p.query_text) out.push(<Text key="q" style={{ fontSize: 12 }}>「{p.query_text}」</Text>);
    const f = p.filters || {};
    if (f.min_edu) out.push(<Tag key="e">≥ {t(`pages.recruit.edu.${f.min_edu}`)}</Tag>);
    if (f.min_years) out.push(<Tag key="y">≥ {t('pages.recruit.years', { n: f.min_years })}</Tag>);
    if (f.industry) out.push(<Tag key="i">{t(`pages.recruit.industry.${f.industry}`)}</Tag>);
    if (f.status) out.push(<Tag key="st">{t(`pages.recruit.status.${f.status}`)}</Tag>);
    return out.length ? <Space size={[4, 4]} wrap>{out}</Space> : <Text type="secondary">{t('pages.recruit.finder.pool.condNone')}</Text>;
  };
  const toggle = (id: number) => setExpanded((e) => (e.includes(id) ? e.filter((x) => x !== id) : [...e, id]));
  const poolsPane = (
    <Table rowKey="id" size="middle" loading={pools === null} dataSource={pools || []} pagination={false} rowClassName={() => 'recruit-row'}
      onRow={(p: any) => ({ onClick: (e) => { if ((e.target as HTMLElement).closest('a,button,.ant-table-row-expand-icon')) return; toggle(Number(p.id)); } })}
      expandable={{
        expandedRowKeys: expanded, onExpand: (_, p: any) => toggle(Number(p.id)),
        expandedRowRender: (p: any) => <PoolPanel pool={p} meta={meta} pools={pools || []} t={t} lang={lang} onOpen={setOpenId} onEdit={editPoolInSearch} onChanged={loadPools} />,
      }}
      locale={{ emptyText: <DoodleEmpty kind="list" description={t('pages.recruit.finder.pool.empty')} /> }}
      columns={[
        { title: t('pages.recruit.finder.pool.name'), width: 240, render: (_: any, p: any) => (
            <><div><Text strong>{p.name}</Text></div>{!!p.description && <Text type="secondary" style={{ fontSize: 12 }}>{p.description}</Text>}</>) },
        { title: t('pages.recruit.finder.pool.cond'), render: (_: any, p: any) => (
            <Space direction="vertical" size={4}>{condSummary(p)}{(p.conds || []).length > 0 && <CondChips conds={p.conds} lang={lang} t={t} />}</Space>) },
        { title: t('pages.recruit.finder.pool.members'), width: 80, align: 'right' as const, render: (_: any, p: any) => Number(p.member_count) },
        { title: t('pages.recruit.finder.pool.visibility'), width: 130, render: (_: any, p: any) => (
            <Tag color={p.visibility === 'all' ? 'blue' : 'default'}>{t(`pages.recruit.finder.pool.${p.visibility === 'all' ? 'all' : 'private'}`)}</Tag>) },
        { title: t('pages.recruit.finder.pool.creator'), width: 160, render: (_: any, p: any) => (
            <><div>{p.created_by_name || '-'}</div><Text type="secondary" style={{ fontSize: 12 }}>{fmtMin(p.updated_at)}</Text></>) },
        { title: '', width: 150, align: 'right' as const, render: (_: any, p: any) => p.can_edit && (
            <Space size={0}>
              <Button type="link" size="small" onClick={() => openSave(p)}>{t('pages.recruit.finder.pool.edit')}</Button>
              <Popconfirm title={t('pages.recruit.finder.pool.deleteConfirm', { name: p.name })}
                onConfirm={async () => {
                  const r = await recruitDeletePool(Number(p.id));
                  if (!r?.success) { showErr(r, t); return; }
                  if (pool && Number(pool.id) === Number(p.id)) setPool(null);
                  loadPools();
                }}>
                <Button type="link" size="small" danger>{t('pages.recruit.finder.pool.delete')}</Button>
              </Popconfirm>
            </Space>) },
      ]} />
  );

  return (
    <div>
      <style>{`.recruit-row:hover > td, .recruit-row > td.ant-table-cell-row-hover { background:#eef4fb !important; cursor:pointer }`}</style>
      <Tabs activeKey={tab} onChange={setTab} items={[
        { key: 'search', label: <span><SearchOutlined /> {t('pages.recruit.finder.tabSearch')}</span>, children: searchPane },
        { key: 'pools', label: <span><FolderOpenOutlined /> {t('pages.recruit.finder.tabPools')}{pools ? ` · ${pools.length}` : ''}</span>, children: poolsPane },
      ]} />

      <CandidateDrawer open={openId !== null} candidateId={openId} meta={meta} onClose={() => setOpenId(null)} onChanged={load} />

      <Modal open={!!saveOpen} width={520} destroyOnClose onCancel={() => setSaveOpen(null)} onOk={submitSave}
        okText={t('pages.recruit.save')} cancelText={t('pages.recruit.cancel')}
        title={saveOpen?.editing ? t('pages.recruit.finder.pool.edit') : t('pages.recruit.finder.savePool')}>
        <Form form={form} layout="vertical">
          <Form.Item name="name" label={t('pages.recruit.finder.pool.name')} rules={[{ required: true, whitespace: true, message: t('pages.recruit.err.nameRequired') }]}>
            <Input maxLength={191} placeholder={t('pages.recruit.finder.pool.namePh')} />
          </Form.Item>
          <Form.Item name="description" label={t('pages.recruit.finder.pool.desc')}><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
          <Form.Item name="visibility" label={t('pages.recruit.finder.pool.visibility')}>
            <Radio.Group options={[{ value: 'private', label: t('pages.recruit.finder.pool.private') }, { value: 'all', label: t('pages.recruit.finder.pool.all') }]} />
          </Form.Item>
          {!saveOpen?.editing && (
            <>
              {(parsed?.conditions || []).length > 0 && !cond.seed && <div style={{ marginBottom: 8 }}><CondChips conds={parsed!.conditions} lang={lang} t={t} /></div>}
              {sel.length > 0 && (
                <Form.Item name="add_selected" valuePropName="checked" style={{ marginBottom: 0 }}>
                  <Checkbox>{t('pages.recruit.finder.pool.addSelected', { n: sel.length })}</Checkbox>
                </Form.Item>)}
            </>)}
        </Form>
      </Modal>
    </div>
  );
};

export default RecruitSearch;
