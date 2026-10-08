/**
 * 招聘绩效看板（「用大屏报表的形式展示，现在太笼统」）。
 *   ① KPI：收到简历 / 新增候选人 / ≥4 分 / 推荐给客户 / 进面 / 录用 / 人工跟进 / AI 成本
 *   ② 每日趋势（简历、新增候选人、人工跟进）   ③ 招聘漏斗（本期新挂上的人×职位走到了哪一步）
 *   ④ 招聘专员对比   ⑤ 简历来源（哪个 plus 地址 / 上传 / 导入）   ⑥ 项目人选分布   ⑦ 明细表（数字可点，跳人才库）
 * 明细表的每个数字与人才库列表共用 recruitCandidateFilterSql（§6.7.1），点进去的总数一定等于这里的数字。
 * 时间按月选（§6.5 按周期单位切 picker）。
 */
import React, { useCallback, useEffect, useState } from 'react';
import { Table, DatePicker, Space, Typography, Tag, Card, Row, Col, Statistic, Empty, Tooltip as ATooltip } from 'antd';
import StatCard from '../components/StatCard';
import { useIntl, history } from '@umijs/max';
import dayjs, { Dayjs } from 'dayjs';
import {
  ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, BarChart, Bar, PieChart, Pie, Cell,
} from 'recharts';
import { recruitStats } from '@/services/api';
import { showErr, STAGE_COLOR } from '../common';

const { Text } = Typography;
const BRAND = '#1a2ad6';
const PALETTE = ['#1a2ad6', '#13c2c2', '#fa8c16', '#52c41a', '#eb2f96', '#722ed1', '#faad14', '#8c8c8c'];
/** KPI 卡插图（components/StatCard 的手绘插图名） */
const KPI_ART: Record<string, string> = {
  resumes: 'inbox', new_candidates: 'newPerson', strong: 'star', submitted: 'recommend', interviewing: 'interview',
  hired: 'placed', followups: 'followup', ai: 'aicost',
};
const STAGE_HEX: Record<string, string> = { suggested: '#bfbfbf', shortlisted: '#1677ff', submitted: '#13c2c2', interviewing: '#722ed1', offered: '#faad14', hired: '#52c41a' };

const Stats: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [month, setMonth] = useState<Dayjs>(dayjs().startOf('month'));
  const [rows, setRows] = useState<any[]>([]);
  const [dash, setDash] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const from = month.startOf('month').format('YYYY-MM-DD');
  const to = month.endOf('month').format('YYYY-MM-DD');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const r = await recruitStats({ from, to });
      if (r?.success) { setRows(r.data.rows || []); setDash(r.data.dashboard || null); } else showErr(r, t);
    } finally { setLoading(false); }
  }, [from, to, t]);
  useEffect(() => { load(); }, [load]);

  /** 数字 → 人才库链接：参数与后端统计用的完全一致 */
  const drill = (n: number, params: Record<string, any>) => {
    if (!n) return <Text type="secondary">0</Text>;
    const q = new URLSearchParams({ from, to, ...Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)])) });
    return <a onClick={() => history.push(`/recruit/talent?${q.toString()}`)}>{n}</a>;
  };
  const owner = (r: any) => (r.unclaimed ? 'unclaimed' : r.user_id);

  const columns = [
    { title: t('pages.recruit.col.owner'), render: (_: any, r: any) => r.unclaimed ? <Tag color="orange">{t('pages.recruit.unclaimed')}</Tag> : r.user_name },
    { title: t('pages.recruit.stats.new'), width: 130, align: 'right' as const, render: (_: any, r: any) => drill(r.new_candidates, { owner_id: owner(r) }) },
    { title: t('pages.recruit.stats.handled'), width: 130, align: 'right' as const, render: (_: any, r: any) => r.unclaimed ? '-' : drill(r.handled, { source_user_id: r.user_id }) },
    { title: t('pages.recruit.stats.strong'), width: 130, align: 'right' as const, render: (_: any, r: any) => drill(r.strong, { owner_id: owner(r), min_score: 4 }) },
    { title: t('pages.recruit.stats.interviewing'), width: 120, align: 'right' as const, render: (_: any, r: any) => r.unclaimed ? '-' : drill(r.interviewing, { owner_id: r.user_id, stage_reached: 'interviewing' }) },
    { title: t('pages.recruit.stats.hired'), width: 110, align: 'right' as const, render: (_: any, r: any) => r.unclaimed ? '-' : drill(r.hired, { owner_id: r.user_id, stage_reached: 'hired' }) },
    { title: t('pages.recruit.stats.followups'), width: 110, align: 'right' as const, render: (_: any, r: any) => r.unclaimed ? '-' : r.followups },
  ];

  const k = dash?.kpi || {};
  const kpis = [
    { key: 'resumes', v: k.resumes, sub: t('pages.recruit.dash.parsedOf', { n: k.parsed }) },
    { key: 'new_candidates', v: k.new_candidates },
    { key: 'strong', v: k.strong },
    { key: 'submitted', v: k.submitted },
    { key: 'interviewing', v: k.interviewing },
    { key: 'hired', v: k.hired },
    { key: 'followups', v: k.followups },
    { key: 'ai', v: `$${Number(k.ai_usd || 0).toFixed(2)}`,
      sub: t('pages.recruit.dash.aiSub', { tokens: Number(k.ai_tokens || 0).toLocaleString(), per: k.per_resume_tokens ? Number(k.per_resume_tokens).toLocaleString() : '-' }) },
  ];
  const recruiters = rows.filter((r) => !r.unclaimed).map((r) => ({
    name: r.user_name, [t('pages.recruit.stats.new')]: r.new_candidates, [t('pages.recruit.stats.strong')]: r.strong,
    [t('pages.recruit.stats.interviewing')]: r.interviewing, [t('pages.recruit.stats.hired')]: r.hired,
  }));
  const sources = (dash?.sources || []).map((s: any) => ({
    name: s.origin === 'email' ? (s.code ? `+${s.code} ${s.user_name}` : t('pages.recruit.dash.noCode')) : t(`pages.recruit.origin.${s.origin}`),
    value: s.n, cv: s.cv,
  }));
  const funnelMax = Math.max(1, ...(dash?.funnel || []).map((f: any) => f.n));
  const chartH = 260;

  return (
    <div>
      <Space style={{ marginBottom: 12 }}>
        <DatePicker picker="month" value={month} allowClear={false} onChange={(v) => v && setMonth(v.startOf('month'))} />
        <Text type="secondary">{t('pages.recruit.stats.hint')}</Text>
      </Space>

      {/* ① KPI：与其它招聘页同一套手绘插图统计卡（StatCard） */}
      <Row gutter={[12, 12]} style={{ marginBottom: 12 }}>
        {kpis.map((x) => (
          <Col key={x.key} xs={12} md={6} xl={3}>
            <StatCard art={KPI_ART[x.key]} minWidth={0} title={t(`pages.recruit.dash.kpi.${x.key}`)}
              value={loading ? '-' : (x.v ?? 0)} sub={x.sub} valueColor={x.key === 'hired' ? '#389e0d' : BRAND} />
          </Col>
        ))}
      </Row>

      <Row gutter={[12, 12]} style={{ marginBottom: 12 }}>
        {/* ② 每日趋势 */}
        <Col xs={24} xl={14}>
          <Card size="small" title={t('pages.recruit.dash.trend')} loading={loading}>
            <ResponsiveContainer width="100%" height={chartH}>
              <LineChart data={dash?.trend || []} margin={{ left: -20, right: 10 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="day" tick={{ fontSize: 11 }} interval="preserveStartEnd" />
                <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                <Tooltip />
                <Legend />
                <Line type="monotone" dataKey="resumes" name={t('pages.recruit.dash.kpi.resumes')} stroke={BRAND} strokeWidth={2} dot={false} />
                <Line type="monotone" dataKey="candidates" name={t('pages.recruit.dash.kpi.new_candidates')} stroke="#13c2c2" strokeWidth={2} dot={false} />
                <Line type="monotone" dataKey="followups" name={t('pages.recruit.dash.kpi.followups')} stroke="#fa8c16" strokeWidth={2} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          </Card>
        </Col>
        {/* ③ 漏斗 */}
        <Col xs={24} xl={10}>
          <Card size="small" loading={loading}
            title={<ATooltip title={t('pages.recruit.dash.funnelHint')}>{t('pages.recruit.dash.funnel')}</ATooltip>}>
            <div style={{ height: chartH, display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 8 }}>
              {(dash?.funnel || []).map((f: any, i: number, arr: any[]) => {
                const prev = i > 0 ? arr[i - 1].n : 0;
                return (
                  <div key={f.stage} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <span style={{ width: 70, fontSize: 12, textAlign: 'right' }}><Tag color={STAGE_COLOR[f.stage]} style={{ margin: 0 }}>{t(`pages.recruit.stage.${f.stage}`)}</Tag></span>
                    <div style={{ flex: 1, display: 'flex', justifyContent: 'center' }}>
                      <div style={{ width: `${Math.max(4, (f.n / funnelMax) * 100)}%`, background: STAGE_HEX[f.stage], color: '#fff', borderRadius: 4,
                        textAlign: 'center', fontWeight: 600, fontSize: 13, lineHeight: '26px', minWidth: 28 }}>{f.n}</div>
                    </div>
                    <span style={{ width: 52, fontSize: 11, color: '#888' }}>{i > 0 && prev > 0 ? `${Math.round((f.n / prev) * 100)}%` : ''}</span>
                  </div>
                );
              })}
            </div>
          </Card>
        </Col>
      </Row>

      <Row gutter={[12, 12]} style={{ marginBottom: 12 }}>
        {/* ④ 招聘专员对比 */}
        <Col xs={24} xl={14}>
          <Card size="small" title={t('pages.recruit.dash.recruiters')} loading={loading}>
            {recruiters.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} style={{ height: chartH }} /> : (
              <ResponsiveContainer width="100%" height={chartH}>
                <BarChart data={recruiters} margin={{ left: -20, right: 10 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Legend />
                  {[t('pages.recruit.stats.new'), t('pages.recruit.stats.strong'), t('pages.recruit.stats.interviewing'), t('pages.recruit.stats.hired')]
                    .map((key, i) => <Bar key={key} dataKey={key} fill={PALETTE[i]} radius={[3, 3, 0, 0]} />)}
                </BarChart>
              </ResponsiveContainer>
            )}
          </Card>
        </Col>
        {/* ⑤ 简历来源 */}
        <Col xs={24} xl={10}>
          <Card size="small" title={t('pages.recruit.dash.sources')} loading={loading}>
            {sources.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} style={{ height: chartH }} /> : (
              <ResponsiveContainer width="100%" height={chartH}>
                <PieChart>
                  <Pie data={sources} dataKey="value" nameKey="name" innerRadius={55} outerRadius={90} paddingAngle={2}
                    label={(e: any) => `${e.name} ${e.value}`}>
                    {sources.map((_: any, i: number) => <Cell key={i} fill={PALETTE[i % PALETTE.length]} />)}
                  </Pie>
                  <Tooltip formatter={(v: any, _n: any, p: any) => [t('pages.recruit.dash.sourceTip', { n: v, cv: p?.payload?.cv }), p?.payload?.name]} />
                </PieChart>
              </ResponsiveContainer>
            )}
          </Card>
        </Col>
      </Row>

      {/* ⑥ 项目人选分布 */}
      <Card size="small" title={t('pages.recruit.dash.projects')} loading={loading} style={{ marginBottom: 12 }}>
        {(dash?.projects || []).length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> : (
          <ResponsiveContainer width="100%" height={Math.max(120, (dash?.projects || []).length * 40)}>
            <BarChart layout="vertical" data={(dash?.projects || []).map((p: any) => ({ ...p, label: p.group_name ? `${p.name} · ${p.group_name}` : p.name }))}
              margin={{ left: 10, right: 20 }}>
              <XAxis type="number" allowDecimals={false} tick={{ fontSize: 11 }} />
              <YAxis type="category" dataKey="label" width={260} tick={{ fontSize: 11 }} />
              <Tooltip />
              <Legend />
              {['suggested', 'shortlisted', 'interviewing', 'hired'].map((s) => (
                <Bar key={s} dataKey={s} stackId="a" name={t(`pages.recruit.dash.pipe.${s}`)} fill={STAGE_HEX[s]} />
              ))}
            </BarChart>
          </ResponsiveContainer>
        )}
      </Card>

      {/* ⑦ 明细（数字可点） */}
      <Card size="small" title={t('pages.recruit.dash.detail')}>
        <Table rowKey={(r: any) => (r.unclaimed ? 'unclaimed' : r.user_id)} loading={loading} dataSource={rows} columns={columns as any} pagination={false} size="small" />
      </Card>
    </div>
  );
};

export default Stats;
