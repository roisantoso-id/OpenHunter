/**
 * 企业详情抽屉（企业库页、人才库都从这里开）：资料卡（每条挂来源）· 公开组织架构 · 人才地图（职能 × 职级）· 在职 / 已离职 / 待得久 名单。
 * 数字与名单同一口径（后端 recruitCompanyBucketSql）。管理员：编辑资料 / 名次 / 别名、立即检索、合并、不建档（二级抽屉，不用 Modal）。
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Drawer, Descriptions, Tag, Space, Button, Typography, Tabs, Spin, Empty, Segmented, Popconfirm, Form, Input, InputNumber, Select, Row, Col, List, Tooltip, message,
} from 'antd';
import { EditOutlined, ReloadOutlined, MergeCellsOutlined, StopOutlined, GlobalOutlined, LinkedinOutlined, TeamOutlined, ApartmentOutlined } from '@ant-design/icons';
import { useIntl, history } from '@umijs/max';
import { DetailBlock, DetailTable, DETAIL_WIDTH } from '@/components/DetailPanel';
import {
  recruitCompanyDetail, recruitCompanyPeople, recruitSaveCompany, recruitCompanyEnrich, recruitMergeCompany, recruitIgnoreCompany, recruitCompanies,
} from '@/services/api';
import { showErr, fmtDay, candCode, NO_HSCROLL, TIER_COLOR, REGION_COLOR, segName, T } from '../common';
import CandidateDrawer from './CandidateDrawer';

const { Text, Paragraph } = Typography;
const CONF_COLOR: Record<string, string> = { high: 'green', medium: 'gold', low: 'default' };

// ====================================================================== 编辑
const CompanyForm: React.FC<{ open: boolean; co?: any; segments: any[]; meta: any; t: T; onClose: () => void; onSaved: (id: number) => void }> =
({ open, co, segments, meta, t, onClose, onSaved }) => {
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const intl = useIntl();
  useEffect(() => {
    if (!open) return;
    form.resetFields();
    const c = co || {};
    form.setFieldsValue({ name: c.name, aliases: (c.aliases || []).map((a: any) => a.alias).filter((a: string) => a !== c.name),
      region: c.region || undefined, country: c.country, hq_city: c.hq_city, website: c.website, linkedin_url: c.linkedin_url,
      industry: c.industry || undefined, segment_id: Number(c.segment_id) || undefined, rank_in_segment: c.rank_in_segment ?? undefined,
      tier: c.tier || undefined, employee_range: c.employee_range, founded_year: c.founded_year || undefined, parent_group: c.parent_group,
      summary_zh: c.summary?.zh, summary_en: c.summary?.en, summary_id: c.summary?.id, notes: c.notes });
  }, [open, co, form]);
  const submit = async () => {
    const v = await form.validateFields();
    setSaving(true);
    try {
      const res = await recruitSaveCompany({ id: co?.id || 0, name: v.name, aliases: v.aliases || [], region: v.region || '', country: (v.country || '').toUpperCase(),
        hq_city: v.hq_city || '', website: v.website || '', linkedin_url: v.linkedin_url || '', industry: v.industry || '', segment_id: v.segment_id || 0,
        rank_in_segment: v.rank_in_segment ?? '', tier: v.tier || '', employee_range: v.employee_range || '', founded_year: v.founded_year || '',
        parent_group: v.parent_group || '', summary: { zh: v.summary_zh || '', en: v.summary_en || '', id: v.summary_id || '' }, notes: v.notes || '' });
      if (res?.success) { message.success(t('pages.recruit.msg.saved')); onSaved(Number(res.data.id)); }
      else if (res?.message_key === 'pages.recruit.err.aliasTaken') message.error(t(res.message_key, { alias: res.alias, company: res.company }));
      else showErr(res, t);
    } finally { setSaving(false); }
  };
  return (
    <Drawer open={open} onClose={onClose} width={DETAIL_WIDTH.info} destroyOnClose title={co?.id ? t('pages.recruit.co.edit') : t('pages.recruit.co.new')}
      extra={<Space><Button onClick={onClose}>{t('pages.recruit.cancel')}</Button><Button type="primary" loading={saving} onClick={submit}>{t('pages.recruit.save')}</Button></Space>}>
      <Form form={form} layout="vertical">
        <Form.Item name="name" label={t('pages.recruit.co.name')} rules={[{ required: true, message: t('pages.recruit.err.nameRequired') }]}><Input maxLength={191} /></Form.Item>
        <Form.Item name="aliases" label={t('pages.recruit.co.aliases')} extra={t('pages.recruit.co.aliasesHint')}>
          <Select mode="tags" tokenSeparators={['\n']} open={false} />
        </Form.Item>
        <Row gutter={16}>
          <Col span={12}>
            <Form.Item name="segment_id" label={t('pages.recruit.co.segment')}>
              <Select allowClear options={segments.filter((s) => Number(s.active)).map((s) => ({ value: Number(s.id), label: segName(s, intl.locale) }))} />
            </Form.Item>
          </Col>
          <Col span={6}><Form.Item name="rank_in_segment" label={t('pages.recruit.co.rank')} extra={t('pages.recruit.co.rankHint')}><InputNumber min={1} max={999} style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={6}>
            <Form.Item name="tier" label={t('pages.recruit.co.tier')}>
              <Select allowClear options={(meta?.tier || ['top', 'mid', 'other']).map((x: string) => ({ value: x, label: t(`pages.recruit.tier.${x}`) }))} />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="region" label={t('pages.recruit.co.region')}>
              <Select allowClear options={['local', 'overseas'].map((x) => ({ value: x, label: t(`pages.recruit.region.${x}`) }))} />
            </Form.Item>
          </Col>
          <Col span={6}><Form.Item name="country" label={t('pages.recruit.co.country')}><Input maxLength={2} placeholder="ID / CN / SG" /></Form.Item></Col>
          <Col span={6}><Form.Item name="hq_city" label={t('pages.recruit.co.hq')}><Input maxLength={100} /></Form.Item></Col>
          <Col span={12}><Form.Item name="website" label={t('pages.recruit.co.website')}><Input maxLength={255} /></Form.Item></Col>
          <Col span={12}><Form.Item name="linkedin_url" label="LinkedIn"><Input maxLength={255} /></Form.Item></Col>
          <Col span={12}>
            <Form.Item name="industry" label={t('pages.recruit.p.industries')}>
              <Select allowClear showSearch optionFilterProp="label" options={(meta?.industry || []).map((x: string) => ({ value: x, label: t(`pages.recruit.industry.${x}`) }))} />
            </Form.Item>
          </Col>
          <Col span={6}><Form.Item name="employee_range" label={t('pages.recruit.co.size')}><Input maxLength={40} placeholder="201-1000" /></Form.Item></Col>
          <Col span={6}><Form.Item name="founded_year" label={t('pages.recruit.co.founded')}><InputNumber min={1800} max={2100} style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={24}><Form.Item name="parent_group" label={t('pages.recruit.co.parent')}><Input maxLength={191} /></Form.Item></Col>
          <Col span={24}><Form.Item name="summary_zh" label={`${t('pages.recruit.co.summary')} · 中文`}><Input.TextArea rows={2} maxLength={500} /></Form.Item></Col>
          <Col span={12}><Form.Item name="summary_en" label={`${t('pages.recruit.co.summary')} · English`}><Input.TextArea rows={2} maxLength={500} /></Form.Item></Col>
          <Col span={12}><Form.Item name="summary_id" label={`${t('pages.recruit.co.summary')} · Indonesia`}><Input.TextArea rows={2} maxLength={500} /></Form.Item></Col>
          <Col span={24}><Form.Item name="notes" label={t('pages.recruit.pl.notes')}><Input.TextArea rows={2} maxLength={2000} /></Form.Item></Col>
        </Row>
      </Form>
    </Drawer>
  );
};
export { CompanyForm };

// ====================================================================== 合并
const MergeDrawer: React.FC<{ open: boolean; co: any; t: T; onClose: () => void; onDone: (intoId: number) => void }> = ({ open, co, t, onClose, onDone }) => {
  const [opts, setOpts] = useState<any[]>([]);
  const [into, setInto] = useState<number>();
  const [saving, setSaving] = useState(false);
  const search = async (kw: string) => {
    const r = await recruitCompanies({ keyword: kw, has_people: '0', pageSize: 20 });
    setOpts((r?.data?.rows || []).filter((x: any) => Number(x.id) !== Number(co?.id)).map((x: any) => ({ value: Number(x.id), label: `${x.name}（${x.counts?.all ?? 0}）` })));
  };
  useEffect(() => { if (open) { setInto(undefined); search(''); } }, [open]);   // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <Drawer open={open} onClose={onClose} width={DETAIL_WIDTH.form} destroyOnClose title={`${t('pages.recruit.co.merge')} · ${co?.name || ''}`}
      extra={<Space><Button onClick={onClose}>{t('pages.recruit.cancel')}</Button>
        <Button type="primary" danger disabled={!into} loading={saving} onClick={async () => {
          setSaving(true);
          try { const r = await recruitMergeCompany(Number(co.id), into!); if (r?.success) { message.success(t('pages.recruit.msg.saved')); onDone(into!); } else showErr(r, t); }
          finally { setSaving(false); }
        }}>{t('pages.recruit.co.mergeOk')}</Button></Space>}>
      <Paragraph type="secondary">{t('pages.recruit.co.mergeHint')}</Paragraph>
      <Select showSearch filterOption={false} style={{ width: '100%' }} placeholder={t('pages.recruit.co.mergeInto')} value={into} onChange={setInto}
        onSearch={search} options={opts} />
    </Drawer>
  );
};

// ====================================================================== 详情
const CompanyDrawer: React.FC<{ open: boolean; companyId: number | null; meta: any; segments: any[]; enums: any; onClose: () => void; onChanged?: () => void;
  initBucket?: string; initFunc?: string; initEdit?: boolean }> = ({ open, companyId, meta, segments, enums, onClose, onChanged, initBucket, initFunc, initEdit }) => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const lang = intl.locale === 'id-ID' ? 'id' : intl.locale === 'en-US' ? 'en' : 'zh';
  const [id, setId] = useState<number | null>(companyId);
  const [co, setCo] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [tab, setTab] = useState('people');
  const [bucket, setBucket] = useState('all');
  const [cell, setCell] = useState<{ func?: string; sen?: string }>({});
  const [people, setPeople] = useState<any[]>([]);
  const [pLoading, setPLoading] = useState(false);
  const [editing, setEditing] = useState(false);
  const [merging, setMerging] = useState(false);
  const [openCand, setOpenCand] = useState<number | null>(null);
  useEffect(() => { setId(companyId); }, [companyId]);

  const load = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    try {
      const r = await recruitCompanyDetail(id);
      if (!r?.success) { showErr(r, t); return; }
      if (r.data.merged_into) { setId(Number(r.data.merged_into)); return; }
      setCo(r.data);
      if (initEdit && r.data.can_admin) setEditing(true);   // 卡片上点「编辑」：打开就进编辑
    } finally { setLoading(false); }
  }, [id, t, initEdit]);
  useEffect(() => { if (open) { setCo(null); setTab('people'); setBucket(initBucket || 'all'); setCell(initFunc ? { func: initFunc } : {}); load(); } }, [open, load, initBucket, initFunc]);
  const loadPeople = useCallback(async () => {
    if (!id) return;
    setPLoading(true);
    try { const r = await recruitCompanyPeople({ id, bucket, func: cell.func, sen: cell.sen }); if (r?.success) setPeople(r.data || []); else showErr(r, t); }
    finally { setPLoading(false); }
  }, [id, bucket, cell, t]);
  useEffect(() => { if (open && co) loadPeople(); }, [open, co, loadPeople]);

  const canAdmin = !!co?.can_admin;
  const counts = co?.counts || {};
  const funcs: string[] = enums?.job_function || [];
  const sens: string[] = enums?.seniority || [];
  const mapRows = useMemo(() => funcs.filter((f) => co?.talent_map?.[f]).map((f) => ({ f, ...co.talent_map[f] })), [co, funcs]);
  const srcFor = (field: string) => (co?.sources || []).filter((s: any) => s.field === field);
  const srcTag = (field: string) => {
    const s = srcFor(field)[0];
    return s ? <Tooltip title={<div>{s.evidence}<br /><a href={s.url} target="_blank" rel="noreferrer">{s.url}</a></div>}>
      <Tag color={CONF_COLOR[s.confidence]} style={{ fontSize: 11, marginLeft: 6 }}>{t(`pages.recruit.co.conf.${s.confidence}`)}</Tag></Tooltip> : null;
  };

  const info = co && (
    <>
      <DetailBlock column={2}>
        <Descriptions.Item label={t('pages.recruit.co.website')}>{co.website ? <a href={co.website} target="_blank" rel="noreferrer"><GlobalOutlined /> {co.website}</a> : '-'}{srcTag('website')}</Descriptions.Item>
        <Descriptions.Item label="LinkedIn">{co.linkedin_url ? <a href={co.linkedin_url} target="_blank" rel="noreferrer"><LinkedinOutlined /> LinkedIn</a> : '-'}</Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.co.region')}>
          {co.region ? <Tag color={REGION_COLOR[co.region]}>{t(`pages.recruit.region.${co.region}`)}</Tag> : '-'}{co.country}{co.hq_city ? ` · ${co.hq_city}` : ''}{srcTag('country')}
        </Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.co.size')}>{co.employee_range || '-'}{co.founded_year ? ` · ${t('pages.recruit.co.foundedIn', { y: co.founded_year })}` : ''}{srcTag('employee_range')}</Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.co.segment')}>
          <Space size={4} wrap>
            {co.segment ? segName(co.segment, intl.locale) : '-'}
            {co.rank_in_segment ? <Tag color="gold">{t('pages.recruit.co.rankN', { n: co.rank_in_segment })}</Tag> : null}
            {co.tier ? <Tag color={TIER_COLOR[co.tier]}>{t(`pages.recruit.tier.${co.tier}`)}</Tag> : null}
          </Space>
        </Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.p.industries')}>{co.industry ? t(`pages.recruit.industry.${co.industry}`) : '-'}</Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.co.parent')}>{co.parent_group || '-'}</Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.co.idEntities')}>{(co.id_entities || []).length ? (co.id_entities || []).join(' / ') : '-'}</Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.co.summary')} span={2}><div style={{ whiteSpace: 'pre-wrap' }}>{co.summary?.[lang] || co.summary?.zh || co.summary?.en || '-'}</div></Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.co.aliases')} span={2}>
          <Space size={4} wrap>{(co.aliases || []).map((a: any) => <Tag key={a.id}>{a.alias}</Tag>)}</Space>
        </Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.co.enrich')} span={2}>
          <Space size={6} wrap>
            <Tag color={{ done: 'green', failed: 'red', queued: 'default', running: 'processing' }[co.enrich_status as string]}>{t(`pages.recruit.co.enrichStatus.${co.enrich_status}`)}</Tag>
            {!!co.enrich_error && <Text type="danger" style={{ fontSize: 12 }}>{t(`pages.recruit.co.enrichErr.${['ambiguous', 'not_found', 'no_results'].includes(co.enrich_error) ? co.enrich_error : 'other'}`)}</Text>}
            {!!co.enriched_at && <Text type="secondary" style={{ fontSize: 12 }}>{fmtDay(co.enriched_at)}</Text>}
            {co.enrich_meta?.cost_usd !== undefined && <Text type="secondary" style={{ fontSize: 12 }}>
              {t('pages.recruit.co.enrichCost', { cost: Number(co.enrich_meta.cost_usd).toFixed(3), s: co.enrich_meta.searches, p: co.enrich_meta.pages, sec: Math.round((co.enrich_meta.elapsed_ms || 0) / 1000) })}</Text>}
            {co.source === 'auto' && <Tag>{t('pages.recruit.co.auto')}</Tag>}
          </Space>
        </Descriptions.Item>
      </DetailBlock>
      <div style={{ marginTop: 16 }}>
        <Text strong>{t('pages.recruit.co.org')}</Text>
        {!co.org?.departments?.length && !co.org?.leaders?.length && !co.org?.subsidiaries?.length ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('pages.recruit.co.noOrg')} /> : (
          <div style={{ marginTop: 8 }}>
            {!!co.org?.departments?.length && <div style={{ marginBottom: 8 }}><Text type="secondary">{t('pages.recruit.co.departments')}：</Text>
              <Space size={4} wrap>{co.org.departments.map((d: string) => <Tag key={d}>{d}</Tag>)}</Space></div>}
            {!!co.org?.leaders?.length && <DetailTable rowKey="name" size="small" pagination={false} {...NO_HSCROLL} dataSource={co.org.leaders}
              columns={[{ title: t('pages.recruit.f.name'), dataIndex: 'name', width: 180 }, { title: t('pages.recruit.co.leaderTitle'), dataIndex: 'title' },
                { title: t('pages.recruit.co.source'), width: 90, render: (_: any, l: any) => <a href={l.source_url} target="_blank" rel="noreferrer">{t('pages.recruit.co.open')}</a> }]} />}
            {!!co.org?.subsidiaries?.length && <div style={{ marginTop: 8 }}><Text type="secondary">{t('pages.recruit.co.subsidiaries')}：</Text>{co.org.subsidiaries.join(' / ')}</div>}
          </div>)}
      </div>
      {(co.sources || []).length > 0 && (
        <div style={{ marginTop: 16 }}>
          <Text strong>{t('pages.recruit.co.sources')}</Text>
          <List size="small" dataSource={co.sources} renderItem={(s: any) => (
            <List.Item>
              <Space size={6} wrap style={{ wordBreak: 'break-word' }}>
                <Tag color={CONF_COLOR[s.confidence]}>{t(`pages.recruit.co.conf.${s.confidence}`)}</Tag>
                <Text type="secondary" style={{ fontSize: 12 }}>{s.field}</Text>
                <Text style={{ fontSize: 12 }}>{s.evidence}</Text>
                <a href={s.url} target="_blank" rel="noreferrer" style={{ fontSize: 12 }}>{s.url}</a>
              </Space>
            </List.Item>)} />
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.co.aiDisclaimer')}</Text>
        </div>)}
    </>
  );

  const peopleTab = co && (
    <>
      <Space wrap style={{ marginBottom: 10 }}>
        <Segmented value={bucket} onChange={(v) => setBucket(String(v))}
          options={['all', 'current', 'former', 'long'].map((b) => ({ value: b, label: `${t(`pages.recruit.co.bucket.${b}`)} ${counts[b] ?? 0}` }))} />
        {(cell.func || cell.sen) && (
          <Tag closable onClose={() => setCell({})} color="blue">
            {[cell.func ? t(`pages.recruit.func.${cell.func}`) : '', cell.sen ? t(`pages.recruit.seniority.${cell.sen}`) : ''].filter(Boolean).join(' · ')}</Tag>)}
        <Button size="small" icon={<TeamOutlined />} onClick={() => history.push(`/recruit/talent?company_id=${co.id}`)}>{t('pages.recruit.co.inTalent')}</Button>
      </Space>
      {mapRows.length > 0 && (
        <DetailTable rowKey="f" size="small" pagination={false} {...NO_HSCROLL} dataSource={mapRows} style={{ marginBottom: 12 }}
          columns={[{ title: t('pages.recruit.co.talentMap'), width: 150, render: (_: any, r: any) => (
              <a onClick={() => { setBucket('all'); setCell({ func: r.f }); }}>{t(`pages.recruit.func.${r.f}`)}</a>) },
            ...sens.map((s) => ({ title: t(`pages.recruit.seniority.${s}`), align: 'center' as const, render: (_: any, r: any) => (r[s]
              ? <a onClick={() => { setBucket('all'); setCell({ func: r.f, sen: s }); }} style={{ fontWeight: cell.func === r.f && cell.sen === s ? 700 : undefined }}>{r[s]}</a> : <Text type="secondary">-</Text>) }))]} />)}
      <Spin spinning={pLoading}>
        <DetailTable rowKey="id" dataSource={people} pagination={{ pageSize: 30, hideOnSinglePage: true }} {...NO_HSCROLL}
          rowClassName={() => 'recruit-row'} onRow={(r: any) => ({ onClick: () => setOpenCand(Number(r.id)) })}
          locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
          columns={[
            { title: t('pages.recruit.candidate'), width: 190, render: (_: any, r: any) => (
                <div><Text strong>{r.name || '-'}</Text><div style={{ fontSize: 12, color: '#8c8c8c' }}>{r.code || candCode(r.id)} · {r.owner_user_name || t('pages.recruit.unclaimed')}</div></div>) },
            { title: t('pages.recruit.co.stints'), render: (_: any, r: any) => (
                <div style={{ fontSize: 12 }}>
                  {(r.stints || []).map((s: any, i: number) => (
                    <div key={i} style={{ marginBottom: 2 }}>
                      {s.is_current ? <Tag color="green" style={{ fontSize: 11 }}>{t('pages.recruit.co.bucket.current')}</Tag> : null}
                      <Text strong>{s.title || '-'}</Text> <Text type="secondary">{s.start_ym || '?'} ~ {s.is_current ? t('pages.recruit.p.present') : (s.end_ym || '?')}
                        {s.months ? ` · ${t('pages.recruit.co.months', { n: s.months })}` : ''}</Text>
                      {!!s.job_function && <Tag style={{ fontSize: 11, marginLeft: 4 }}>{t(`pages.recruit.func.${s.job_function}`)}{s.seniority ? ` · ${t(`pages.recruit.seniority.${s.seniority}`)}` : ''}</Tag>}
                    </div>))}
                </div>) },
            { title: t('pages.recruit.col.latest'), width: 220, render: (_: any, r: any) => (
                <div style={{ fontSize: 12 }}>{r.latest_title || '-'}{r.latest_company ? <Text type="secondary"> @ {r.latest_company}</Text> : ''}</div>) },
            { title: t('pages.recruit.col.status'), width: 110, render: (_: any, r: any) => <Tag>{t(`pages.recruit.status.${r.status}`)}</Tag> },
          ]} />
      </Spin>
    </>
  );

  return (
    <Drawer open={open} onClose={onClose} width={Math.min(1180, (typeof window !== 'undefined' ? window.innerWidth : 1180) - 80)} destroyOnClose
      title={co ? <Space wrap size={6}>{co.name}
        {co.region && <Tag color={REGION_COLOR[co.region]}>{t(`pages.recruit.region.${co.region}`)}</Tag>}
        {co.rank_in_segment ? <Tag color="gold">{segName(co.segment, intl.locale)} · {t('pages.recruit.co.rankN', { n: co.rank_in_segment })}</Tag> : null}
        {co.tier && <Tag color={TIER_COLOR[co.tier]}>{t(`pages.recruit.tier.${co.tier}`)}</Tag>}</Space> : t('pages.recruit.co.title')}
      extra={co && (
        <Space>
          <Button icon={<ApartmentOutlined />} onClick={() => history.push(`/recruit/network?type=company&ref=${co.id}`)}>{t('menu.recruit.network')}</Button>
          {canAdmin && <>
          <Button icon={<EditOutlined />} onClick={() => setEditing(true)}>{t('pages.recruit.edit')}</Button>
          <Button icon={<ReloadOutlined />} onClick={async () => { const r = await recruitCompanyEnrich(Number(co.id)); if (r?.success) { message.success(t('pages.recruit.co.enrichQueued')); load(); } else showErr(r, t); }}>
            {t('pages.recruit.co.enrichNow')}</Button>
          <Button icon={<MergeCellsOutlined />} onClick={() => setMerging(true)}>{t('pages.recruit.co.merge')}</Button>
          <Popconfirm title={t('pages.recruit.co.ignoreConfirm')} onConfirm={async () => { const r = await recruitIgnoreCompany(Number(co.id)); if (r?.success) { onChanged?.(); onClose(); } else showErr(r, t); }}>
            <Button danger icon={<StopOutlined />}>{t('pages.recruit.co.ignore')}</Button>
          </Popconfirm>
          </>}
        </Space>)}>
      <style>{`.recruit-row:hover > td, .recruit-row > td.ant-table-cell-row-hover { background:#eef4fb !important; cursor:pointer }`}</style>
      {loading && !co ? <Spin style={{ display: 'block', margin: '60px auto' }} /> : co && (
        <Tabs activeKey={tab} onChange={setTab} items={[
          { key: 'people', label: `${t('pages.recruit.co.people')} (${counts.all ?? 0})`, children: peopleTab },
          { key: 'info', label: t('pages.recruit.co.info'), children: info },
        ]} />
      )}
      <CompanyForm open={editing} co={co} segments={segments} meta={{ ...enums, industry: meta?.enums?.industry }} t={t} onClose={() => setEditing(false)}
        onSaved={() => { setEditing(false); load(); onChanged?.(); }} />
      <MergeDrawer open={merging} co={co} t={t} onClose={() => setMerging(false)} onDone={(into) => { setMerging(false); setId(into); onChanged?.(); }} />
      <CandidateDrawer open={openCand !== null} candidateId={openCand} meta={meta} onClose={() => setOpenCand(null)} />
    </Drawer>
  );
};

export default CompanyDrawer;
