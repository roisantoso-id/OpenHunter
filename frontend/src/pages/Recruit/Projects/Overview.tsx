/**
 * 招聘项目总览 /recruit/projects/:id：客户是谁、签约情况怎么样、招聘进展、进了几个人，一目了然。
 * 先总后分：头部条款卡 → KPI（每个数字可点，点开的行数 = 数字，后端同一份 SQL）→ 页签：职位 / 候选人漏斗 / 入职与保证期 / 合同与文件。
 * 条款只有 recruit_admin 能改（「商务条款」按钮）；入职登记、过保、离职招聘专员可做。
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, Descriptions, Tag, Space, Button, Typography, Tabs, Spin, Empty, Alert, Segmented, Tooltip } from 'antd';
import { ArrowLeftOutlined, EditOutlined, FileProtectOutlined } from '@ant-design/icons';
import { useIntl, useParams, history, useAccess } from '@umijs/max';
import { DetailBlock, DetailTable } from '@/components/DetailPanel';
import { recruitMeta, recruitProjectOverview, recruitGetProject, recruitProjectPipeline } from '@/services/api';
import { ProjectFormDrawer, JobsSection } from '../components/ProjectDrawer';
import ProjectTermsDrawer from '../components/ProjectTermsDrawer';
import PlacementsTab from '../components/PlacementsTab';
import DocumentsTab, { DocEntity } from '../components/DocumentsTab';
import CandidateDrawer from '../components/CandidateDrawer';
import {
  showErr, fmtDay, fmtMin, money, feeText, guaranteeText, effScore, ScoreTag, STAGE_COLOR, SERVICE_COLOR, CONTRACT_COLOR, MONTHLY_SERVICES, NO_HSCROLL,
} from '../common';

const { Text, Title } = Typography;
const LINK_BUCKETS = ['candidates', 'matched', 'submitted', 'interviewing', 'offered', 'hired', 'dropped'];
const PL_BUCKETS = ['pending_start', 'in_guarantee', 'passed', 'lost'];
const BUCKET_COLOR: Record<string, string> = { matched: '#1a2ad6', interviewing: '#722ed1', offered: '#d48806', hired: '#389e0d', in_guarantee: '#1677ff', passed: '#389e0d', lost: '#cf1322' };

const Kpi: React.FC<{ label: string; value: React.ReactNode; color?: string; active?: boolean; onClick?: () => void; tip?: string }> = ({ label, value, color, active, onClick, tip }) => (
  <Tooltip title={tip}>
    <div onClick={onClick} style={{ cursor: onClick ? 'pointer' : 'default', padding: '10px 14px', borderRadius: 8, minWidth: 92, textAlign: 'center',
      border: `1px solid ${active ? '#1a2ad6' : '#f0f0f0'}`, background: active ? '#f0f3ff' : '#fff' }}>
      <div style={{ fontSize: 22, fontWeight: 600, lineHeight: 1.3, color: color || '#262626' }}>{value}</div>
      <div style={{ fontSize: 12, color: '#8c8c8c', whiteSpace: 'nowrap' }}>{label}</div>
    </div>
  </Tooltip>
);

const Overview: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const { id } = useParams();
  const pid = Number(id);
  const access = useAccess() as any;
  const [meta, setMeta] = useState<any>(null);
  const [ov, setOv] = useState<any>(null);
  const [proj, setProj] = useState<any>(null);   // recruitGetProject：职位列表
  const [loading, setLoading] = useState(false);
  const [tab, setTab] = useState('jobs');
  const [bucket, setBucket] = useState('candidates');
  const [rows, setRows] = useState<any[]>([]);
  const [rowsLoading, setRowsLoading] = useState(false);
  const [editProj, setEditProj] = useState(false);
  const [editTerms, setEditTerms] = useState(false);
  const [editJob, setEditJob] = useState<any>(null);   // 编辑某个职位的条款
  const [openCand, setOpenCand] = useState<number | null>(null);

  useEffect(() => { recruitMeta().then((r: any) => (r?.success ? setMeta(r.data) : showErr(r, t))); }, [t]);
  const load = useCallback(async () => {
    if (!pid) return;
    setLoading(true);
    try {
      const [a, b] = await Promise.all([recruitProjectOverview(pid), recruitGetProject(pid)]);
      if (a?.success) setOv(a.data); else showErr(a, t);
      if (b?.success) setProj(b.data);
    } finally { setLoading(false); }
  }, [pid, t]);
  useEffect(() => { load(); }, [load]);
  const loadRows = useCallback(async () => {
    setRowsLoading(true);
    try { const r = await recruitProjectPipeline(pid, bucket); if (r?.success) setRows(r.data || []); else showErr(r, t); } finally { setRowsLoading(false); }
  }, [pid, bucket, t]);
  useEffect(() => { if (tab === 'funnel') loadRows(); }, [tab, loadRows]);
  const reloadAll = () => { load(); if (tab === 'funnel') loadRows(); };

  const k = ov?.kpis || {};
  const drill = (b: string) => { setBucket(b); setTab('funnel'); };
  const canAdmin = !!ov?.can_admin || !!access.canManageRecruit;
  const metaA = useMemo(() => (meta ? { ...meta, can_admin: canAdmin } : meta), [meta, canAdmin]);
  const monthly = MONTHLY_SERVICES.includes(ov?.service_type);
  const docEntities: DocEntity[] = useMemo(() => ov ? [
    { type: 'project', id: pid, label: t('pages.recruit.ovw.projectItself') },
    ...(ov.placements || []).map((p: any) => ({ type: 'placement' as const, id: Number(p.id), label: `${p.cand_name || p.cand_code} · ${p.job_title}` })),
  ] : [], [ov, pid, t]);
  const feeTotals = Object.entries(ov?.fee_totals || {});

  if (!ov) return <Spin spinning={loading} style={{ display: 'block', margin: '80px auto' }}>{!loading && <Empty />}</Spin>;
  const internal = ov.kind === 'internal';
  const milestones = (ov.billing_terms?.milestones || []).map((m: any) => `${t(`pages.recruit.milestone.${m.code}`)} ${m.percent}%`).join(' · ');

  return (
    <div>
      <style>{`.recruit-row:hover > td, .recruit-row > td.ant-table-cell-row-hover { background:#eef4fb !important; cursor:pointer }`}</style>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8, marginBottom: 12 }}>
        <Space size={10} align="center" wrap>
          <Button type="text" icon={<ArrowLeftOutlined />} onClick={() => history.push('/recruit/projects')} />
          <Title level={4} style={{ margin: 0 }}>{ov.name}</Title>
          <Tag color={internal ? 'purple' : 'blue'}>{t(`pages.recruit.kind.${ov.kind}`)}</Tag>
          <Tag color={ov.status === 'open' ? 'green' : ov.status === 'paused' ? 'orange' : 'default'}>{t(`pages.recruit.projStatus.${ov.status}`)}</Tag>
          {!internal && ov.lc_ready && (ov.service_types?.length ? ov.service_types : [ov.service_type]).map((st: string) => (
            <Tag key={st} color={SERVICE_COLOR[st]}>{t(`pages.recruit.svc.${st}`)}</Tag>))}
          {!internal && ov.lc_ready && <Tag color={CONTRACT_COLOR[ov.contract_status]}>{t(`pages.recruit.contract.${ov.contract_status}`)}</Tag>}
        </Space>
        <Space>
          <Button icon={<EditOutlined />} onClick={() => setEditProj(true)}>{t('pages.recruit.ovw.editInfo')}</Button>
          {canAdmin && ov.lc_ready && !internal && <Button type="primary" icon={<FileProtectOutlined />} onClick={() => setEditTerms(true)}>{t('pages.recruit.terms.title')}</Button>}
        </Space>
      </div>
      {!ov.lc_ready && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.err.migrationPending')} />}

      <Card size="small" style={{ marginBottom: 12 }}>
        <DetailBlock column={3}>
          <Descriptions.Item label={t('pages.recruit.proj.customer')}>{ov.customer_group_name || '-'}</Descriptions.Item>
          <Descriptions.Item label={t('pages.recruit.terms.entity')}>{ov.client_entity_name || '-'}</Descriptions.Item>
          {!internal && <Descriptions.Item label={t('pages.recruit.terms.defaultTerms')}>
            <Tag color={SERVICE_COLOR[ov.service_type]} style={{ marginInlineEnd: 4 }}>{t(`pages.recruit.svc.${ov.service_type}`)}</Tag>{feeText(ov, t)}</Descriptions.Item>}
          {!internal && <Descriptions.Item label={t('pages.recruit.terms.guarantee')}>{guaranteeText(ov, t)}</Descriptions.Item>}
          {!internal && <Descriptions.Item label={t('pages.recruit.terms.milestones')}>{monthly ? t('pages.recruit.terms.monthlyBill') : (milestones || t('pages.recruit.terms.manualBill'))}</Descriptions.Item>}
          {!internal && (
            <Descriptions.Item label={t('pages.recruit.terms.contract')}>
              <Space size={4} wrap>
                <Tag color={CONTRACT_COLOR[ov.contract_status]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.contract.${ov.contract_status || 'draft'}`)}</Tag>
                {!!ov.contract_signed_at && <Text type="secondary">{t('pages.recruit.terms.signedAt')} {fmtDay(ov.contract_signed_at)}</Text>}
                {!!(ov.contract_start || ov.contract_end) && <Text type="secondary">{fmtDay(ov.contract_start)} ~ {fmtDay(ov.contract_end)}</Text>}
              </Space>
            </Descriptions.Item>)}
          <Descriptions.Item label={t('pages.recruit.proj.manager')}>{ov.manager_name || '-'}</Descriptions.Item>
          <Descriptions.Item label={t('pages.recruit.proj.created')}>{fmtDay(ov.created_at)}</Descriptions.Item>
          {!!ov.terms_note && <Descriptions.Item label={t('pages.recruit.terms.note')} span={3}><div style={{ whiteSpace: 'pre-wrap' }}>{ov.terms_note}</div></Descriptions.Item>}
          {!!ov.description && <Descriptions.Item label={t('pages.recruit.proj.desc')} span={3}><div style={{ whiteSpace: 'pre-wrap' }}>{ov.description}</div></Descriptions.Item>}
        </DetailBlock>
        {/* 各职位条款（混合项目）：虚线标签 = 沿用项目默认，实线 = 单独约定 */}
        {!internal && ov.lc_ready && (ov.job_terms || []).length > 0 && (
          <>
            <Text strong style={{ display: 'block', margin: '12px 0 6px' }}>{t('pages.recruit.terms.jobTerms')}</Text>
            <DetailTable rowKey="id" dataSource={ov.job_terms} pagination={false} {...NO_HSCROLL}
              columns={[
                { title: t('pages.recruit.col.job'), render: (_: any, j: any) => (
                    <div><Text strong>{j.title}</Text> {j.status !== 'open' && <Tag>{t(`pages.recruit.jobStatus.${j.status}`)}</Tag>}</div>) },
                { title: t('pages.recruit.terms.serviceType'), width: 190, render: (_: any, j: any) => (
                    <Space size={4} wrap>
                      <Tag color={SERVICE_COLOR[j.service_type]} style={{ marginInlineEnd: 0, borderStyle: j.own ? 'solid' : 'dashed' }}>{t(`pages.recruit.svc.${j.service_type}`)}</Tag>
                      <Text type="secondary" style={{ fontSize: 12 }}>{t(j.own ? 'pages.recruit.terms.own' : 'pages.recruit.terms.inherit')}</Text>
                    </Space>) },
                { title: t('pages.recruit.terms.feeType'), width: 200, render: (_: any, j: any) => feeText(j, t) },
                { title: t('pages.recruit.terms.guarantee'), width: 180, render: (_: any, j: any) => guaranteeText(j, t) },
                { title: t('pages.recruit.terms.milestones'), width: 180, render: (_: any, j: any) => (
                    <span style={{ fontSize: 12 }}>{MONTHLY_SERVICES.includes(j.service_type) ? t('pages.recruit.terms.monthlyBill')
                      : ((j.billing_terms?.milestones || []).map((m: any) => `${t(`pages.recruit.milestone.${m.code}`)} ${m.percent}%`).join(' · ') || t('pages.recruit.terms.manualBill'))}</span>) },
                ...(canAdmin ? [{ title: '', width: 80, render: (_: any, j: any) => (
                    <Button size="small" type="link" icon={<EditOutlined />} onClick={() => setEditJob(j)}>{t('pages.recruit.edit')}</Button>) }] : []),
              ]} />
          </>
        )}
      </Card>

      <Card size="small" style={{ marginBottom: 12 }} bodyStyle={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'stretch' }}>
        {LINK_BUCKETS.map((b) => <Kpi key={b} label={t(`pages.recruit.kpi.${b}`)} value={k[b] ?? 0} color={Number(k[b]) > 0 ? BUCKET_COLOR[b] : undefined}
          active={tab === 'funnel' && bucket === b} onClick={() => drill(b)} tip={t(`pages.recruit.kpi.${b}Tip`)} />)}
        {ov.lc_ready && <div style={{ width: 1, background: '#f0f0f0', margin: '0 4px' }} />}
        {ov.lc_ready && PL_BUCKETS.map((b) => <Kpi key={b} label={t(`pages.recruit.kpi.${b}`)} value={k[b] ?? 0} color={Number(k[b]) > 0 ? BUCKET_COLOR[b] : undefined}
          active={tab === 'funnel' && bucket === b} onClick={() => drill(b)} tip={t(`pages.recruit.kpi.${b}Tip`)} />)}
        {!internal && ov.lc_ready && ov.can_see_money && (
          <Kpi label={t('pages.recruit.kpi.money')} tip={t('pages.recruit.kpi.moneyTip')}
            value={<div style={{ fontSize: 14, lineHeight: 1.5, textAlign: 'right' }}>
              {(ov.fee_by_type || []).length > 1
                ? ov.fee_by_type.map((f: any) => <div key={`${f.service_type}${f.currency}`}>{t(`pages.recruit.svc.${f.service_type}`)} {f.count}{t('pages.recruit.kpi.personUnit')} · {money(f.fee, f.currency)}</div>)
                : feeTotals.length ? feeTotals.map(([c, v]) => <div key={c}>{t('pages.recruit.kpi.fee')} {money(v, c)}</div>) : <div>{t('pages.recruit.kpi.fee')} -</div>}
            </div>} />)}
      </Card>

      <Card size="small">
        <Tabs activeKey={tab} onChange={setTab} items={[
          { key: 'jobs', label: `${t('pages.recruit.jobs')} (${(proj?.jobs || []).length})`,
            children: proj && <JobsSection p={proj} meta={meta} reload={load} onOpenMailbox={access.canAccessSettings ? () => history.push('/settings') : undefined} /> },
          { key: 'funnel', label: t('pages.recruit.ovw.funnel'), children: (
              <>
                <Segmented style={{ marginBottom: 10 }} value={bucket} onChange={(v) => setBucket(String(v))}
                  options={[...LINK_BUCKETS, ...(ov.lc_ready ? PL_BUCKETS : [])].map((b) => ({ value: b, label: `${t(`pages.recruit.kpi.${b}`)} ${k[b] ?? 0}` }))} />
                <Spin spinning={rowsLoading}>
                  {PL_BUCKETS.includes(bucket)
                    ? <PlacementsTab rows={rows} t={t} meta={metaA} mode="project" project={ov} onChanged={reloadAll} onOpenCandidate={setOpenCand} />
                    : <DetailTable rowKey="id" dataSource={rows} pagination={{ pageSize: 50, hideOnSinglePage: true }} {...NO_HSCROLL}
                        rowClassName={() => 'recruit-row'} onRow={(r: any) => ({ onClick: () => setOpenCand(Number(r.candidate_id)) })}
                        columns={[
                          { title: t('pages.recruit.candidate'), render: (_: any, r: any) => (
                              <div><Text strong>{r.cand_name || '-'}</Text> <Text type="secondary" style={{ fontSize: 12 }}>{r.cand_code}</Text>
                                <div style={{ fontSize: 12, color: '#8c8c8c' }}>{r.owner_user_name || t('pages.recruit.unclaimed')}</div></div>) },
                          { title: t('pages.recruit.col.job'), width: 200, dataIndex: 'job_title' },
                          { title: t('pages.recruit.col.stage'), width: 150, render: (_: any, r: any) => (
                              <Space direction="vertical" size={2}>
                                <Tag color={STAGE_COLOR[r.stage]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.stage.${r.stage}`)}</Tag>
                                <Text type="secondary" style={{ fontSize: 12 }}>{fmtDay(r.stage_changed_at)}</Text>
                              </Space>) },
                          { title: t('pages.recruit.col.score'), width: 120, render: (_: any, r: any) => <ScoreTag score={effScore(r)} t={t} human={r.human_score !== null && r.human_score !== undefined} /> },
                          { title: t('pages.recruit.ovw.followCol'), width: 170, render: (_: any, r: any) => (
                              <div style={{ fontSize: 12 }}>
                                <div>{r.last_followup_at ? fmtMin(r.last_followup_at) : '-'}</div>
                                {!!r.next_followup_at && <Text type="secondary">{t('pages.recruit.fu.nextAt', { at: fmtMin(r.next_followup_at) })}</Text>}
                              </div>) },
                        ]} />}
                </Spin>
              </>) },
          ...(ov.lc_ready ? [
            { key: 'placements', label: `${t('pages.recruit.ovw.placements')} (${(ov.placements || []).length})`,
              children: (ov.placements || []).length
                ? <PlacementsTab rows={ov.placements} t={t} meta={metaA} mode="project" project={ov} onChanged={reloadAll} onOpenCandidate={setOpenCand} />
                : <Empty description={t('pages.recruit.ovw.noPlacement')} /> },
            { key: 'docs', label: `${t('pages.recruit.ovw.docs')} (${(ov.documents || []).length})`,
              children: <DocumentsTab docs={ov.documents || []} t={t} meta={metaA} entities={docEntities} onChanged={load} /> },
          ] : []),
        ]} />
      </Card>

      <ProjectFormDrawer open={editProj} project={proj} meta={meta} onClose={() => setEditProj(false)} onSaved={() => { setEditProj(false); load(); }} />
      <ProjectTermsDrawer open={editTerms} project={ov} meta={meta} t={t} onClose={() => setEditTerms(false)} onSaved={() => { setEditTerms(false); load(); }} />
      <ProjectTermsDrawer open={!!editJob} project={ov} job={editJob || undefined} meta={meta} t={t} onClose={() => setEditJob(null)} onSaved={() => { setEditJob(null); load(); }} />
      <CandidateDrawer open={openCand !== null} candidateId={openCand} meta={meta} onClose={() => setOpenCand(null)} onChanged={reloadAll} />
    </div>
  );
};

export default Overview;
