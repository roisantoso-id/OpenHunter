/**
 * 人力工作台 · 我的数据图表（「做成图表，支持时间切换：今天 / 最近一周 / 一个月 / 三个月」）。
 * 顶部：所选时间段的合计（收到简历 / 新增候选人 / 人工跟进 / 推进到面试 / 录用）
 * 左：按日期的折线趋势（今天按小时，其余按天）——收到简历 / 新增候选人 / 人工跟进 / 推进到面试 / 录用
 * 右：我名下候选人按状态分布（当前快照，不随时间切换）
 * 口径见后端 handleRecruitWorkbenchStats。
 */
import React, { useCallback, useEffect, useState } from 'react';
import { Card, Segmented, Row, Col, Statistic, Empty, Spin } from 'antd';
import { useIntl } from '@umijs/max';
import { ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, PieChart, Pie, Cell } from 'recharts';
import { recruitWorkbenchStats } from '@/services/api';
import { showErr } from '../common';

type Range = 'today' | '7d' | '30d' | '90d';
const BRAND = '#1a2ad6';
const STATUS_HEX: Record<string, string> = {
  new: '#1677ff', contacting: '#13c2c2', in_process: '#722ed1', on_hold: '#bfbfbf', placed: '#52c41a',
  not_interested: '#8c8c8c', unreachable: '#fa8c16', blacklisted: '#cf1322',
};
const KPIS = ['resumes', 'candidates', 'followups', 'interviews', 'hired'] as const;
const LINE_HEX: Record<string, string> = { resumes: BRAND, candidates: '#13c2c2', followups: '#fa8c16', interviews: '#722ed1', hired: '#52c41a' };

const WorkbenchStats: React.FC<{ userId?: number }> = ({ userId }) => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [range, setRange] = useState<Range>('7d');
  const [d, setD] = useState<any>(null);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const r = await recruitWorkbenchStats({ range, user_id: userId || undefined });
      if (r?.success) setD(r.data); else showErr(r, t);
    } finally { setLoading(false); }
  }, [range, userId, t]);
  useEffect(() => { load(); }, [load]);

  const series = (d?.series || []).map((p: any) => ({ ...p }));
  const status = (d?.by_status || []).map((s: any) => ({ name: t(`pages.recruit.status.${s.status}`), value: s.n, key: s.status }));

  return (
    <Card size="small" style={{ marginBottom: 14 }} title={t('pages.recruit.wb.statsTitle')}
      extra={<Segmented size="small" value={range} onChange={(v) => setRange(v as Range)}
        options={(['today', '7d', '30d', '90d'] as Range[]).map((r) => ({ value: r, label: t(`pages.recruit.wb.range.${r}`) }))} />}>
      {!d ? <Spin style={{ display: 'block', margin: '40px auto' }} /> : (
        <Spin spinning={loading}>
          <Row gutter={12} style={{ marginBottom: 8 }}>
            {KPIS.map((k) => (
              <Col key={k} flex="1 1 0">
                <Statistic title={t(`pages.recruit.wb.kpi.${k}`)} value={d.totals?.[k] ?? 0}
                  valueStyle={{ fontSize: 22, fontWeight: 600, color: k === 'hired' ? '#389e0d' : BRAND }} />
              </Col>
            ))}
          </Row>
          <Row gutter={12}>
            <Col xs={24} xl={17}>
              <ResponsiveContainer width="100%" height={240}>
                <LineChart data={series} margin={{ left: -20, right: 10, top: 8 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} interval="preserveStartEnd" minTickGap={12} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Legend />
                  {KPIS.map((k) => (
                    <Line key={k} type="monotone" dataKey={k} name={t(`pages.recruit.wb.kpi.${k}`)} stroke={LINE_HEX[k]}
                      strokeWidth={k === 'resumes' ? 2.5 : 2} dot={series.length <= 31 ? { r: 2 } : false} activeDot={{ r: 4 }} />
                  ))}
                </LineChart>
              </ResponsiveContainer>
            </Col>
            <Col xs={24} xl={7}>
              <div style={{ fontSize: 12, color: '#888', textAlign: 'center' }}>{t('pages.recruit.wb.byStatus')}</div>
              {status.length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} style={{ marginTop: 40 }} /> : (
                <ResponsiveContainer width="100%" height={220}>
                  <PieChart>
                    <Pie data={status} dataKey="value" nameKey="name" innerRadius={45} outerRadius={75} paddingAngle={2} label={(e: any) => `${e.value}`}>
                      {status.map((s: any) => <Cell key={s.key} fill={STATUS_HEX[s.key] || '#bfbfbf'} />)}
                    </Pie>
                    <Tooltip />
                    <Legend iconSize={8} wrapperStyle={{ fontSize: 11 }} />
                  </PieChart>
                </ResponsiveContainer>
              )}
            </Col>
          </Row>
        </Spin>
      )}
    </Card>
  );
};

export default WorkbenchStats;
