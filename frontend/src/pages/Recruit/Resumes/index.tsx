/**
 * 简历解析（「谁上传的、从哪个邮箱读到的、简历解析的情况全都需要列出来」）。
 * 每一份收到的文件一行：邮件附件、邮件正文、页面上传、批量导入。
 * 顶部统计卡可点——卡片数字与点开后的列表是同一组条件（后端 recruitResumeViewSql，§6.7.1）。
 * URL ?view=queue|failed 从人才库统计卡跳来、?source_user_id= 从人力工作台跳来；?id=123 直接打开那份简历（候选人抽屉「简历」页签跳来）。
 */
import React, { useCallback, useEffect, useState } from 'react';
import { Table, Input, Select, Space, Button, Tag, Typography, DatePicker, Tooltip, Dropdown, Modal, message, Tabs, Alert } from 'antd';
import { SearchOutlined, ReloadOutlined, StopOutlined } from '@ant-design/icons';
import { useIntl, useLocation, history } from '@umijs/max';
import dayjs from 'dayjs';
import { recruitMeta, recruitResumesPage, recruitBlockSender } from '@/services/api';
import { maskEmail } from '@/utils/mask';
import ResumeDrawer from '../components/ResumeDrawer';
import CandidateDrawer from '../components/CandidateDrawer';
import StatCard from '../components/StatCard';
import AssetsPanel from './AssetsPanel';
import PipelineButton from '../components/PipelineButton';
import { showErr, fmtMin, candCode } from '../common';

const { Text } = Typography;
const VIEWS = ['all', 'today', 'parsed', 'queue', 'failed', 'review', 'not_cv'] as const;
const VIEW_ART: Record<string, string> = { all: 'files', today: 'inbox', parsed: 'parsed', queue: 'queue', failed: 'failed', review: 'review', not_cv: 'notcv' };
const fmtSize = (n: number) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : n > 0 ? `${Math.max(1, Math.round(n / 1024))} KB` : '');

const Resumes: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: Record<string, any>) => intl.formatMessage({ id }, v), [intl]);
  const location = useLocation();
  const q = new URLSearchParams(location.search);
  const [meta, setMeta] = useState<any>(null);
  const [rows, setRows] = useState<any[]>([]);
  const [cards, setCards] = useState<Record<string, number>>({});
  const [mailboxes, setMailboxes] = useState<any[]>([]);
  const [usage, setUsage] = useState<any>(null);
  /** 流水线状态：总开关 / 预算 / 最近一轮（后端 recruitResumesPage.pipeline）——队列积压时页面要说清为什么没人解析 */
  const [pipeline, setPipeline] = useState<{ enabled: boolean; budget_ok: boolean; queue: number; last_run: any } | null>(null);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(20);
  const [f, setF] = useState<Record<string, any>>(() => {
    const init: Record<string, any> = {};
    ['view', 'source_user_id'].forEach((k) => { const v = q.get(k); if (v) init[k] = v; });   // 人才库/工作台跳来
    return init;
  });
  const [openId, setOpenId] = useState<number | null>(q.get('id') ? Number(q.get('id')) : null);
  const [candId, setCandId] = useState<number | null>(null);
  // 「简历文件」/「资产信息」（黑名单、收件邮箱）；?tab=assets 直达
  const [tab, setTab] = useState<string>(q.get('tab') === 'assets' ? 'assets' : 'files');
  const [blockKey, setBlockKey] = useState(0);

  useEffect(() => { recruitMeta().then((r: any) => (r?.success ? setMeta(r.data) : showErr(r, t))); }, [t]);
  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, any> = { page, pageSize };
      Object.entries(f).forEach(([k, v]) => { if (v !== undefined && v !== '' && v !== null && v !== 'all') params[k] = v; });
      const res = await recruitResumesPage(params);
      if (res?.success) { setRows(res.data || []); setTotal(res.total || 0); setCards(res.cards || {}); setMailboxes(res.mailboxes || []); setUsage(res.ai_usage || null); setPipeline(res.pipeline || null); }
      else showErr(res, t);
    } finally { setLoading(false); }
  }, [f, page, pageSize, t]);
  useEffect(() => { load(); }, [load]);

  const set = (patch: Record<string, any>) => { setPage(1); setF((p) => ({ ...p, ...patch })); };
  const reset = () => { setPage(1); setF({}); history.replace('/recruit/resumes'); };
  const view = f.view || 'all';
  const blang = intl.locale === 'id-ID' ? 'id' : intl.locale === 'en-US' ? 'en' : 'zh';

  /* 拉黑发件人（「解析失败的有时是猎头公司的广告，直接拉黑，以后都不解析」）：
     这个邮箱 / 整个域名（公共邮箱域名只能拉黑单个邮箱）。可在 设置 · 招聘邮箱 解除 */
  const senderOf = (from: string) => (from.match(/<([^<>\s]+@[^<>\s]+)>/)?.[1] || from.match(/[^\s<>"'(),;:]+@[^\s<>"'(),;:]+/)?.[0] || '').toLowerCase();
  const block = (r: any, scope: 'email' | 'domain') => {
    const email = senderOf(r.from_addr);
    const target = scope === 'domain' ? `@${email.split('@')[1]}` : email;
    let note = '';
    Modal.confirm({
      title: t('pages.recruit.block.confirmTitle', { target }),
      content: (
        <>
          <div style={{ marginBottom: 8 }}>{t('pages.recruit.block.confirmBody')}</div>
          <Input maxLength={191} placeholder={t('pages.recruit.block.notePh')} onChange={(e) => { note = e.target.value; }} />
        </>),
      okButtonProps: { danger: true },
      okText: t('pages.recruit.block.ok'),
      onOk: async () => {
        const res = await recruitBlockSender(Number(r.id), scope, note);
        if (res?.success) { message.success(t('pages.recruit.block.done', { target: res.data.pattern, n: res.data.resumes })); load(); setBlockKey((k) => k + 1); }
        else showErr(res, t);
      },
    });
  };

  const columns = [
    { title: t('pages.recruit.col.file'), width: 230, render: (_: any, r: any) => (
        <>
          <div style={{ wordBreak: 'break-all' }}>{r.file_name || t('pages.recruit.bodyOnly')}</div>
          <Space size={4}>
            {!!r.file_ext && <Tag style={{ fontSize: 11 }}>{String(r.file_ext).toUpperCase()}</Tag>}
            <Text type="secondary" style={{ fontSize: 12 }}>{fmtSize(Number(r.file_size))}</Text>
          </Space>
        </>) },
    { title: t('pages.recruit.resume.source'), width: 250, render: (_: any, r: any) => (
        <>
          <Space size={4} wrap>
            <Tag color={r.origin === 'email' ? 'blue' : r.origin === 'upload' ? 'green' : 'default'}>{t(`pages.recruit.origin.${r.origin}`)}</Tag>
            {r.origin === 'email' && !!r.plus_code && <Text style={{ fontSize: 12 }}>+{r.plus_code}</Text>}
            {r.origin === 'upload' && <Text style={{ fontSize: 12 }}>{r.uploaded_by_name}</Text>}
          </Space>
          {r.origin === 'email' && (
            <>
              {!!r.mailbox && <div style={{ fontSize: 12, color: '#888' }}>{r.mailbox}</div>}
              {!!r.from_addr && <div style={{ fontSize: 12 }} title={r.from_addr}>{t('pages.recruit.resume.from', { from: maskEmail(r.from_addr) })}</div>}
              {!!r.subject && <div title={r.subject} style={{ fontSize: 12, color: '#888', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 230 }}>{r.subject}</div>}
              {/* 只有解析失败的才给拉黑（「解析失败的才拉黑」）——解析成功 / 排队中的多半是真候选人 */}
              {!!senderOf(r.from_addr || '') && ['failed', 'unsupported'].includes(r.parse_status) && (() => {
                const domain = senderOf(r.from_addr).split('@')[1];
                return (
                  <Dropdown trigger={['click']} menu={{
                    items: [
                      { key: 'email', label: t('pages.recruit.block.email', { email: maskEmail(senderOf(r.from_addr)) }) },
                      // 公共邮箱域名只能拉黑单个邮箱：名单由后端 recruitMeta 给（RECRUIT_PUBLIC_MAIL_DOMAINS），前端不抄一份
                      { key: 'domain', label: t('pages.recruit.block.domain', { domain }), disabled: (meta?.public_mail_domains || []).includes(domain) },
                    ],
                    onClick: ({ key, domEvent }) => { domEvent.stopPropagation(); block(r, key as 'email' | 'domain'); },
                  }}>
                    <a style={{ fontSize: 12, color: '#cf1322' }} onClick={(e) => e.stopPropagation()}><StopOutlined /> {t('pages.recruit.block.btn')}</a>
                  </Dropdown>
                );
              })()}
            </>
          )}
          {!!r.target_job_title && <div><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.resume.targetJob', { job: r.target_job_title })}</Text></div>}
        </>) },
    { title: t('pages.recruit.col.receivedBy'), width: 150, render: (_: any, r: any) => (
        <><div>{fmtMin(r.received_at)}</div>
          {r.source_user_id > 0 ? <Text type="secondary" style={{ fontSize: 12 }}>{r.source_user_name}</Text> : <Tag color="orange">{t('pages.recruit.unclaimed')}</Tag>}</>) },
    { title: t('pages.recruit.col.parse'), width: 170, render: (_: any, r: any) => (
        <>
          <Space size={4} wrap>
            <Tag color={r.parse_status === 'parsed' ? 'green' : r.parse_status === 'failed' ? 'red' : r.parse_status === 'processing' ? 'processing' : r.parse_status === 'blocked' ? 'volcano' : 'default'}>
              {t(`pages.recruit.parse.${r.parse_status}`)}</Tag>
            {r.outdated && <Tooltip title={t('pages.recruit.resume.outdatedHint')}><Tag>{t('pages.recruit.resume.outdated')}</Tag></Tooltip>}
          </Space>
          <div style={{ fontSize: 12, color: '#888' }}>
            {[r.parse_mode ? t(`pages.recruit.resume.mode.${r.parse_mode}`) : '', r.attempts > 0 ? t('pages.recruit.attempts', { n: r.attempts }) : ''].filter(Boolean).join(' · ')}
          </div>
          {!!r.parse_error_kind && r.parse_status !== 'parsed' && <Text type="danger" style={{ fontSize: 12 }}>{t(`pages.recruit.errKind.${r.parse_error_kind}`)}</Text>}
          {r.parse_status === 'retry' && !!r.next_retry_at && <div><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.files.nextRetry', { at: fmtMin(r.next_retry_at) })}</Text></div>}
          {!!r.block && (
            <div style={{ fontSize: 12 }}>
              <Text type="secondary">{r.block.source === 'auto' ? t('pages.recruit.block.byAuto') : t('pages.recruit.block.byUser', { name: r.block.created_by_name })}</Text>
              {' · '}<a onClick={(e) => { e.stopPropagation(); setTab('assets'); }}>{r.block.reason?.[blang] || (r.block.reason_key ? t(`pages.recruit.block.reasonKey.${r.block.reason_key}`) : r.block.pattern)}</a>
            </div>
          )}
        </>) },
    { title: t('pages.recruit.resume.docType'), width: 110, render: (_: any, r: any) => (r.doc_type
        ? <Tag color={r.doc_type === 'cv' ? 'geekblue' : 'default'}>{t(`pages.recruit.doc.${r.doc_type}`)}</Tag> : '-') },
    { title: t('pages.recruit.col.candidate'), width: 180, render: (_: any, r: any) => (Number(r.candidate_id) > 0
        ? <a onClick={(e) => { e.stopPropagation(); setCandId(Number(r.candidate_id)); }}>{candCode(r.candidate_id)} {r.candidate_name}</a>
        : <Text type="secondary">-</Text>) },
    { title: t('pages.recruit.resume.reviewCol'), width: 120, render: (_: any, r: any) => (
        <Space size={2} wrap>
          {Number(r.needs_review) === 1 && <Tag color="orange">{t('pages.recruit.resume.review')}</Tag>}
          {String(r.review_flags).includes(',phone_conflict,') && <Tag color="orange">{t('pages.recruit.flag.phone_conflict')}</Tag>}
        </Space>) },
  ];

  return (
    <div>
      <style>{`.recruit-row:hover > td, .recruit-row > td.ant-table-cell-row-hover { background:#eef4fb !important; cursor:pointer }`}</style>
      <Tabs activeKey={tab} onChange={setTab} items={[
        { key: 'files', label: t('pages.recruit.assets.tabFiles') },
        { key: 'assets', label: t('pages.recruit.assets.tab') },
      ]} />
      {tab === 'assets' ? <AssetsPanel t={t} meta={meta} mailboxes={mailboxes} reloadKey={blockKey} /> : (<>
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        {VIEWS.map((v) => (
          <StatCard key={v} art={VIEW_ART[v]} minWidth={130} title={t(`pages.recruit.resume.card.${v}`)} value={cards[v] ?? '-'}
            active={view === v} danger={(v === 'failed' || v === 'review') && cards[v] > 0} onClick={() => set({ view: v })} />
        ))}
      </div>

      {/* 队列里有东西却没人解析：把原因说出来（「一堆一直在待解析什么回事」） */}
      {pipeline && pipeline.queue > 0 && (() => {
        const lastAt = pipeline.last_run?.finished_at || pipeline.last_run?.started_at;
        const stale = !lastAt || dayjs().diff(dayjs(lastAt), 'minute') > 20;
        const why = !pipeline.enabled ? 'off' : !pipeline.budget_ok ? 'budget' : stale ? 'stale' : '';
        if (!why) return null;
        return (
          <Alert type={why === 'off' ? 'error' : 'warning'} showIcon style={{ marginBottom: 10 }}
            message={t(`pages.recruit.pipe.${why}`, { n: pipeline.queue, at: lastAt ? fmtMin(lastAt) : '-' })}
            description={t(`pages.recruit.pipe.${why}Hint`)}
            action={<PipelineButton type="primary" size="small" label={t('pages.recruit.pipe.parseNow')} onDone={load} />} />
        );
      })()}
      {usage && (
        <div style={{ marginBottom: 10, fontSize: 12, color: '#666' }}>
          <Tooltip title={t('pages.recruit.cost.hint')}>
            {t('pages.recruit.cost.month', { calls: usage.calls, tokens: Number(usage.tokens).toLocaleString(), usd: Number(usage.usd).toFixed(4) })}
            {usage.per_resume_tokens !== null && ` · ${t('pages.recruit.cost.perResume', { tokens: Number(usage.per_resume_tokens).toLocaleString(), usd: Number(usage.per_resume_usd).toFixed(5) })}`}
            {` · ${t('pages.recruit.cost.today', { used: Number(usage.today_tokens).toLocaleString(), budget: Number(usage.budget).toLocaleString() })}`}
          </Tooltip>
        </div>
      )}
      <Space wrap style={{ marginBottom: 12 }}>
        <Input allowClear prefix={<SearchOutlined />} style={{ width: 240 }} placeholder={t('pages.recruit.resume.searchPh')}
          defaultValue={f.keyword} onPressEnter={(e) => set({ keyword: (e.target as HTMLInputElement).value })}
          onChange={(e) => { if (!e.target.value) set({ keyword: undefined }); }} />
        <Select allowClear style={{ width: 130 }} placeholder={t('pages.recruit.resume.source')} value={f.origin} onChange={(v) => set({ origin: v })}
          options={['email', 'upload', 'import', 'apply'].map((o) => ({ value: o, label: t(`pages.recruit.origin.${o}`) }))} />
        <Select allowClear style={{ width: 210 }} placeholder={t('pages.recruit.resume.mailbox')} value={f.mailbox_id} onChange={(v) => set({ mailbox_id: v })}
          options={mailboxes.map((m: any) => ({ value: Number(m.id), label: m.username }))} />
        <Select allowClear style={{ width: 150 }} placeholder={t('pages.recruit.col.owner')} value={f.source_user_id} onChange={(v) => set({ source_user_id: v })}
          options={[{ value: 'unclaimed', label: t('pages.recruit.unclaimed') },
            ...(meta?.owners || []).map((o: any) => ({ value: String(o.id), label: o.name }))]} />
        <Select allowClear style={{ width: 130 }} placeholder={t('pages.recruit.resume.fileType')} value={f.file_ext} onChange={(v) => set({ file_ext: v })}
          options={[{ value: 'pdf', label: 'PDF' }, { value: 'docx', label: 'DOCX' }, { value: 'doc', label: 'DOC' },
            { value: 'image', label: t('pages.recruit.resume.image') }, { value: 'body', label: t('pages.recruit.bodyOnly') }]} />
        <DatePicker.RangePicker value={f.from && f.to ? [dayjs(f.from), dayjs(f.to)] : null}
          placeholder={[t('pages.recruit.receivedFrom'), t('pages.recruit.receivedTo')]}
          onChange={(v) => set({ from: v?.[0]?.format('YYYY-MM-DD'), to: v?.[1]?.format('YYYY-MM-DD') })} />
        <Button icon={<ReloadOutlined />} onClick={reset}>{t('pages.recruit.reset')}</Button>
        <PipelineButton label={t('pages.recruit.pipe.parseNow')} onDone={load} />
      </Space>

      <Table rowKey="id" size="middle" loading={loading} dataSource={rows} columns={columns as any} scroll={{ x: 1210 }}
        rowClassName={() => 'recruit-row'}
        onRow={(r: any) => ({ onClick: (e) => { if ((e.target as HTMLElement).closest('a,button')) return; setOpenId(Number(r.id)); } })}
        pagination={{ current: page, pageSize, total, showSizeChanger: true, showTotal: (n) => t('pages.recruit.total', { n }),
          onChange: (p, s) => { setPage(p); setPageSize(s); } }} />
      </>)}

      <ResumeDrawer open={openId !== null} resumeId={openId} onClose={() => setOpenId(null)} onChanged={load}
        onOpenCandidate={(id) => setCandId(id)} />
      <CandidateDrawer open={candId !== null} candidateId={candId} meta={meta} onClose={() => setCandId(null)} onChanged={load} />
    </div>
  );
};

export default Resumes;
