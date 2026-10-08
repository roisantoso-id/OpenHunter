/**
 * 人力工作台：打开就知道今天该干什么。
 *   ① 我的固定收简历地址（plus 地址，管理员分配、本人不能改）——简历和候选人邮件一律发/抄送到这里，不要发到个人邮箱
 *   ①b 我的数据图表：今天 / 7 天 / 30 天 / 90 天切换（WorkbenchStats）+ 此刻要处理的 4 张卡
 *   ①c 在招职位 · 招聘文案：点开就能复制去发平台，末尾自动附上本人的投递地址（JobPostingBox，与项目抽屉共用）
 *   ② 今天/近 7 天进来的简历：几份、从哪个邮箱（plus 代码）或谁上传、几点到、解析成谁
 *   ③ 需要保温的人（规则见 includes/recruit_warm.php）
 *   ④ 我的客户与项目：客户、在招职位数、我名下人选在各阶段的数量
 * 管理员可切换查看某位招聘专员。
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, Row, Col, Typography, Tag, Alert, Space, Select, Table, Empty, Spin, Tooltip, Segmented, Button } from 'antd';
import { MailOutlined, SettingOutlined } from '@ant-design/icons';
import { useIntl, useModel, useAccess, history } from '@umijs/max';
import dayjs from 'dayjs';
import { recruitMeta, recruitWorkbench } from '@/services/api';
import { maskPhone, maskEmail } from '@/utils/mask';
import CandidateDrawer from '../components/CandidateDrawer';
import WorkbenchStats from '../components/WorkbenchStats';
import StatCard from '../components/StatCard';
import { showErr, fmtDay, fmtMin, agoText, STAGE_COLOR, STATUS_COLOR, candCode } from '../common';
import DoodleEmpty from '../components/DoodleEmpty';
import JobPostingBox, { POST_PLATFORMS } from '../components/JobPostingBox';

const { Text, Title } = Typography;
const WARM_COLOR: Record<string, string> = { due: 'red', active_stage: 'volcano', never: 'gold', strong_idle: 'blue' };
const PIPE = ['shortlisted', 'submitted', 'interviewing', 'offered', 'hired'];
const ROW_CSS = '.recruit-row:hover > td, .recruit-row > td.ant-table-cell-row-hover { background:#eef4fb !important; cursor:pointer }';

const Workbench: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: Record<string, any>) => intl.formatMessage({ id }, v), [intl]);
  const { initialState } = useModel('@@initialState');
  const me = Number((initialState as any)?.currentUser?.id || 0);
  const [meta, setMeta] = useState<any>(null);
  const [userId, setUserId] = useState<number>(0);
  const [d, setD] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [openId, setOpenId] = useState<number | null>(null);
  const [range, setRange] = useState<'today' | 'week'>('today');
  const access = useAccess() as any;
  // 招聘邮箱与代码、权限在「系统设置 · 招聘设置」
  const openSettings = () => history.push('/settings');

  useEffect(() => { recruitMeta().then((r: any) => (r?.success ? setMeta(r.data) : showErr(r, t))); }, [t]);
  const load = useCallback(async () => {
    setLoading(true);
    try {
      const r = await recruitWorkbench(userId || undefined);
      if (r?.success) setD(r.data); else showErr(r, t);
    } finally { setLoading(false); }
  }, [userId, t]);
  useEffect(() => { load(); }, [load]);

  const incoming = useMemo(() => {
    const today = dayjs().format('YYYY-MM-DD');
    return (d?.incoming || []).filter((r: any) => range === 'week' || String(r.received_at).slice(0, 10) === today);
  }, [d, range]);

  if (!d) return <Spin style={{ display: 'block', margin: '80px auto' }} />;
  const owner = d.user_id || me;
  const activeAddr = (d.addresses || []).filter((a: any) => a.enabled);
  const today = dayjs().format('YYYY-MM-DD');
  // 此刻要处理的（快照，点了去对应列表）；随时间段变化的数字在下面的图表里
  const cards = [
    { key: 'due', to: `/recruit/talent?owner_id=${owner}&due=1` },
    { key: 'warm', to: '#warm' },
    { key: 'total', to: `/recruit/talent?owner_id=${owner}` },
    { key: 'in_process', to: `/recruit/talent?owner_id=${owner}&status=in_process` },
  ];
  const go = (to: string) => (to.startsWith('#')
    ? document.getElementById(`recruit-${to.slice(1)}`)?.scrollIntoView({ behavior: 'smooth' }) : history.push(to));

  return (
    <div>
      <style>{ROW_CSS}</style>
      {/* 模块标识横幅（图标风）：徽章 + 模块名 + 一句话流程，右侧招聘顾问与 AI 小机器人。
          米色底 + multiply 把插图的白底融掉；窄屏隐藏右侧插图 */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, background: '#FBF8F2',
        border: '1px solid #EFE6D3', borderRadius: 12, padding: '8px 16px', marginBottom: 12, overflow: 'hidden', minHeight: 96 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 }}>
          <img src="/recruit/art/mark.svg" alt="" width={60} height={60} style={{ mixBlendMode: 'multiply', flexShrink: 0 }} />
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 20, fontWeight: 700, color: '#1B1B1B', lineHeight: 1.3 }}>{t('menu.recruit')}</div>
            <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.wb.brandLine')}</Text>
          </div>
        </div>
        <img className="recruit-hero" src="/recruit/art/hero.svg" alt="" style={{ height: 110, mixBlendMode: 'multiply', flexShrink: 0, margin: '-8px 0' }} />
        <style>{'@media (max-width: 900px) { .recruit-hero { display: none } }'}</style>
      </div>
      <Space style={{ marginBottom: 12 }} align="center">
        <Title level={5} style={{ margin: 0 }}>{t('pages.recruit.wb.hello', { name: d.user_name })}</Title>
        <Text type="secondary">{today}</Text>
        {meta?.can_admin && (
          <Select style={{ width: 200 }} value={userId || undefined} allowClear placeholder={t('pages.recruit.wb.viewAs')}
            onChange={(v) => setUserId(v || 0)}
            options={(meta?.sources || []).filter((s: any) => Number(s.user_id) > 0)
              .map((s: any) => ({ value: Number(s.user_id), label: `${s.user_name} (+${s.code})` }))} />
        )}
        {access.canAccessSettings && <Button icon={<SettingOutlined />} onClick={openSettings}>{t('pages.recruit.cfg.menu')}</Button>}
      </Space>

      <Row gutter={14} style={{ marginBottom: 14 }}>
        {/* ① 固定收简历地址 */}
        <Col xs={24} xl={9}>
          <Card size="small" style={{ height: '100%' }} title={<Space><MailOutlined />{t('pages.recruit.wb.addrTitle')}</Space>}>
            {activeAddr.length === 0 ? (
              <Alert type="warning" showIcon message={(d.codes || []).length === 0 ? t('pages.recruit.wb.noCode') : t('pages.recruit.wb.mailboxOff')}
                action={access.canAccessSettings && <Button size="small" onClick={openSettings}>{t('pages.recruit.cfg.menu')}</Button>} />
            ) : (
              <>
                {activeAddr.map((a: any) => (
                  <div key={a.address} style={{ fontSize: 18, fontWeight: 600, marginBottom: 4 }}>
                    <Text copyable={{ text: a.address }}>{a.address}</Text>
                  </div>
                ))}
                <Alert type="info" showIcon style={{ marginTop: 6 }} message={t('pages.recruit.wb.addrRule')} description={t('pages.recruit.wb.addrWhy')} />
              </>
            )}
          </Card>
        </Col>
        {/* 此刻要处理的 4 张卡（2×2） */}
        <Col xs={24} xl={15}>
          <Row gutter={[10, 10]}>
            {cards.map((c) => (
              <Col key={c.key} span={12}>
                <StatCard art={({ due: 'due', warm: 'warm', total: 'people', in_process: 'rocket' } as Record<string, string>)[c.key]}
                  title={t(`pages.recruit.wb.card.${c.key}`)} value={d.cards[c.key] ?? '-'} onClick={() => go(c.to)}
                  danger={(c.key === 'warm' || c.key === 'due') && d.cards[c.key] > 0} />
              </Col>
            ))}
          </Row>
        </Col>
      </Row>

      {/* ①b 我的数据：今天 / 7 天 / 30 天 / 90 天 */}
      <WorkbenchStats userId={userId || undefined} />

      {/* ①c 在招职位 · 招聘文案（「工作台也能直接复制」） */}
      <Card size="small" style={{ marginBottom: 14 }} loading={loading}
        title={<Space>{t('pages.recruit.wb.postings')}<Text type="secondary" style={{ fontSize: 12, fontWeight: 400 }}>{t('pages.recruit.wb.postingsHint')}</Text></Space>}>
        <Table rowKey="id" size="small" dataSource={d.open_jobs || []} pagination={{ pageSize: 10, hideOnSinglePage: true }}
          scroll={{ x: undefined }} tableLayout="fixed" rowClassName={() => 'recruit-row'}
          locale={{ emptyText: <DoodleEmpty size="small" kind="search" description={t('pages.recruit.wb.noOpenJobs')} /> }}
          expandable={{ expandRowByClick: true, expandedRowRender: (j: any) => (
            <JobPostingBox t={t} job={j} addresses={activeAddr} defaultApply={activeAddr[0]?.address}
              onSaved={(posting) => setD((x: any) => ({ ...x, open_jobs: x.open_jobs.map((y: any) => (y.id === j.id ? { ...y, posting } : y)) }))}
              onPosted={(posted) => setD((x: any) => ({ ...x, open_jobs: x.open_jobs.map((y: any) => (y.id === j.id ? { ...y, posted } : y)) }))} />) }}
          columns={[
            { title: t('pages.recruit.col.job'), render: (_: any, j: any) => (
                <><Text strong>{j.title}</Text>
                  <div><Text type="secondary" style={{ fontSize: 12 }}>
                    {[j.customer_group_name || (j.kind === 'internal' ? t('pages.recruit.kind.internal') : ''), j.project_name, j.location].filter(Boolean).join(' · ')}
                  </Text></div></>) },
            { title: t('pages.recruit.posting.postedTo'), width: 320, render: (_: any, j: any) => {
                const on = POST_PLATFORMS.filter((pf) => j.posted?.[pf]);
                return on.length
                  ? <Space size={4} wrap>{on.map((pf) => <Tag key={pf} color="blue">{t(`pages.recruit.platform.${pf}`)}</Tag>)}</Space>
                  : <Text type="warning" style={{ fontSize: 12 }}>{t('pages.recruit.posting.notPosted')}</Text>;
              } },
            { title: t('pages.recruit.posting.title'), width: 220, render: (_: any, j: any) => (
                <Space size={4} wrap>
                  {(['id', 'en', 'zh'] as const).map((l) => (
                    <Tag key={l} color={j.posting?.[l] ? 'green' : 'default'}>{t(`pages.recruit.posting.lang.${l}`)}{j.posting?.[l] ? ' ✓' : ''}</Tag>))}
                </Space>) },
          ]} />
      </Card>

      {/* ② 今天 / 近 7 天进来的简历 */}
      <Card id="recruit-incoming" size="small" style={{ marginBottom: 14 }} loading={loading}
        title={t('pages.recruit.wb.incoming')}
        extra={<Segmented size="small" value={range} onChange={(v) => setRange(v as any)}
          options={[{ value: 'today', label: t('pages.recruit.wb.rangeToday', { n: d.cards.today_resumes }) },
                    { value: 'week', label: t('pages.recruit.wb.rangeWeek', { n: (d.incoming || []).length }) }]} />}>
        <Table rowKey="id" size="small" dataSource={incoming} pagination={{ pageSize: 10, hideOnSinglePage: true }}
          locale={{ emptyText: <DoodleEmpty size="small" kind="inbox" description={t('pages.recruit.wb.incomingEmpty')} /> }}
          rowClassName={() => 'recruit-row'}
          onRow={(r: any) => ({ onClick: () => (Number(r.candidate_id) > 0 ? setOpenId(Number(r.candidate_id)) : history.push(`/recruit/resumes?id=${r.id}`)) })}
          columns={[
            { title: t('pages.recruit.wb.receivedAt'), width: 140, render: (_: any, r: any) => fmtMin(r.received_at) },
            { title: t('pages.recruit.resume.source'), width: 320, render: (_: any, r: any) => (
                <Space size={4} wrap>
                  <Tag color={r.origin === 'email' ? 'blue' : r.origin === 'upload' ? 'green' : 'default'}>{t(`pages.recruit.origin.${r.origin}`)}</Tag>
                  {r.origin === 'email' && <Text style={{ fontSize: 12 }}>{r.mailbox ? r.mailbox.replace('@', `+${r.plus_code}@`) : `+${r.plus_code}`}</Text>}
                  {r.origin === 'email' && !!r.from_addr && <Text type="secondary" style={{ fontSize: 12 }} title={r.from_addr}>{t('pages.recruit.resume.from', { from: maskEmail(r.from_addr) })}</Text>}
                  {r.origin === 'upload' && <Text style={{ fontSize: 12 }}>{t('pages.recruit.resume.uploadedBy', { name: r.uploaded_by_name || '-' })}</Text>}
                </Space>) },
            { title: t('pages.recruit.col.file'), render: (_: any, r: any) => <Text style={{ fontSize: 12 }}>{r.file_name || t('pages.recruit.bodyOnly')}</Text> },
            { title: t('pages.recruit.col.candidate'), width: 190, render: (_: any, r: any) => (Number(r.candidate_id) > 0
                ? <Text strong>{r.candidate_name || candCode(r.candidate_id)}</Text>
                : r.parse_status === 'parsed' && r.doc_type !== 'cv' ? <Tag>{t(`pages.recruit.doc.${r.doc_type}`)}</Tag> : <Text type="secondary">-</Text>) },
            { title: t('pages.recruit.col.parse'), width: 110, render: (_: any, r: any) => (
                <Tag color={r.parse_status === 'parsed' ? 'green' : r.parse_status === 'failed' ? 'red' : 'default'}>{t(`pages.recruit.parse.${r.parse_status}`)}</Tag>) },
          ]} />
      </Card>

      {/* ③ 保温 */}
      <Card id="recruit-warm" size="small" loading={loading} style={{ marginBottom: 14 }}
        title={<Space>{t('pages.recruit.wb.warmTitle')}<Tag color={d.warm.length ? 'red' : 'green'}>{d.cards.warm}</Tag></Space>}
        extra={<Tooltip title={t('pages.recruit.wb.warmRule', { a: d.warm_config?.active_days, p: d.warm_config?.pool_days, s: d.warm_config?.strong_score })}>
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.wb.warmRemind')}</Text></Tooltip>}>
        <Space wrap size={4} style={{ marginBottom: 8 }}>
          {Object.entries(d.warm_count || {}).map(([k, n]: any) => (
            <Tag key={k} color={n > 0 ? WARM_COLOR[k] : 'default'}>{t(`pages.recruit.warm.${k}`)} {n}</Tag>
          ))}
        </Space>
        <Table rowKey="id" size="small" dataSource={d.warm} pagination={{ pageSize: 10, hideOnSinglePage: true }}
          locale={{ emptyText: <DoodleEmpty size="small" kind="list" description={t('pages.recruit.wb.warmEmpty')} /> }}
          rowClassName={() => 'recruit-row'} onRow={(r: any) => ({ onClick: () => setOpenId(Number(r.id)) })}
          columns={[
            { title: t('pages.recruit.col.candidate'), render: (_: any, r: any) => (
                <><div><Text strong>{r.name || t('pages.recruit.noName')}</Text> <Text type="secondary" style={{ fontSize: 12 }}>{candCode(r.id)}</Text></div>
                  <Text type="secondary" style={{ fontSize: 12 }} title={r.phone_display || r.phone_key || ''}>
                    {[r.latest_title, maskPhone(r.phone_display || r.phone_key)].filter(Boolean).join(' · ')}</Text></>) },
            { title: t('pages.recruit.wb.why'), width: 160, render: (_: any, r: any) => (
                <><Tag color={WARM_COLOR[r.reason]}>{t(`pages.recruit.warm.${r.reason}`)}</Tag>
                  {r.reason === 'due' && <div style={{ fontSize: 12, color: '#cf1322' }}>{t('pages.recruit.nextShort', { at: fmtDay(r.next_followup_at) })}</div>}</>) },
            { title: t('pages.recruit.wb.lastContact'), width: 130, render: (_: any, r: any) => (r.last_note_at
                ? <><div>{fmtDay(r.last_note_at)}</div><Text type="secondary" style={{ fontSize: 12 }}>{agoText(r.last_note_at, t)}</Text></>
                : <Text type="warning">{t('pages.recruit.wb.neverContacted')}</Text>) },
            { title: t('pages.recruit.col.job'), width: 280, render: (_: any, r: any) => (
                r.active_stage
                  ? <><Tag color={STAGE_COLOR[r.active_stage]}>{t(`pages.recruit.stage.${r.active_stage}`)}</Tag><div style={{ fontSize: 12 }}>{r.active_job}</div></>
                  : r.best_job ? <div style={{ fontSize: 12 }}>{r.best_job}{r.best_score !== null && <Text type="secondary"> · {Number(r.best_score).toFixed(1)}</Text>}</div> : '-') },
            { title: t('pages.recruit.col.status'), width: 110, render: (_: any, r: any) => <Tag color={STATUS_COLOR[r.status]}>{t(`pages.recruit.status.${r.status}`)}</Tag> },
          ]} />
      </Card>

      {/* ④ 我的客户与项目 */}
      <Card size="small" loading={loading} title={t('pages.recruit.wb.clients')}>
        <Table rowKey="id" size="small" dataSource={d.projects} pagination={{ pageSize: 10, hideOnSinglePage: true }}
          locale={{ emptyText: <DoodleEmpty size="small" kind="search" description={t('pages.recruit.wb.noProjects')} /> }}
          rowClassName={() => 'recruit-row'} onRow={(p: any) => ({ onClick: () => history.push(`/recruit/talent?owner_id=${owner}&project_id=${p.id}`) })}
          columns={[
            { title: t('pages.recruit.wb.clientProject'), width: 260, render: (_: any, p: any) => (
                <><div><Text strong>{p.customer_group_name || (p.kind === 'internal' ? t('pages.recruit.kind.internal') : '-')}</Text></div>
                  <Text type="secondary" style={{ fontSize: 12 }}>{p.name} · {t('pages.recruit.wb.openJobs', { n: p.open_jobs })}</Text></>) },
            { title: t('pages.recruit.wb.myPipeline'), render: (_: any, p: any) => {
                const pipe = p.my_pipeline || {};
                const tags = PIPE.filter((s) => pipe[s] > 0);
                return tags.length ? <Space wrap size={2}>{tags.map((s) => <Tag key={s} color={STAGE_COLOR[s]}>{t(`pages.recruit.stage.${s}`)} {pipe[s]}</Tag>)}</Space>
                  : <Text type="secondary" style={{ fontSize: 12 }}>{pipe.suggested ? t('pages.recruit.wb.onlySuggested', { n: pipe.suggested }) : '-'}</Text>;
              } },
          ]} />
      </Card>

      <CandidateDrawer open={openId !== null} candidateId={openId} meta={meta} onClose={() => setOpenId(null)} onChanged={load} />
    </div>
  );
};

export default Workbench;
