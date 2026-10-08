/**
 * 候选人详情抽屉 —— 人才库、项目职位下钻共用这一个（同一个人只有一份详情）。
 * 顶部：身份与归属（可内联修正、admin 可转移归属/合并）；下面五个页签：跟进 / 匹配 / 档案 / 简历 / 关联。
 * 「关联」（RelatedTab）：适合的其他在招职位、像他这样的人、前同事/校友。里面点一个人，在同一个抽屉里切过去看
 * （顶部有「返回」），不叠第二层。
 * ⛔ 不在 Drawer 里嵌 Modal（§6.3）：编辑、跟进、合并都是内联表单。
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Drawer, Tabs, Tag, Space, Button, Input, InputNumber, Select, DatePicker, Typography, Empty, Spin,
  Popconfirm, Tooltip, message, Descriptions, Timeline, Segmented,
} from 'antd';
import {
  WhatsAppOutlined, EditOutlined, ReloadOutlined, EyeOutlined, DeleteOutlined, PlusOutlined, MergeCellsOutlined,
  ArrowLeftOutlined, FileTextOutlined, SolutionOutlined, ApartmentOutlined,
} from '@ant-design/icons';
import dayjs from 'dayjs';
import { useIntl, history } from '@umijs/max';
import { DetailBlock, DetailTable, DETAIL_WIDTH } from '@/components/DetailPanel';
import {
  recruitGetCandidate, recruitAddFollowup, recruitAddMatch, recruitUpdateMatch, recruitRemoveMatch,
  recruitReparseResume, recruitUpdateCandidate, recruitSetOwner, recruitMergeCandidates, recruitUpdateFollowup, recruitListDocuments,
} from '@/services/api';
import { ScoreTag, STAGE_COLOR, STATUS_COLOR, effScore, showErr, fmtMin, fmtDay, ageOf, agoText, FacetBars, candCode, parseCandCode, NO_HSCROLL, isLowMatch, LOW_LINE, LangTags, PersonalTags, money, PL_STATUS_COLOR } from '../common';
import PlacementsTab, { PlacementFormDrawer } from './PlacementsTab';
import DocumentsTab from './DocumentsTab';
import CandidateStatusBlock from './CandidateStatusBlock';
import ProfileView from './ProfileView';
import ProfileEditor from './ProfileEditor';
import RecoDrawer from './RecoDrawer';
import ResumeDrawer from './ResumeDrawer';
import RelatedTab from './RelatedTab';
import DoodleEmpty from './DoodleEmpty';
import FavStar from './FavStar';

const { Text } = Typography;

interface Props {
  open: boolean;
  candidateId: number | null;
  meta: any;
  onClose: () => void;
  onChanged?: () => void;
  /** 从职位点进来时，匹配页签把这个职位排第一 */
  focusJobId?: number;
  /** 打开时先看哪个页签（关系网络里点「看简历」直接到简历页签） */
  initTab?: string;
}

const CandidateDrawer: React.FC<Props> = ({ open, candidateId, meta, onClose, onChanged, focusJobId, initTab }) => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: Record<string, any>) => intl.formatMessage({ id }, v), [intl]);
  const lang = intl.locale === 'id-ID' ? 'id' : intl.locale === 'en-US' ? 'en' : 'zh';
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [tab, setTab] = useState('follow');
  /** 从「关联」里切过去看的人；null = 看的是父页面传进来的那位 */
  const [viewId, setViewId] = useState<number | null>(null);
  const cid = viewId ?? candidateId;

  const load = useCallback(async () => {
    if (!cid) return;
    setLoading(true);
    try {
      const res = await recruitGetCandidate(cid);
      if (res?.success) setData(res.data); else showErr(res, t);
    } finally { setLoading(false); }
  }, [cid, t]);
  useEffect(() => { if (open) setViewId(null); }, [open, candidateId]);
  useEffect(() => { if (open) { setData(null); setTab(initTab || 'follow'); load(); } }, [open, load, initTab]);

  const afterWrite = async (res: any, okKey = 'pages.recruit.msg.saved') => {
    if (res?.success) { message.success(t(okKey)); await load(); onChanged?.(); return true; }
    showErr(res, t); return false;
  };

  // ------------------------------------------------------------ 顶部：身份与归属（内联修正）
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<any>({});
  const startEdit = () => {
    setForm({ name: data.name, phone: data.phone_display || data.phone_key || '', email: data.email_key || '', city: data.city, birth_date: data.birth_date });
    setEditing(true);
  };
  const saveEdit = async () => {
    const res = await recruitUpdateCandidate({ id: data.id, ...form });
    if (res?.message_key === 'pages.recruit.err.phoneTaken') {
      message.error(t('pages.recruit.err.phoneTaken', { id: candCode(res.other_id) }));
      return;
    }
    if (await afterWrite(res)) setEditing(false);
  };
  const [ownerTo, setOwnerTo] = useState<number | undefined>();
  // 合并：输入对方编号（OH-CD-… 或 #数字）；校验码不对就当没填，不猜
  const [mergeInput, setMergeInput] = useState('');
  const mergeParsed = parseCandCode(mergeInput) ?? (/^\d+$/.test(mergeInput.trim()) ? { id: Number(mergeInput.trim()), valid: true } : null);
  const mergeId = mergeParsed?.valid ? mergeParsed.id : null;

  const flags: string[] = String(data?.review_flags || '').split(',').filter(Boolean);
  const phoneDigits = String(data?.phone_key || '').replace(/\D/g, '');

  const header = data && (
    <DetailBlock column={2}>
      <Descriptions.Item label={t('pages.recruit.f.name')}>
        {editing ? <Input size="small" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          : <Space wrap size={4}>
              <Text strong>{data.name || t('pages.recruit.noName')}</Text>
              {data.gender && <Tag>{t(`pages.recruit.gender.${data.gender}`)}</Tag>}
              {ageOf(data.birth_date) !== null && <Text type="secondary">{t('pages.recruit.age', { n: ageOf(data.birth_date) })}</Text>}
              {flags.map((f) => <Tag key={f} color="orange">{t(`pages.recruit.flag.${f}`)}</Tag>)}
              {/* 语言 / 宗教 / 婚姻 一眼可见；详情在「档案」页签 */}
              <LangTags langs={data.languages_json} t={t} compact />
              <PersonalTags row={data} t={t} compact />
            </Space>}
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.f.status')}>
        <Tag color={STATUS_COLOR[data.status]}>{t(`pages.recruit.status.${data.status}`)}</Tag>
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.f.phone')}>
        {editing ? <Input size="small" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          : (data.phone_display || data.phone_key)
            ? <Space size={6}>
                <Text copyable>{data.phone_display || data.phone_key}</Text>
                {phoneDigits && <a href={`https://wa.me/${phoneDigits}`} target="_blank" rel="noreferrer"><WhatsAppOutlined /></a>}
              </Space>
            : '-'}
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.f.email')}>
        {editing ? <Input size="small" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          : data.email_key ? <Text copyable>{data.email_key}</Text> : '-'}
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.f.city')}>
        {editing ? <Input size="small" value={form.city} onChange={(e) => setForm({ ...form, city: e.target.value })} /> : (data.city || '-')}
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.f.birth')}>
        {editing ? <Input size="small" placeholder="YYYY-MM-DD" value={form.birth_date} onChange={(e) => setForm({ ...form, birth_date: e.target.value })} /> : (data.birth_date || '-')}
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.f.owner')} span={2}>
        <Space wrap size={6}>
          {data.owner_user_id > 0 ? <Tag color="blue">{data.owner_user_name}</Tag> : <Tag color="orange">{t('pages.recruit.unclaimed')}</Tag>}
          {data.owner_set_by === 'manual' && <Text type="secondary">{t('pages.recruit.ownerManual')}</Text>}
          {(data.also_by || []).length > 0 && <Text type="secondary">{t('pages.recruit.alsoBy', { names: data.also_by.join(', ') })}</Text>}
          {meta?.can_admin && (
            <Space.Compact size="small">
              <Select size="small" style={{ width: 160 }} placeholder={t('pages.recruit.setOwner')} allowClear value={ownerTo}
                onChange={setOwnerTo} options={[{ value: 0, label: t('pages.recruit.ownerAuto') },
                  ...(meta?.owners || []).map((o: any) => ({ value: Number(o.id), label: o.name }))]} />
              <Button size="small" disabled={ownerTo === undefined}
                onClick={async () => { if (await afterWrite(await recruitSetOwner(data.id, ownerTo!))) setOwnerTo(undefined); }}>
                {t('pages.recruit.ok')}
              </Button>
            </Space.Compact>
          )}
        </Space>
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.f.follow')} span={2}>
        {(() => {
          const notes = (data.followups || []).filter((f: any) => f.kind !== 'system');
          const last = notes[0];
          const live = (data.matches || []).filter((m: any) => m.stage !== 'removed');
          const humanTouched = live.some((m: any) => m.origin === 'manual' || m.stage !== 'suggested');
          return (
            <Space direction="vertical" size={4} style={{ width: '100%' }}>
              {last ? (
                <Space wrap size={6}>
                  <Tag color="green">{t('pages.recruit.follow.human')}</Tag>
                  <Text>{last.created_by_name} · {agoText(last.created_at, t)}{last.channel ? ` · ${t(`pages.recruit.channel.${last.channel}`)}` : ''}</Text>
                  <Text type="secondary">{t('pages.recruit.follow.count', { n: notes.length })}</Text>
                  {data.next_followup_at && <Text type={dayjs(data.next_followup_at).isBefore(dayjs().endOf('day')) ? 'danger' : 'secondary'}>
                    {t('pages.recruit.fu.nextAt', { at: fmtMin(data.next_followup_at) })}</Text>}
                </Space>
              ) : humanTouched ? (
                <Space><Tag color="green">{t('pages.recruit.follow.human')}</Tag><Text type="secondary">{t('pages.recruit.follow.touched')}</Text></Space>
              ) : live.length > 0 ? (
                <Space><Tag color="blue">{t('pages.recruit.follow.ai')}</Tag><Text type="warning">{t('pages.recruit.follow.aiPending')}</Text></Space>
              ) : <Tag>{t('pages.recruit.follow.none')}</Tag>}
              {last?.content && <Text style={{ fontSize: 12, whiteSpace: 'pre-wrap' }}>{last.content}</Text>}
              {live.length > 0 && (
                <Space wrap size={4}>
                  {live.map((m: any) => (
                    <Tag key={m.id} color={STAGE_COLOR[m.stage]}>
                      {m.job_title} · {t(`pages.recruit.stage.${m.stage}`)}{m.origin === 'ai' && m.stage === 'suggested' ? ' (AI)' : ''}
                    </Tag>))}
                </Space>
              )}
            </Space>
          );
        })()}
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.cs.title')} span={2}>
        <CandidateStatusBlock data={data} meta={meta} t={t} onSaved={() => { load(); onChanged?.(); }} />
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.f.received')} span={2}>
        {fmtMin(data.first_received_at)} ~ {fmtMin(data.last_received_at)} · {t('pages.recruit.resumeCount', { n: data.resume_count })}
      </Descriptions.Item>
    </DetailBlock>
  );

  const headerActions = data && (
    <Space wrap style={{ margin: '8px 0 4px' }}>
      {editing ? (
        <>
          <Button size="small" type="primary" onClick={saveEdit}>{t('pages.recruit.save')}</Button>
          <Button size="small" onClick={() => setEditing(false)}>{t('pages.recruit.cancel')}</Button>
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.editHint')}</Text>
        </>
      ) : (
        <Button size="small" icon={<EditOutlined />} onClick={startEdit}>{t('pages.recruit.edit')}</Button>
      )}
      {!editing && <FavStar id={Number(data.id)} on={!!data.favorited} t={t} withText />}
      {/* 关系网络：他的公司、学校、同事、校友、我们的项目（企业库查看名单里的人才有，meta.co.ready） */}
      {!!meta?.co?.ready && !editing && (
        <Button size="small" icon={<ApartmentOutlined />} onClick={() => history.push(`/recruit/network?type=candidate&ref=${data.id}`)}>{t('menu.recruit.network')}</Button>
      )}
      {flags.length > 0 && !editing && (
        <Button size="small" onClick={async () => afterWrite(await recruitUpdateCandidate({ id: data.id, clear_flags: 1 }))}>
          {t('pages.recruit.clearFlags')}
        </Button>
      )}
      {meta?.can_admin && !editing && (
        <Space.Compact size="small">
          <Input size="small" allowClear placeholder={t('pages.recruit.mergeIdPh')} value={mergeInput} onChange={(e) => setMergeInput(e.target.value)}
            status={mergeInput.trim() && !mergeId ? 'error' : undefined} style={{ width: 190 }} />
          <Popconfirm title={t('pages.recruit.mergeConfirm', { drop: candCode(mergeId), keep: candCode(data.id) })} disabled={!mergeId}
            onConfirm={async () => { if (await afterWrite(await recruitMergeCandidates(data.id, mergeId!), 'pages.recruit.msg.merged')) setMergeInput(''); }}>
            <Button size="small" icon={<MergeCellsOutlined />} disabled={!mergeId}>{t('pages.recruit.merge')}</Button>
          </Popconfirm>
        </Space.Compact>
      )}
    </Space>
  );

  // ------------------------------------------------------------ 档案（与简历解析页共用 ProfileView；可整段修正，覆盖 AI 解析）
  const [editingProfile, setEditingProfile] = useState(false);
  useEffect(() => { setEditingProfile(false); }, [cid]);
  const editedSections: string[] = Object.keys(data?.locked?.sections || {});
  const profileTab = editingProfile && data ? (
    <ProfileEditor candidateId={data.id} profile={data.profile} industries={meta?.enums?.industry || []} t={t}
      onCancel={() => setEditingProfile(false)} onSaved={async () => { setEditingProfile(false); await load(); onChanged?.(); }} />
  ) : (
    <>
      <Space wrap style={{ marginBottom: 8 }}>
        <Button size="small" icon={<EditOutlined />} onClick={() => setEditingProfile(true)}>{t('pages.recruit.pe.edit')}</Button>
        {editedSections.length > 0 && (
          <>
            <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.pe.edited')}</Text>
            {editedSections.map((k) => <Tag key={k} color="purple">{t(`pages.recruit.pe.sec.${k}`)}</Tag>)}
            <Popconfirm title={t('pages.recruit.pe.resetConfirm')}
              onConfirm={async () => afterWrite(await recruitUpdateCandidate({ id: data.id, reset_sections: editedSections }))}>
              <Button size="small" type="link">{t('pages.recruit.pe.reset')}</Button>
            </Popconfirm>
          </>
        )}
      </Space>
      <ProfileView p={data?.profile} t={t} yearsExp={data?.years_exp ?? null} bench={data?.bench} translate={data?.id ? { type: 'candidate', id: Number(data.id) } : undefined} />
    </>
  );

  // ------------------------------------------------------------ 匹配
  const [addJob, setAddJob] = useState<number | undefined>();
  const [recoJob, setRecoJob] = useState<number | null>(null);   // 推荐材料抽屉（叠在本抽屉上）
  const matches = useMemo(() => {
    const list = [...(data?.matches || [])];
    if (focusJobId) list.sort((a, b) => (Number(b.job_id) === focusJobId ? 1 : 0) - (Number(a.job_id) === focusJobId ? 1 : 0));
    return list;
  }, [data, focusJobId]);
  // 不合适的职位（AI 评过 < 3 分、没人动过）默认不显示，底部可展开；从某职位点进来的那条始终显示
  const [showLow, setShowLow] = useState(false);
  useEffect(() => { setShowLow(false); }, [cid]);
  const lowCount = matches.filter((m: any) => isLowMatch(m) && Number(m.job_id) !== focusJobId).length;
  const shownMatches = showLow ? matches : matches.filter((m: any) => !isLowMatch(m) || Number(m.job_id) === focusJobId);
  const linkedJobIds = new Set(matches.filter((m: any) => m.stage !== 'removed').map((m: any) => Number(m.job_id)));
  const activePl = (linkId: number) => (data?.placements || []).find((p: any) => Number(p.candidate_job_id) === linkId
    && ['offer_accepted', 'started', 'guarantee_passed'].includes(p.status));
  const matchesTab = (
    <>
      <Space.Compact style={{ marginBottom: 10 }}>
        <Select style={{ width: 360 }} showSearch optionFilterProp="label" placeholder={t('pages.recruit.addJobPh')} value={addJob} onChange={setAddJob}
          options={(meta?.open_jobs || []).filter((j: any) => !linkedJobIds.has(Number(j.id)))
            .map((j: any) => ({ value: Number(j.id), label: `${j.project_name} · ${j.title}` }))} />
        <Button icon={<PlusOutlined />} disabled={!addJob}
          onClick={async () => { if (await afterWrite(await recruitAddMatch(data.id, addJob!))) setAddJob(undefined); }}>
          {t('pages.recruit.addJob')}
        </Button>
      </Space.Compact>
      {/* 不横向拖（§6.3）：理由整段换行，要求逐条一行、证据直接显示；人工分与阶段并一列给理由让位 */}
      <DetailTable rowKey="id" dataSource={shownMatches} locale={{ emptyText: <DoodleEmpty size="small" kind={lowCount ? 'nofit' : 'list'} description={t(lowCount ? 'pages.recruit.noFitJob' : 'pages.recruit.noMatch', { n: lowCount })} /> }}
        {...NO_HSCROLL} pagination={false}
        rowClassName={(m: any) => (m.stage === 'removed' ? 'recruit-row-removed' : '')}
        columns={[
          { title: t('pages.recruit.col.job'), width: 170, render: (_: any, m: any) => (
              <><div><Text strong>{m.job_title}</Text>{m.job_status !== 'open' && <Tag style={{ marginLeft: 6 }}>{t(`pages.recruit.jobStatus.${m.job_status}`)}</Tag>}</div>
                <Text type="secondary" style={{ fontSize: 12 }}>{m.project_name}{m.customer_group_name ? ` · ${m.customer_group_name}` : ''}</Text></>) },
          { title: t('pages.recruit.col.score'), width: 150, render: (_: any, m: any) => (
              <Space direction="vertical" size={2}>
                <ScoreTag score={effScore(m)} t={t} human={m.human_score !== null && m.human_score !== undefined} />
                {m.human_score !== null && m.ai_score !== null && <Text type="secondary" style={{ fontSize: 12 }}>AI {Number(m.ai_score).toFixed(1)}</Text>}
                {m.origin === 'manual' && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.origin.manual')}</Text>}
                {Number(m.transferable) === 1 && (
                  <Tooltip title={t('pages.recruit.transferableHint')}><Tag color="purple" style={{ marginInlineEnd: 0 }}>{t('pages.recruit.transferable')}</Tag></Tooltip>
                )}
                <FacetBars facets={m.sem_facets_json} t={t} />
              </Space>) },
          { title: t('pages.recruit.col.reason'), render: (_: any, m: any) => (
              <div style={{ fontSize: 12, lineHeight: 1.6, wordBreak: 'break-word' }}>
                {m.ai_reason?.[lang] && <div>{m.ai_reason[lang]}</div>}
                {/* 逐条一行：判定 + 要求原文（★ = 核心）+ 档案原文证据（m3 起后端核对过证据确实在档案里） */}
                {(m.ai_req_json || []).map((r: any) => (
                  <div key={r.id} style={{ display: 'flex', gap: 6, alignItems: 'flex-start', marginTop: 6 }}>
                    <Tag color={r.met === 'yes' ? 'green' : r.met === 'partial' ? 'gold' : r.level === 'core' ? 'red' : 'default'}
                      style={{ flexShrink: 0, marginInlineEnd: 0, fontSize: 11 }}>{t(`pages.recruit.met.${r.met}`)}</Tag>
                    <div>
                      {r.level === 'core' && <Text strong>★ </Text>}{r.text}
                      {!!r.evidence && <div style={{ color: '#8c8c8c' }}>{t('pages.recruit.evidence')}「{r.evidence}」</div>}
                    </div>
                  </div>))}
              </div>) },
          { title: `${t('pages.recruit.col.humanScore')} / ${t('pages.recruit.col.stage')}`, width: 120, render: (_: any, m: any) => (
              <Space direction="vertical" size={6}>
                <Tag color={STAGE_COLOR[m.stage]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.stage.${m.stage}`)}</Tag>
                {/* 推荐报告标记已发送的记录：推给了哪个客户、何时（「能直接关联到客户」） */}
                {(m.reco_sent || []).slice(0, 2).map((s: any, i: number) => (
                  <Text key={i} type="secondary" style={{ fontSize: 11, display: 'block', lineHeight: 1.4 }}>
                    {t('pages.recruit.reco.sentToClient', { client: s.client_name || '-', at: fmtMin(s.sent_at).slice(0, 10) })}</Text>))}
                {m.stage !== 'removed' && (
                  <InputNumber size="small" min={0} max={5} step={0.5} style={{ width: 90 }} placeholder={t('pages.recruit.col.humanScore')}
                    defaultValue={m.human_score ?? undefined}
                    onBlur={async (e) => {
                      const raw = (e.target as HTMLInputElement).value;
                      const v = raw === '' ? null : Number(raw);
                      if (v === (m.human_score === null ? null : Number(m.human_score))) return;
                      await afterWrite(await recruitUpdateMatch(m.id, v));
                    }} />)}
              </Space>) },
          // 推荐报告入口做成带字的按钮（「推荐报告在哪里」——原来只有一个小图标，找不到）
          { title: '', width: 110, render: (_: any, m: any) => m.stage !== 'removed' && (
              <Space direction="vertical" size={4} align="end">
                <Tooltip title={t('pages.recruit.reco.open')}>
                  <Button size="small" type="primary" ghost icon={<FileTextOutlined />} onClick={() => setRecoJob(Number(m.job_id))}>{t('pages.recruit.reco.btn')}</Button>
                </Tooltip>
                {/* 登记入职：到了 Offer / 已录用、还没有进行中的入职记录（「记录候选人是否入职」） */}
                {meta?.lc_ready && ['offered', 'hired'].includes(m.stage) && !activePl(Number(m.id)) && (
                  <Button size="small" icon={<SolutionOutlined />} onClick={() => setRegLink(Number(m.id))}>{t('pages.recruit.pl.register')}</Button>)}
                {!!activePl(Number(m.id)) && (
                  <Tag color={PL_STATUS_COLOR[activePl(Number(m.id)).status]} style={{ marginInlineEnd: 0, cursor: 'pointer' }} onClick={() => setTab('placements')}>
                    {t(`pages.recruit.plStatus.${activePl(Number(m.id)).status}`)}</Tag>)}
                <Popconfirm title={t('pages.recruit.removeMatchConfirm')} onConfirm={async () => afterWrite(await recruitRemoveMatch(m.id))}>
                  <Button type="text" size="small" danger icon={<DeleteOutlined />} />
                </Popconfirm>
              </Space>) },
        ]} />
      {lowCount > 0 && (
        <div style={{ margin: '8px 0' }}>
          <Button type="link" size="small" style={{ padding: 0 }} onClick={() => setShowLow((v) => !v)}>
            {t(showLow ? 'pages.recruit.low.hideJobs' : 'pages.recruit.low.showJobs', { n: lowCount, line: LOW_LINE })}
          </Button>
        </div>
      )}
      <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.matchHint')}</Text>
      {recoJob !== null && data && <RecoDrawer open candidateId={data.id} jobId={recoJob} onClose={() => setRecoJob(null)} />}
    </>
  );

  // ------------------------------------------------------------ 跟进（备注 / 面试 / Offer / 淘汰，「面试安排、Offer 与薪资、淘汰原因都要记」）
  const [fu, setFu] = useState<any>({ channel: 'whatsapp', kind: 'note' });
  const [fuSaving, setFuSaving] = useState(false);
  const activeLinks = matches.filter((m: any) => m.stage !== 'removed');
  const lc = meta?.lc || {};
  const reasons = lc.reason || {};
  const kind = fu.kind || 'note';
  // 这次提交会把阶段改成淘汰 / 撤回吗 → 要原因码（后端 recruitFollowupDetail 同规则）
  const needReason = kind === 'reject' || (kind === 'offer' && fu.offer_status === 'rejected') || (kind === 'note' && ['rejected', 'withdrawn'].includes(fu.stage_after));
  const reasonGroups: string[] = kind === 'reject' ? [fu.side || 'client'] : kind === 'offer' ? ['candidate'] : ['client', 'candidate', 'agency'];
  const reasonOpts = reasonGroups.map((g) => ({ label: t(`pages.recruit.reasonSide.${g}`),
    options: (reasons[g] || []).map((c: string) => ({ value: c, label: t(`pages.recruit.reason.${g}.${c}`) })) }));
  const canSubmit = kind === 'note' ? !!(fu.content || fu.status_after || fu.stage_after)
    : !!fu.link && (kind !== 'interview' || !!fu.iv_at) && (kind !== 'offer' || (fu.offer_salary !== undefined && fu.offer_salary !== null))
      && (!needReason || !!fu.reason_code);
  const submitFu = async () => {
    setFuSaving(true);
    try {
      const detail = kind === 'interview' ? { round: fu.iv_round || 1, type: fu.iv_type || 'video', scheduled_at: fu.iv_at ? dayjs(fu.iv_at).format('YYYY-MM-DD HH:mm') : '',
          interviewer: fu.iv_interviewer || '', location: fu.iv_location || '' }
        : kind === 'offer' ? { salary_monthly: fu.offer_salary, currency: fu.offer_ccy || 'IDR', allowances: fu.offer_allow || '',
          start_date: fu.offer_start ? dayjs(fu.offer_start).format('YYYY-MM-DD') : '', status: fu.offer_status || 'awaiting' }
        : kind === 'reject' ? { side: fu.side || 'client' } : undefined;
      const res = await recruitAddFollowup({
        candidate_id: data.id, kind, channel: fu.channel, content: fu.content || '', status_after: kind === 'note' ? (fu.status_after || '') : '',
        candidate_job_id: fu.link || 0, stage_after: kind === 'note' && fu.link ? (fu.stage_after || '') : '',
        next_follow_at: fu.next ? dayjs(fu.next).format('YYYY-MM-DD HH:mm') : '', reason_code: needReason ? (fu.reason_code || '') : '', detail,
      });
      if (await afterWrite(res)) {
        setFu({ channel: fu.channel, kind });
        // 推到「已录用」自动建了入职记录草稿 → 直接打开补全
        if (res?.data?.placement_draft) { message.info(t('pages.recruit.pl.draftCreated')); setTab('placements'); setPlEditId(Number(res.data.placement_id)); }
      }
    } finally { setFuSaving(false); }
  };
  const updFu = async (id: number, patch: any) => afterWrite(await recruitUpdateFollowup({ id, ...patch }));
  const [offerRej, setOfferRej] = useState<number | null>(null);   // 正在把哪条 Offer 标为拒绝（要选原因）
  const linkSel = (
    <Select style={{ width: 240 }} allowClear placeholder={t('pages.recruit.fu.job')} value={fu.link}
      onChange={(v) => setFu({ ...fu, link: v, stage_after: undefined })}
      options={activeLinks.map((m: any) => ({ value: Number(m.id), label: `${m.project_name} · ${m.job_title}` }))} />
  );
  const detailView = (f: any) => {
    const d = f.detail || {};
    if (f.kind === 'interview') return (
      <Space size={6} wrap style={{ marginTop: 2 }}>
        <Tag color="purple">{t('pages.recruit.iv.round', { n: d.round || 1 })} · {t(`pages.recruit.ivType.${d.type || 'video'}`)}</Tag>
        <Text>{fmtMin(d.scheduled_at)}</Text>
        {!!d.interviewer && <Text type="secondary">{d.interviewer}</Text>}
        {!!d.location && <Text type="secondary">{d.location}</Text>}
        <Select size="small" style={{ width: 120 }} value={d.result || 'pending'} onChange={(v) => updFu(Number(f.id), { result: v })}
          options={(lc.iv_result || []).map((r: string) => ({ value: r, label: t(`pages.recruit.ivResult.${r}`) }))} />
        {!!d.feedback && <div style={{ width: '100%', whiteSpace: 'pre-wrap', fontSize: 12 }}>{d.feedback}</div>}
      </Space>);
    if (f.kind === 'offer') return (
      <Space size={6} wrap style={{ marginTop: 2 }}>
        <Tag color="gold">{money(d.salary_monthly, d.currency)} / {t('pages.recruit.offer.month')}</Tag>
        {!!d.allowances && <Text type="secondary">{d.allowances}</Text>}
        {!!d.start_date && <Text>{t('pages.recruit.offer.start')} {fmtDay(d.start_date)}</Text>}
        <Select size="small" style={{ width: 120 }} value={offerRej === Number(f.id) ? 'rejected' : (d.status || 'awaiting')}
          onChange={(v) => (v === 'rejected' ? setOfferRej(Number(f.id)) : (setOfferRej(null), updFu(Number(f.id), { status: v })))}
          options={(lc.offer_status || []).map((r: string) => ({ value: r, label: t(`pages.recruit.offerStatus.${r}`) }))} />
        {offerRej === Number(f.id) && (
          <Select size="small" style={{ width: 180 }} placeholder={t('pages.recruit.fu.reason')}
            onChange={async (v) => { setOfferRej(null); await updFu(Number(f.id), { status: 'rejected', reason_code: v }); }}
            options={(reasons.candidate || []).map((c: string) => ({ value: c, label: t(`pages.recruit.reason.candidate.${c}`) }))} />)}
      </Space>);
    return null;
  };
  const reasonTag = (f: any) => {
    if (!f.reason_code) return null;
    const g = ['client', 'candidate', 'agency'].find((x) => (reasons[x] || []).includes(f.reason_code));
    return g ? <Tag color="red">{t(`pages.recruit.reason.${g}.${f.reason_code}`)}</Tag> : null;
  };
  const followTab = (
    <>
      <div style={{ padding: 12, background: '#fafafa', borderRadius: 6, marginBottom: 14 }}>
        {meta?.lc_ready && (
          <Segmented style={{ marginBottom: 8 }} value={kind} onChange={(v) => setFu({ channel: fu.channel, kind: String(v), link: fu.link })}
            options={(lc.fu_kind || ['note']).map((k: string) => ({ value: k, label: t(`pages.recruit.fuKind.${k}`) }))} />
        )}
        <Space wrap style={{ marginBottom: 8 }}>
          <Select style={{ width: 130 }} value={fu.channel} onChange={(v) => setFu({ ...fu, channel: v })}
            options={(meta?.enums?.channel || []).map((c: string) => ({ value: c, label: t(`pages.recruit.channel.${c}`) }))} />
          {kind === 'note' && (
            <Select style={{ width: 170 }} allowClear placeholder={t('pages.recruit.fu.statusAfter')} value={fu.status_after}
              onChange={(v) => setFu({ ...fu, status_after: v })}
              options={(meta?.enums?.status || []).map((s: string) => ({ value: s, label: t(`pages.recruit.status.${s}`) }))} />)}
          {linkSel}
          {kind === 'note' && fu.link && (
            <Select style={{ width: 150 }} allowClear placeholder={t('pages.recruit.fu.stageAfter')} value={fu.stage_after}
              onChange={(v) => setFu({ ...fu, stage_after: v })}
              options={(meta?.enums?.stage || []).filter((s: string) => s !== 'removed').map((s: string) => ({ value: s, label: t(`pages.recruit.stage.${s}`) }))} />
          )}
          {kind !== 'interview' && (
            <DatePicker showTime={{ format: 'HH:mm' }} format="YYYY-MM-DD HH:mm" placeholder={t('pages.recruit.fu.next')}
              value={fu.next ? dayjs(fu.next) : null} onChange={(v) => setFu({ ...fu, next: v ? v.toISOString() : undefined })} />)}
        </Space>
        {kind === 'interview' && (
          <Space wrap style={{ marginBottom: 8 }}>
            <InputNumber min={1} max={10} style={{ width: 110 }} addonBefore={t('pages.recruit.iv.roundLabel')} value={fu.iv_round ?? 1} onChange={(v) => setFu({ ...fu, iv_round: v })} />
            <Select style={{ width: 120 }} value={fu.iv_type || 'video'} onChange={(v) => setFu({ ...fu, iv_type: v })}
              options={(lc.iv_type || []).map((x: string) => ({ value: x, label: t(`pages.recruit.ivType.${x}`) }))} />
            <DatePicker showTime={{ format: 'HH:mm' }} format="YYYY-MM-DD HH:mm" placeholder={t('pages.recruit.iv.time')} status={fu.link && !fu.iv_at ? 'warning' : undefined}
              value={fu.iv_at ? dayjs(fu.iv_at) : null} onChange={(v) => setFu({ ...fu, iv_at: v ? v.toISOString() : undefined })} />
            <Input style={{ width: 160 }} placeholder={t('pages.recruit.iv.interviewer')} value={fu.iv_interviewer} onChange={(e) => setFu({ ...fu, iv_interviewer: e.target.value })} />
            <Input style={{ width: 200 }} placeholder={t('pages.recruit.iv.location')} value={fu.iv_location} onChange={(e) => setFu({ ...fu, iv_location: e.target.value })} />
          </Space>)}
        {kind === 'offer' && (
          <Space wrap style={{ marginBottom: 8 }}>
            <Space.Compact>
              <Select style={{ width: 80 }} value={fu.offer_ccy || 'IDR'} onChange={(v) => setFu({ ...fu, offer_ccy: v })}
                options={(lc.currency || ['IDR']).map((c: string) => ({ value: c, label: c }))} />
              <InputNumber style={{ width: 170 }} min={0} placeholder={t('pages.recruit.pl.salary')} value={fu.offer_salary}
                onChange={(v) => setFu({ ...fu, offer_salary: v })} formatter={(x) => (x ? `${x}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '')} />
            </Space.Compact>
            <Input style={{ width: 200 }} placeholder={t('pages.recruit.pl.allowances')} value={fu.offer_allow} onChange={(e) => setFu({ ...fu, offer_allow: e.target.value })} />
            <DatePicker placeholder={t('pages.recruit.offer.start')} value={fu.offer_start ? dayjs(fu.offer_start) : null}
              onChange={(v) => setFu({ ...fu, offer_start: v ? v.format('YYYY-MM-DD') : undefined })} />
            <Select style={{ width: 130 }} value={fu.offer_status || 'awaiting'} onChange={(v) => setFu({ ...fu, offer_status: v })}
              options={(lc.offer_status || []).map((r: string) => ({ value: r, label: t(`pages.recruit.offerStatus.${r}`) }))} />
          </Space>)}
        {kind === 'reject' && (
          <Space wrap style={{ marginBottom: 8 }}>
            <Segmented value={fu.side || 'client'} onChange={(v) => setFu({ ...fu, side: String(v), reason_code: undefined })}
              options={['client', 'candidate', 'agency'].map((g) => ({ value: g, label: t(`pages.recruit.reasonSide.${g}`) }))} />
          </Space>)}
        {needReason && (
          <div style={{ marginBottom: 8 }}>
            <Select style={{ width: 280 }} placeholder={t('pages.recruit.fu.reason')} value={fu.reason_code} status={!fu.reason_code ? 'warning' : undefined}
              onChange={(v) => setFu({ ...fu, reason_code: v })} options={reasonOpts} />
          </div>)}
        <Input.TextArea rows={2} placeholder={t(kind === 'note' ? 'pages.recruit.fu.contentPh' : 'pages.recruit.fu.notePh')} value={fu.content} maxLength={2000}
          onChange={(e) => setFu({ ...fu, content: e.target.value })} />
        <div style={{ textAlign: 'right', marginTop: 8 }}>
          {kind !== 'note' && !fu.link && <Text type="warning" style={{ fontSize: 12, marginRight: 8 }}>{t('pages.recruit.err.matchRequired')}</Text>}
          <Button type="primary" loading={fuSaving} disabled={!canSubmit} onClick={submitFu}>{t('pages.recruit.fu.add')}</Button>
        </div>
      </div>
      {(data?.followups || []).length === 0 ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> : (
        <Timeline items={(data?.followups || []).map((f: any) => ({
          color: f.kind === 'system' ? 'gray' : f.kind === 'interview' ? 'purple' : f.kind === 'offer' ? 'gold' : f.kind === 'reject' ? 'red' : 'blue',
          children: (
            <div>
              <Space size={6} wrap>
                <Text type="secondary" style={{ fontSize: 12 }}>{fmtMin(f.created_at)}</Text>
                <Text style={{ fontSize: 12 }}>{f.created_by_name || t('pages.recruit.system')}</Text>
                {f.kind !== 'system' && f.kind !== 'note' && <Tag color={f.kind === 'interview' ? 'purple' : f.kind === 'offer' ? 'gold' : 'red'}>{t(`pages.recruit.fuKind.${f.kind}`)}</Tag>}
                {f.channel && <Tag>{t(`pages.recruit.channel.${f.channel}`)}</Tag>}
                {f.status_after && <Tag color={STATUS_COLOR[f.status_after]}>{t(`pages.recruit.status.${f.status_after}`)}</Tag>}
                {f.stage_after && <Tag color={STAGE_COLOR[f.stage_after]}>{f.job_title ? `${f.job_title} · ` : ''}{t(`pages.recruit.stage.${f.stage_after}`)}</Tag>}
                {!f.stage_after && f.kind !== 'system' && f.kind !== 'note' && f.job_title && <Text type="secondary" style={{ fontSize: 12 }}>{f.job_title}</Text>}
                {reasonTag(f)}
              </Space>
              {detailView(f)}
              {(f.kind === 'system' || f.content) && (
                <div style={{ whiteSpace: 'pre-wrap', marginTop: 2 }}>
                  {f.kind === 'system' ? t(`pages.recruit.event.${f.event_code}`, { content: f.content || '' }) : f.content}
                </div>)}
              {f.next_follow_at && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.fu.nextAt', { at: fmtMin(f.next_follow_at) })}</Text>}
            </div>
          ),
        }))} />
      )}
    </>
  );

  // ------------------------------------------------------------ 入职记录 + 合同文件（与项目总览同一组件，§6.7.3）
  const [regLink, setRegLink] = useState<number | null>(null);
  const [plEditId, setPlEditId] = useState<number | null>(null);
  const [docs, setDocs] = useState<any[]>([]);
  const loadDocs = useCallback(() => { if (cid && meta?.lc_ready) recruitListDocuments('candidate', cid).then((r: any) => r?.success && setDocs(r.data || [])); }, [cid, meta?.lc_ready]);
  useEffect(() => { if (open && tab === 'placements') loadDocs(); }, [open, tab, loadDocs]);
  const placementsTab = data && (
    <>
      {(data.placements || []).length ? <PlacementsTab rows={data.placements} t={t} meta={meta} mode="candidate" onChanged={() => { load(); onChanged?.(); }} />
        : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('pages.recruit.pl.none')} />}
      <div style={{ marginTop: 16 }}>
        <Text strong>{t('pages.recruit.ovw.docs')}</Text>
        <DocumentsTab docs={docs} t={t} meta={meta} onChanged={loadDocs}
          entities={[{ type: 'candidate', id: Number(data.id), label: `${candCode(data.id)} ${data.name || ''}` },
            ...(data.placements || []).map((p: any) => ({ type: 'placement' as const, id: Number(p.id), label: `${p.project_name} · ${p.job_title}` }))]} />
      </div>
    </>
  );

  // ------------------------------------------------------------ 简历文件
  // 点「查看」/ 点行：在侧边打开简历详情（原件 + 抽取结果 + 解析记录），叠在本抽屉上，不跳页
  const [viewResume, setViewResume] = useState<number | null>(null);
  const resumesTab = (
    <DetailTable rowKey="id" dataSource={data?.resumes || []} rowClassName={() => 'recruit-row'} {...NO_HSCROLL}
      onRow={(r: any) => ({ onClick: (e: any) => { if ((e.target as HTMLElement).closest('button')) return; setViewResume(Number(r.id)); } })}
      columns={[
        { title: t('pages.recruit.col.file'), render: (_: any, r: any) => (
            <div>
              <div>{r.file_name || t('pages.recruit.bodyOnly')}</div>
              <Text type="secondary" style={{ fontSize: 12 }}>{r.doc_type ? t(`pages.recruit.doc.${r.doc_type}`) : '-'}{r.attach_mode === 'sibling' ? ` · ${t('pages.recruit.sibling')}` : ''}</Text>
            </div>) },
        { title: t('pages.recruit.col.receivedBy'), width: 170, render: (_: any, r: any) => (
            <><div>{fmtMin(r.received_at)}</div><Text type="secondary" style={{ fontSize: 12 }}>{r.source_user_name || t('pages.recruit.unclaimed')}</Text></>) },
        { title: t('pages.recruit.col.parse'), width: 150, render: (_: any, r: any) => (
            <Space direction="vertical" size={2}>
              <Tag color={r.parse_status === 'parsed' ? 'green' : r.parse_status === 'failed' ? 'red' : 'default'}>{t(`pages.recruit.parse.${r.parse_status}`)}</Tag>
              {r.parse_error_kind && r.parse_status !== 'parsed' && <Text type="secondary" style={{ fontSize: 12 }}>{t(`pages.recruit.errKind.${r.parse_error_kind}`)}</Text>}
            </Space>) },
        { title: '', width: 170, render: (_: any, r: any) => (
            <Space size={0}>
              <Button type="link" size="small" icon={<EyeOutlined />} onClick={() => setViewResume(Number(r.id))}>{t('pages.recruit.view')}</Button>
              {['failed', 'retry', 'parsed'].includes(r.parse_status) && (
                <Popconfirm title={t('pages.recruit.reparseConfirm')} onConfirm={async () => afterWrite(await recruitReparseResume(r.id), 'pages.recruit.msg.queued')}>
                  <Button type="link" size="small" icon={<ReloadOutlined />}>{t('pages.recruit.reparse')}</Button>
                </Popconfirm>)}
            </Space>) },
      ]} />
  );

  // ------------------------------------------------------------ 关联：其他在招职位 / 像他这样的人 / 前同事校友
  // 切到别人时从头加载：key 跟着 cid 走
  const relatedTab = cid ? <RelatedTab key={cid} cid={cid} t={t} onLinked={() => { load(); onChanged?.(); }}
    onOpenCandidate={(id) => { setViewId(id); setTab('profile'); }} /> : null;

  return (
    <Drawer open={open} onClose={onClose} width={DETAIL_WIDTH.withTable} destroyOnClose
      title={data ? (
        <Space>
          {viewId !== null && (
            <Tooltip title={t('pages.recruit.similar.back')}>
              <Button size="small" type="text" icon={<ArrowLeftOutlined />} onClick={() => { setViewId(null); setTab('related'); }} />
            </Tooltip>
          )}
          {`${candCode(data.id)} ${data.name || ''}`}
        </Space>) : t('pages.recruit.candidate')}>
      <style>{`.recruit-row-removed td { opacity: .45 } .recruit-row:hover > td, .recruit-row > td.ant-table-cell-row-hover { background:#eef4fb !important; cursor:pointer }`}</style>
      {loading && !data ? <Spin style={{ display: 'block', margin: '60px auto' }} /> : data && (
        <>
          {header}
          {headerActions}
          <Tabs activeKey={tab} onChange={setTab} items={[
            { key: 'follow', label: `${t('pages.recruit.tab.follow')} (${(data.followups || []).filter((f: any) => f.kind !== 'system').length})`, children: followTab },
            { key: 'matches', label: `${t('pages.recruit.tab.matches')} (${activeLinks.filter((m: any) => !isLowMatch(m)).length})`, children: matchesTab },
            { key: 'profile', label: t('pages.recruit.tab.profile'), children: profileTab },
            { key: 'resumes', label: `${t('pages.recruit.tab.resumes')} (${(data.resumes || []).length})`, children: resumesTab },
            { key: 'related', label: t('pages.recruit.tab.related'), children: relatedTab },
            ...(meta?.lc_ready ? [{ key: 'placements', label: `${t('pages.recruit.tab.placements')} (${(data.placements || []).length})`, children: placementsTab }] : []),
          ]} />
        </>
      )}
      {/* 简历详情叠在本抽屉上（点「查看」不跳页） */}
      {/* 登记入职 / 补全入职记录（叠在本抽屉上，不用 Modal） */}
      {data && (
        <>
          <PlacementFormDrawer open={regLink !== null} t={t} meta={meta} linkId={regLink || undefined} onClose={() => setRegLink(null)}
            onSaved={() => { setRegLink(null); setTab('placements'); load(); onChanged?.(); }} />
          <PlacementFormDrawer open={plEditId !== null && !!(data.placements || []).find((p: any) => Number(p.id) === plEditId)} t={t} meta={meta}
            title={t('pages.recruit.pl.completeDraft')} placement={(data.placements || []).find((p: any) => Number(p.id) === plEditId)} onClose={() => setPlEditId(null)}
            onSaved={() => { setPlEditId(null); load(); onChanged?.(); }} />
        </>
      )}
      {viewResume !== null && <ResumeDrawer open resumeId={viewResume} onClose={() => setViewResume(null)} onChanged={load}
        onOpenCandidate={() => setViewResume(null)} />}
    </Drawer>
  );
};

export default CandidateDrawer;
