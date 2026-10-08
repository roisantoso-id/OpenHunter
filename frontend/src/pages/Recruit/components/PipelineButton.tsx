/**
 * 「立即 AI 匹配」按钮 + 运行记录抽屉（「点击之后匹配的日志要展示出来」）。
 * 点按钮：后台拉起一轮 解析 → 拆要求 → 向量化 → 语义分 → 大模型精排 → 高分提醒，自动打开抽屉看这一轮：
 *   6 步进度、逐行日志（运行中每 2 秒刷新）、完成后的汇总；下方是最近 30 轮记录（手动 / 定时任务），点哪轮看哪轮。
 * 旁边的时钟按钮只看记录不启动。后端：recruitRunPipeline / recruitPipelineRuns / recruitPipelineRun。
 */
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Button, Drawer, Steps, Tag, Table, Tooltip, Space, Typography, Empty, Statistic, Row, Col, Alert, message } from 'antd';
import { ThunderboltOutlined, HistoryOutlined } from '@ant-design/icons';
import { useIntl } from '@umijs/max';
import { recruitRunPipeline, recruitPipelineRuns, recruitPipelineRun } from '@/services/api';
import { showErr, fmtMin } from '../common';
import DoodleEmpty from './DoodleEmpty';

const { Text } = Typography;
const STEP_MARK = ['①', '②', '③', '④', '⑤', '⑥'];
const STATUS_COLOR: Record<string, string> = { queued: 'default', running: 'processing', done: 'success', aborted: 'warning', failed: 'error', skipped: 'default', stale: 'error' };
const secText = (s?: number | null) => (s === null || s === undefined ? '-' : s >= 60 ? `${Math.floor(s / 60)}m${s % 60}s` : `${s}s`);

/** label：按钮文字，默认「立即 AI 匹配」；简历解析页传「立即解析」（跑的是同一条流水线，从解析那步开始） */
const PipelineButton: React.FC<{ type?: 'primary' | 'default'; size?: 'small' | 'middle'; onDone?: () => void; label?: string }> = ({ type = 'default', size, onDone, label }) => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [open, setOpen] = useState(false);
  const [starting, setStarting] = useState(false);
  const [runs, setRuns] = useState<any[]>([]);
  const [runId, setRunId] = useState<number>(0);
  const [run, setRun] = useState<any>(null);
  const logRef = useRef<HTMLPreElement>(null);
  const wasLive = useRef(false);

  const loadRuns = useCallback(async () => {
    const r = await recruitPipelineRuns();
    if (r?.success) setRuns(r.data || []); else showErr(r, t);
    return r?.data || [];
  }, [t]);
  const loadRun = useCallback(async (id: number) => {
    if (!id) return;
    const r = await recruitPipelineRun(id);
    if (r?.success) setRun(r.data);
  }, []);

  const start = async () => {
    setStarting(true);
    try {
      const r = await recruitRunPipeline();
      if (!r?.success) { showErr(r, t); return; }
      // 运行记录表还没建（建表脚本没重跑）：匹配照样在跑，只是没有日志可看
      if (r.data?.no_log) { message.warning(t('pages.recruit.err.runTableMissing'), 8); return; }
      setOpen(true);
      const id = Number(r.data?.run_id || 0);
      const list = await loadRuns();
      setRunId(id || Number(list[0]?.id || 0));
    } finally { setStarting(false); }
  };
  const openHistory = async () => {
    setOpen(true);
    const list = await loadRuns();
    setRunId(Number(list[0]?.id || 0));
  };

  useEffect(() => { if (open && runId) loadRun(runId); }, [open, runId, loadRun]);
  // 运行中每 2 秒刷新；跑完刷新列表并通知页面重载数据
  const live = run && ['queued', 'running'].includes(run.status);
  useEffect(() => {
    if (!open || !live) {
      if (wasLive.current && run && !live) { wasLive.current = false; loadRuns(); onDone?.(); }
      return undefined;
    }
    wasLive.current = true;
    const h = setInterval(() => loadRun(runId), 2000);
    return () => clearInterval(h);
  }, [open, live, runId, run, loadRun, loadRuns, onDone]);
  useEffect(() => { if (logRef.current) logRef.current.scrollTop = logRef.current.scrollHeight; }, [run?.log]);

  // 6 步状态：日志里出现 ①…⑥ 算开始；后一步开始或整轮结束算完成；中止/失败时最后开始的那步标红
  const log: string = run?.log || '';
  const started = STEP_MARK.map((m) => log.includes(` ${m} `));
  const lastStarted = started.lastIndexOf(true);
  const ended = run && !live;
  const stepItems = STEP_MARK.map((_, i) => {
    let status: 'wait' | 'process' | 'finish' | 'error' = 'wait';
    if (started[i]) status = (i < lastStarted || ended) ? 'finish' : 'process';
    if (ended && ['aborted', 'failed', 'stale'].includes(run.status) && i === lastStarted) status = 'error';
    return { title: t(`pages.recruit.run.step${i + 1}`), status };
  });
  const s = run?.summary || {};
  // 手动（谁点的）/ 自动（为什么：新邮件简历、上传简历、职位变更）/ 定时任务
  const triggerText = (r: any) => (r.trigger_type === 'manual' ? t('pages.recruit.run.manual', { name: r.started_by_name || '-' })
    : r.trigger_type === 'auto' ? t('pages.recruit.run.auto', { why: r.started_by_name || '-' }) : t('pages.recruit.run.cron'));

  return (
    <>
      <Space.Compact>
        <Tooltip title={t('pages.recruit.pipeline.tip')}>
          <Button type={type} size={size} icon={<ThunderboltOutlined />} loading={starting} onClick={start}>{label || t('pages.recruit.pipeline.run')}</Button>
        </Tooltip>
        <Tooltip title={t('pages.recruit.run.history')}>
          <Button type={type} size={size} icon={<HistoryOutlined />} onClick={openHistory} />
        </Tooltip>
      </Space.Compact>

      <Drawer open={open} onClose={() => setOpen(false)} width={Math.min(900, (typeof window !== 'undefined' ? window.innerWidth : 900) - 40)}
        title={t('pages.recruit.run.title')} destroyOnClose>
        {!run ? <DoodleEmpty size="small" kind="inbox" description={t('pages.recruit.run.none')} /> : (
          <>
            <Space wrap style={{ marginBottom: 12 }}>
              <Tag color={STATUS_COLOR[run.status]}>{t(`pages.recruit.run.status.${run.status}`)}</Tag>
              <Text>{triggerText(run)}</Text>
              <Text type="secondary">{fmtMin(run.started_at)} · {t('pages.recruit.run.took', { s: secText(run.seconds) })}</Text>
            </Space>
            <Steps size="small" items={stepItems} style={{ marginBottom: 14 }} />
            {run.status === 'skipped' && <Alert type="info" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.run.skippedHint')} />}
            {run.status === 'stale' && <Alert type="error" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.run.staleHint')} />}
            {ended && run.summary && (
              <Row gutter={12} style={{ marginBottom: 12 }}>
                <Col span={5}><Statistic title={t('pages.recruit.run.s.parsed')} value={s.parse?.parsed ?? 0} /></Col>
                <Col span={5}><Statistic title={t('pages.recruit.run.s.newCand')} value={s.parse?.candidates_new ?? 0} /></Col>
                <Col span={4}><Statistic title={t('pages.recruit.run.s.jd')} value={s.jd?.ok ?? 0} /></Col>
                <Col span={5}><Statistic title={t('pages.recruit.run.s.vectors')} value={s.embed?.texts ?? 0} /></Col>
                <Col span={5}><Statistic title={t('pages.recruit.run.s.pairs')} value={s.match?.pairs ?? 0} valueStyle={{ color: '#1a2ad6' }} /></Col>
              </Row>
            )}
            <pre ref={logRef} style={{ background: '#0f172a', color: '#e2e8f0', fontSize: 12, lineHeight: 1.6, padding: 12, borderRadius: 8,
              maxHeight: 360, minHeight: 120, overflow: 'auto', whiteSpace: 'pre-wrap', margin: 0 }}>
              {log || (live ? t('pages.recruit.run.waiting') : '-')}
            </pre>
          </>
        )}

        <div style={{ margin: '18px 0 8px' }}><Text strong>{t('pages.recruit.run.recent')}</Text></div>
        <Table rowKey="id" size="small" dataSource={runs} pagination={false}
          rowClassName={(r: any) => (Number(r.id) === runId ? 'ant-table-row-selected' : '')}
          onRow={(r: any) => ({ onClick: () => setRunId(Number(r.id)), style: { cursor: 'pointer' } })}
          columns={[
            { title: t('pages.recruit.run.col.time'), width: 140, render: (_: any, r: any) => fmtMin(r.started_at) },
            { title: t('pages.recruit.run.col.trigger'), render: (_: any, r: any) => triggerText(r) },
            { title: t('pages.recruit.run.col.status'), width: 100, render: (_: any, r: any) => <Tag color={STATUS_COLOR[r.status]}>{t(`pages.recruit.run.status.${r.status}`)}</Tag> },
            { title: t('pages.recruit.run.s.parsed'), width: 80, align: 'right' as const, render: (_: any, r: any) => r.summary?.parse?.parsed ?? '-' },
            { title: t('pages.recruit.run.s.pairs'), width: 90, align: 'right' as const, render: (_: any, r: any) => r.summary?.match?.pairs ?? '-' },
            { title: t('pages.recruit.run.col.took'), width: 80, align: 'right' as const, render: (_: any, r: any) => secText(r.seconds) },
          ]} />
      </Drawer>
    </>
  );
};

export default PipelineButton;
