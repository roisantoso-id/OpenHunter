/**
 * 招聘项目：一个客户的一次猎头/招聘委托 = 一个项目（内部招聘也是一个项目）。
 * 顶部汇总卡 + 项目卡片网格，
 * 每张卡直接看到客户、开放职位、人选、≥4 分、进行中、已录用/招聘人数、负责人、合作模式与合同。点卡片进项目总览页。
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, Tag, Space, Button, Segmented, Typography, Row, Col, Empty, Spin, Progress, Tooltip } from 'antd';
import { PlusOutlined, SettingOutlined, UserOutlined, RightOutlined, ShopOutlined } from '@ant-design/icons';
import { useIntl, useAccess, history } from '@umijs/max';
import { recruitMeta, recruitListProjects } from '@/services/api';
import { ProjectFormDrawer } from '../components/ProjectDrawer';
import StatCard from '../components/StatCard';
import { showErr, fmtDay, SERVICE_COLOR, CONTRACT_COLOR } from '../common';
import PipelineButton from '../components/PipelineButton';
import DoodleEmpty from '../components/DoodleEmpty';

const { Text, Title } = Typography;
const BRAND = '#1a2ad6';

const Metric: React.FC<{ label: string; value: React.ReactNode; color?: string; tip?: string }> = ({ label, value, color, tip }) => (
  <Tooltip title={tip}>
    <div style={{ textAlign: 'center', minWidth: 0 }}>
      <div style={{ fontSize: 20, fontWeight: 600, lineHeight: 1.3, color: color || '#262626' }}>{value}</div>
      <div style={{ fontSize: 12, color: '#8c8c8c', whiteSpace: 'nowrap' }}>{label}</div>
    </div>
  </Tooltip>
);

const Projects: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [meta, setMeta] = useState<any>(null);
  const [rows, setRows] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState<string>('open');
  const [creating, setCreating] = useState(false);
  const access = useAccess() as any;
  // 招聘邮箱与代码、权限都在「系统设置 · 招聘设置」里
  const openSettings = access.canAccessSettings ? () => history.push('/settings') : undefined;

  const loadMeta = useCallback(() => { recruitMeta().then((r: any) => (r?.success ? setMeta(r.data) : showErr(r, t))); }, [t]);
  useEffect(() => { loadMeta(); }, [loadMeta]);
  const load = useCallback(async () => {
    setLoading(true);
    try {
      const r = await recruitListProjects(status === 'all' ? {} : { status });
      if (r?.success) setRows(r.data || []); else showErr(r, t);
    } finally { setLoading(false); }
  }, [status, t]);
  useEffect(() => { load(); }, [load]);

  const sum = useMemo(() => rows.reduce((a, p) => ({
    projects: a.projects + (p.status === 'open' ? 1 : 0), jobs: a.jobs + Number(p.jobs_open || 0),
    active: a.active + Number(p.active_count || 0), strong: a.strong + Number(p.strong_count || 0), hired: a.hired + Number(p.hired_count || 0),
  }), { projects: 0, jobs: 0, active: 0, strong: 0, hired: 0 }), [rows]);

  const card = (p: any) => {
    const hc = Number(p.headcount || 0);
    const hired = Number(p.hired_count || 0);
    return (
      <Card hoverable onClick={() => history.push(`/recruit/projects/${p.id}`)} style={{ height: '100%', borderRadius: 10 }}
        bodyStyle={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 12, height: '100%' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
          <div style={{ minWidth: 0 }}>
            <Text strong style={{ fontSize: 15 }} ellipsis={{ tooltip: p.name }}>{p.name}</Text>
            <div style={{ marginTop: 4, fontSize: 12, color: '#595959' }}>
              <ShopOutlined style={{ marginRight: 4, color: '#8c8c8c' }} />
              {p.customer_group_name || (p.kind === 'internal' ? t('pages.recruit.kind.internal') : '-')}
            </div>
          </div>
          <Space direction="vertical" size={4} align="end" style={{ flexShrink: 0 }}>
            <Tag color={p.kind === 'internal' ? 'purple' : 'blue'} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.kind.${p.kind}`)}</Tag>
            <Tag color={p.status === 'open' ? 'green' : p.status === 'paused' ? 'orange' : 'default'} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.projStatus.${p.status}`)}</Tag>
          </Space>
        </div>
        {/* 合作模式 + 合同状态：签约情况一眼看清 */}
        {p.kind !== 'internal' && !!(p.service_types || p.service_type) && (
          <Space size={4} wrap style={{ marginTop: -4 }}>
            {String(p.service_types || p.service_type).split(',').filter(Boolean).map((s: string) => (
              <Tag key={s} color={SERVICE_COLOR[s]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.svc.${s}`)}</Tag>))}
            <Tag color={CONTRACT_COLOR[p.contract_status]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.contract.${p.contract_status || 'draft'}`)}</Tag>
            {Number(p.pl_in_guarantee) > 0 && <Tag color="processing" style={{ marginInlineEnd: 0 }}>{t('pages.recruit.kpi.in_guarantee')} {p.pl_in_guarantee}</Tag>}
          </Space>
        )}

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: 4, background: '#fafbff', borderRadius: 8, padding: '10px 4px' }}>
          <Metric label={t('pages.recruit.proj.m.jobs')} value={`${p.jobs_open}/${p.jobs_total}`} tip={t('pages.recruit.proj.m.jobsTip')} />
          <Metric label={t('pages.recruit.proj.m.candidates')} value={p.candidate_count ?? 0} tip={t('pages.recruit.proj.m.candidatesTip')} />
          {/* 匹配人数 = AI / 人工评分 ≥3（合格线，与列表隐藏低分同一条线）的去重人数；「人选」含 AI 挂上但没过线的 */}
          <Metric label={t('pages.recruit.proj.m.matched')} value={p.matched_count ?? 0} tip={t('pages.recruit.proj.m.matchedTip')} color={Number(p.matched_count) > 0 ? '#1a2ad6' : undefined} />
          <Metric label={t('pages.recruit.job.strong')} value={p.strong_count ?? 0} color={Number(p.strong_count) > 0 ? '#d48806' : undefined} />
          <Metric label={t('pages.recruit.job.active')} value={p.active_count ?? 0} color={Number(p.active_count) > 0 ? '#722ed1' : undefined} />
        </div>

        <div>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, color: '#8c8c8c' }}>
            <span>{t('pages.recruit.proj.m.hired')}</span><span>{hired} / {hc || '-'}</span>
          </div>
          <Progress percent={hc ? Math.min(100, Math.round((hired / hc) * 100)) : 0} showInfo={false} size="small" strokeColor="#52c41a" />
        </div>
        <div style={{ marginTop: 'auto', display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 12, color: '#8c8c8c',
          borderTop: '1px solid #f0f0f0', paddingTop: 10 }}>
          <span><UserOutlined style={{ marginRight: 4 }} />{p.manager_name || t('pages.recruit.proj.noManager')} · {fmtDay(p.created_at)}</span>
          <span style={{ color: BRAND }}>{t('pages.recruit.proj.open')} <RightOutlined style={{ fontSize: 10 }} /></span>
        </div>
      </Card>
    );
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14, flexWrap: 'wrap', gap: 8 }}>
        <Space size={12} align="center">
          <Title level={5} style={{ margin: 0 }}>{t('menu.recruit.projects')}</Title>
          <Segmented value={status} onChange={(v) => setStatus(String(v))}
            options={['open', 'paused', 'closed', 'all'].map((s) => ({ value: s, label: t(`pages.recruit.projStatus.${s}`) }))} />
        </Space>
        <Space>
          <PipelineButton onDone={load} />
          {openSettings && <Button icon={<SettingOutlined />} onClick={openSettings}>{t('pages.recruit.cfg.menu')}</Button>}
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreating(true)}>{t('pages.recruit.proj.new')}</Button>
        </Space>
      </div>

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <StatCard art="files" title={t('pages.recruit.proj.sum.projects')} value={sum.projects} />
        <StatCard art="month" title={t('pages.recruit.proj.sum.jobs')} value={sum.jobs} />
        <StatCard art="star" title={t('pages.recruit.proj.sum.strong')} value={sum.strong} />
        <StatCard art="rocket" title={t('pages.recruit.proj.sum.active')} value={sum.active} />
        <StatCard art="placed" title={t('pages.recruit.proj.sum.hired')} value={sum.hired} />
      </div>

      <Spin spinning={loading}>
        {rows.length === 0 && !loading ? (
          <Card><DoodleEmpty kind="list" description={t('pages.recruit.proj.empty')}>
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreating(true)}>{t('pages.recruit.proj.new')}</Button>
          </DoodleEmpty></Card>
        ) : (
          <Row gutter={[16, 16]}>
            {rows.map((p) => <Col key={p.id} xs={24} md={12} xl={8} xxl={6}>{card(p)}</Col>)}
          </Row>
        )}
      </Spin>

      <ProjectFormDrawer open={creating} meta={meta} onClose={() => setCreating(false)}
        onSaved={(id) => { setCreating(false); history.push(`/recruit/projects/${id}`); }} />
    </div>
  );
};

export default Projects;
