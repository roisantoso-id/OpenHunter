/**
 * 系统设置 · 招聘设置 · AI 提示词（「失败的提示词不能每次累计，我们需要有自我迭代的能力」）。
 * 三个场景（JD 拆要求 / 匹配打分 / 检索拆条件）各一套：
 *   版本：现行 / 草稿 / 已退役 / 未通过；新建版本 = 在现行版本上整体改写（有字数上限，逼着合并规则而不是往后追加），保存即排回归评测
 *   评测：现行 vs 候选在同一批回归用例上各跑一遍，候选更好就自动启用并通知；随时可手动启用 / 回退任一版本
 *   回归用例：失败（输出坏）、HR 改过的要求、人工改分、移除匹配；同一问题只一条、累加次数；gold = 有标准答案
 * 后端：recruitPrompts / recruitPromptFeedback / recruitPromptFeedbackSet / recruitPromptSave / recruitPromptEval / recruitPromptActivate
 * （recruit_admin，逻辑在 includes/recruit_prompts.php）。
 */
import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Card, Col, Input, Popconfirm, Row, Segmented, Select, Space, Statistic, Table, Tag, Tooltip, Typography, message } from 'antd';
import { EditOutlined, ExperimentOutlined, RollbackOutlined, CheckOutlined } from '@ant-design/icons';
import { useIntl } from '@umijs/max';
import {
  recruitPrompts, recruitPromptFeedback, recruitPromptFeedbackSet, recruitPromptSave, recruitPromptEval, recruitPromptActivate,
} from '@/services/api';
import { showErr, fmtMin, NO_HSCROLL } from '../common';

const { Text, Paragraph } = Typography;
const SCENES = ['jd_req', 'match', 'query', 'func', 'coclass', 'segment'] as const;
const VER_COLOR: Record<string, string> = { active: 'green', draft: 'blue', retired: 'default', rejected: 'red' };
const DECISION_COLOR: Record<string, string> = { promoted: 'green', kept: 'default', insufficient: 'orange', same: 'default' };
const FB_COLOR: Record<string, string> = { gold: 'gold', open: 'blue', ignored: 'default', sample: 'default' };
const PCT = new Set(['fail_rate', 'f1', 'level_acc', 'halluc_rate', 'empty_rate']);

const pre: React.CSSProperties = { whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontSize: 12, margin: 0, maxHeight: 360, overflow: 'auto' };
const json = (v: any) => (v === null || v === undefined ? '-' : typeof v === 'string' ? v : JSON.stringify(v, null, 2));

const PromptLabPanel: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [scene, setScene] = useState<typeof SCENES[number]>('jd_req');
  const [d, setD] = useState<any>(null);
  const [edit, setEdit] = useState<{ body: string; note: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const [fbStatus, setFbStatus] = useState<string>('');
  const [fb, setFb] = useState<{ rows: any[]; total: number; page: number }>({ rows: [], total: 0, page: 1 });

  const load = useCallback(async () => {
    const r = await recruitPrompts(scene);
    if (r?.success) setD(r.data); else showErr(r, t);
  }, [scene, t]);
  const loadFb = useCallback(async (page = 1) => {
    const r = await recruitPromptFeedback({ scene, status: fbStatus || undefined, page });
    if (r?.success) setFb({ rows: r.data, total: r.total, page }); else showErr(r, t);
  }, [scene, fbStatus, t]);
  useEffect(() => { setEdit(null); load(); }, [load]);
  useEffect(() => { loadFb(1); }, [loadFb]);
  // 有评测在跑就每 5 秒刷一次，跑完自动停
  const running = (d?.evals || []).some((e: any) => e.status === 'queued' || e.status === 'running');
  useEffect(() => {
    if (!running) return undefined;
    const h = setInterval(load, 5000);
    return () => clearInterval(h);
  }, [running, load]);

  const metricText = (k: string, v: number) => (PCT.has(k) ? `${(v * 100).toFixed(k === 'fail_rate' || k === 'halluc_rate' ? 1 : 0)}%` : Number(v).toFixed(2));
  const Metrics: React.FC<{ m?: Record<string, number> | null }> = ({ m }) => (!m ? <Text type="secondary">-</Text> : (
    <div>
      {Object.entries(m).filter(([k]) => k !== 'n' && k !== 'gold').map(([k, v]) => (
        <div key={k} style={{ fontSize: 12 }}><Text type="secondary">{t(`pages.recruit.prompt.metric.${k}`)}</Text> {metricText(k, v)}</div>
      ))}
      {m.n !== undefined && <Text type="secondary" style={{ fontSize: 11 }}>{t('pages.recruit.prompt.casesN', { n: m.n, gold: m.gold ?? 0 })}</Text>}
    </div>
  ));
  // 评测结论：better:f1,mae / worse:fail_rate / no_gain / few_cases / builtin_few_cases
  const reasonText = (r: string) => {
    if (!r) return '';
    const [k, list] = r.split(':');
    const names = (list || '').split(',').filter(Boolean).map((x) => t(`pages.recruit.prompt.metric.${x}`)).join('、');
    return t(`pages.recruit.prompt.reason.${k}`, { metrics: names });
  };

  const act = async (fn: () => Promise<any>, ok: string) => {
    const r = await fn();
    if (r?.success) { message.success(t(ok)); load(); } else showErr(r, t);
  };
  const save = async () => {
    if (!edit) return;
    setSaving(true);
    try {
      const r = await recruitPromptSave({ scene, body: edit.body, note: edit.note });
      if (r?.success) { message.success(t('pages.recruit.prompt.savedEval', { ver: r.data.ver })); setEdit(null); load(); }
      else showErr(r, t);
    } finally { setSaving(false); }
  };

  const fbCount = (st: string) => (d?.feedback || []).filter((x: any) => x.status === st).reduce((a: number, x: any) => a + x.n, 0);
  const hits = (d?.feedback || []).filter((x: any) => x.status !== 'sample').reduce((a: number, x: any) => a + x.hits, 0);
  const maxChars = d?.limits?.max_chars || 4000;

  const verCols = [
    { title: t('pages.recruit.prompt.col.ver'), width: 130, render: (_: any, r: any) => (
        <>
          <div><Text strong>{r.ver}</Text> <Tag color={VER_COLOR[r.status]}>{t(`pages.recruit.prompt.vstatus.${r.status}`)}</Tag></div>
          <Text type="secondary" style={{ fontSize: 12 }}>{t(`pages.recruit.prompt.source.${r.source}`)}</Text>
        </>) },
    { title: t('pages.recruit.prompt.col.metrics'), width: 200, render: (_: any, r: any) => <Metrics m={r.metrics} /> },
    { title: t('pages.recruit.prompt.col.note'), render: (_: any, r: any) => (
        <>
          <div>{r.note || <Text type="secondary">-</Text>}</div>
          <Text type="secondary" style={{ fontSize: 12 }}>
            {[r.created_by_name, fmtMin(r.created_at)].filter(Boolean).join(' · ')}
            {r.activated_at ? ` · ${t('pages.recruit.prompt.activatedAt', { at: fmtMin(r.activated_at) })}` : ''}
          </Text>
        </>) },
    { title: t('pages.recruit.col.actions'), width: 190, render: (_: any, r: any) => (r.status === 'active' ? <Tag color="green"><CheckOutlined /> {t('pages.recruit.prompt.inUse')}</Tag> : (
        <Space size={4} wrap>
          <Button size="small" icon={<ExperimentOutlined />} disabled={running} onClick={() => act(() => recruitPromptEval(r.id), 'pages.recruit.prompt.evalQueued')}>
            {t('pages.recruit.prompt.eval')}
          </Button>
          <Popconfirm title={t('pages.recruit.prompt.activateConfirm', { ver: r.ver })} onConfirm={() => act(() => recruitPromptActivate(r.id), 'pages.recruit.prompt.activated')}>
            <Button size="small" icon={<RollbackOutlined />}>{r.status === 'retired' ? t('pages.recruit.prompt.rollback') : t('pages.recruit.prompt.activate')}</Button>
          </Popconfirm>
        </Space>)) },
  ];

  const evalCols = [
    { title: t('pages.recruit.prompt.col.ver'), width: 110, render: (_: any, r: any) => <><Text strong>{r.ver}</Text><div><Text type="secondary" style={{ fontSize: 12 }}>#{r.id}</Text></div></> },
    { title: t('pages.recruit.prompt.col.result'), width: 260, render: (_: any, r: any) => (r.status !== 'done'
        ? <Tag color={r.status === 'failed' ? 'red' : 'processing'}>{t(`pages.recruit.prompt.estatus.${r.status}`)}{r.error ? ` · ${r.error}` : ''}</Tag>
        : <>
            <Tag color={DECISION_COLOR[r.decision]}>{t(`pages.recruit.prompt.decision.${r.decision}`)}</Tag>
            <div style={{ fontSize: 12 }}>{reasonText(r.reason)}</div>
            {r.cases !== null && <Text type="secondary" style={{ fontSize: 11 }}>{t('pages.recruit.prompt.onCases', { n: r.cases })}</Text>}
          </>) },
    { title: t('pages.recruit.prompt.col.activeM'), render: (_: any, r: any) => (r.active ? <><Text type="secondary" style={{ fontSize: 12 }}>{r.active.ver}</Text><Metrics m={r.active.metrics} /></> : '-') },
    { title: t('pages.recruit.prompt.col.candM'), render: (_: any, r: any) => (r.candidate ? <><Text type="secondary" style={{ fontSize: 12 }}>{r.candidate.ver}</Text><Metrics m={r.candidate.metrics} /></> : '-') },
    { title: t('pages.recruit.prompt.col.time'), width: 120, render: (_: any, r: any) => <Text style={{ fontSize: 12 }}>{fmtMin(r.finished_at || r.created_at)}</Text> },
  ];

  const fbCols = [
    { title: t('pages.recruit.prompt.col.kind'), width: 130, render: (_: any, r: any) => (
        <>
          <Tag>{t(`pages.recruit.prompt.kind.${r.kind}`)}</Tag>
          {r.hits > 1 && <div><Text type="warning" style={{ fontSize: 12 }}>{t('pages.recruit.prompt.hits', { n: r.hits })}</Text></div>}
        </>) },
    { title: t('pages.recruit.prompt.col.detail'), render: (_: any, r: any) => (
        <>
          <div style={{ fontSize: 12, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{r.input.slice(0, 160)}{r.input.length > 160 ? '…' : ''}</div>
          {!!r.detail && <Text type="secondary" style={{ fontSize: 12 }}>{r.detail}</Text>}
        </>) },
    { title: t('pages.recruit.prompt.col.ver'), width: 80, render: (_: any, r: any) => <Text type="secondary">{r.prompt_ver}</Text> },
    { title: t('pages.recruit.prompt.col.status'), width: 150, render: (_: any, r: any) => (r.status === 'sample' ? <Tag>{t('pages.recruit.prompt.fstatus.sample')}</Tag> : (
        <Select size="small" style={{ width: 130 }} value={r.status}
          onChange={async (v) => { const x = await recruitPromptFeedbackSet(r.id, v); if (x?.success) { loadFb(fb.page); load(); } else showErr(x, t); }}
          options={['gold', 'open', 'ignored'].map((s) => ({ value: s, label: t(`pages.recruit.prompt.fstatus.${s}`), disabled: s === 'gold' && !r.human }))} />)) },
    { title: t('pages.recruit.prompt.col.time'), width: 120, render: (_: any, r: any) => <Text style={{ fontSize: 12 }}>{fmtMin(r.last_at)}</Text> },
  ];

  return (
    <div>
      <Space style={{ marginBottom: 12 }} wrap>
        <Segmented value={scene} onChange={(v) => setScene(v as any)} options={SCENES.map((s) => ({ value: s, label: t(`pages.recruit.prompt.scene.${s}`) }))} />
      </Space>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.prompt.intro')} />

      <Row gutter={16} style={{ marginBottom: 12 }}>
        <Col span={6}><Card size="small"><Statistic title={t('pages.recruit.prompt.stat.active')} value={d?.active?.ver || '-'} /></Card></Col>
        <Col span={6}><Card size="small">
          <Statistic title={t('pages.recruit.prompt.stat.fail7d')} value={d?.usage7d?.calls ? (d.usage7d.failed / d.usage7d.calls * 100).toFixed(1) : '-'}
            suffix={d?.usage7d?.calls ? '%' : ''} valueStyle={{ color: d?.usage7d?.calls && d.usage7d.failed / d.usage7d.calls > 0.1 ? '#cf1322' : undefined }} />
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.prompt.stat.calls', { n: d?.usage7d?.calls ?? 0, f: d?.usage7d?.failed ?? 0 })}</Text>
        </Card></Col>
        <Col span={6}><Card size="small">
          <Statistic title={t('pages.recruit.prompt.stat.cases')} value={fbCount('gold')} suffix={<Text type="secondary" style={{ fontSize: 13 }}>/ {fbCount('gold') + fbCount('open')}</Text>} />
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.prompt.stat.casesHint', { min: d?.limits?.min_cases ?? 8 })}</Text>
        </Card></Col>
        <Col span={6}><Card size="small">
          <Statistic title={t('pages.recruit.prompt.stat.hits')} value={hits} />
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.prompt.stat.hitsHint')}</Text>
        </Card></Col>
      </Row>

      <Card size="small" style={{ marginBottom: 12 }} title={t('pages.recruit.prompt.versions')}
        extra={!edit && <Button size="small" type="primary" icon={<EditOutlined />} onClick={() => setEdit({ body: d?.active?.body || '', note: '' })}>{t('pages.recruit.prompt.newVer')}</Button>}>
        {edit && (
          <div style={{ marginBottom: 12 }}>
            <Paragraph type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.prompt.editHint', { max: maxChars })}</Paragraph>
            <Input.TextArea rows={14} value={edit.body} showCount maxLength={maxChars} style={{ fontFamily: 'monospace', fontSize: 12 }}
              onChange={(e) => setEdit({ ...edit, body: e.target.value })} />
            <Space style={{ marginTop: 16 }} wrap>
              <Input style={{ width: 360 }} maxLength={191} placeholder={t('pages.recruit.prompt.notePh')} value={edit.note}
                onChange={(e) => setEdit({ ...edit, note: e.target.value })} />
              <Button type="primary" loading={saving} onClick={save}>{t('pages.recruit.prompt.saveEval')}</Button>
              <Button onClick={() => setEdit(null)}>{t('pages.recruit.cancel')}</Button>
            </Space>
          </div>
        )}
        <Table rowKey="id" size="small" pagination={false} dataSource={d?.versions || []} columns={verCols as any} {...NO_HSCROLL}
          expandable={{ expandedRowRender: (r: any) => <pre style={pre}>{r.body}</pre> }} />
      </Card>

      <Card size="small" style={{ marginBottom: 12 }} title={t('pages.recruit.prompt.evals')}>
        <Table rowKey="id" size="small" pagination={false} dataSource={d?.evals || []} columns={evalCols as any} {...NO_HSCROLL} />
      </Card>

      <Card size="small" title={t('pages.recruit.prompt.cases')}
        extra={<Segmented size="small" value={fbStatus} onChange={(v) => setFbStatus(v as string)}
          options={['', 'gold', 'open', 'ignored', 'sample'].map((s) => ({ value: s, label: s ? t(`pages.recruit.prompt.fstatus.${s}`) : t('pages.recruit.prompt.fstatus.all') }))} />}>
        <Table rowKey="id" size="small" dataSource={fb.rows} columns={fbCols as any} {...NO_HSCROLL}
          pagination={{ current: fb.page, pageSize: 20, total: fb.total, onChange: (p) => loadFb(p), showSizeChanger: false }}
          expandable={{ expandedRowRender: (r: any) => (
            <Row gutter={16}>
              <Col span={8}><Text strong>{t('pages.recruit.prompt.input')}</Text><pre style={pre}>{r.input}</pre></Col>
              <Col span={8}><Text strong>{t('pages.recruit.prompt.aiOut')}</Text><pre style={pre}>{json(r.ai)}</pre></Col>
              <Col span={8}><Text strong>{t('pages.recruit.prompt.answer')}</Text><pre style={pre}>{json(r.human)}</pre></Col>
            </Row>) }} />
      </Card>
    </div>
  );
};

export default PromptLabPanel;
