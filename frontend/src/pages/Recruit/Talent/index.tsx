/**
 * 人才库：所有候选人（一个人一行，按手机号识别）。
 * 顶部统计卡可点，点了就套上对应筛选——卡片数字与点开后的列表是同一组条件（后端 recruitCandidateFilterSql，§6.7.1）。
 * 从项目/统计页跳来时，URL 带 job_id / project_id / owner_id / from / to / min_score / stage_reached。
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Table, Input, Select, Space, Button, Tag, Typography, Cascader, DatePicker, Checkbox, Tooltip, Empty, Segmented,
} from 'antd';
import { SearchOutlined, ReloadOutlined, CloudUploadOutlined, TeamOutlined, StarFilled } from '@ant-design/icons';
import { useIntl, useLocation, history } from '@umijs/max';
import dayjs from 'dayjs';
import { recruitMeta, recruitListCandidates, recruitStats } from '@/services/api';
import { maskPhone, maskEmail } from '@/utils/mask';
import CandidateDrawer from '../components/CandidateDrawer';
import StatCard from '../components/StatCard';
import UploadDrawer from '../components/UploadDrawer';
import RowActions from '../components/RowActions';
import { ScoreTag, STATUS_COLOR, effScore, showErr, fmtDay, ageOf, FollowCell, candCode, parseCandCode, LangTags, PersonalTags, BenchTag, segName } from '../common';
import PipelineButton from '../components/PipelineButton';
import DoodleEmpty from '../components/DoodleEmpty';
import TeamProgress from '../components/TeamProgress';

const { Text } = Typography;
const CARD_ART: Record<string, string> = { new_month: 'month', unclaimed: 'unclaimed', due_today: 'due', noted_today: 'followup', ai_pending: 'review',
                                            strong_new: 'star', parse_failed: 'failed', queue: 'queue' };
const FILTER_KEYS = ['keyword', 'owner_id', 'status', 'project_id', 'job_id', 'min_score', 'min_edu', 'industry', 'from', 'to', 'fav',
                     'flags', 'due', 'stage_reached', 'source_user_id', 'show_low', 'follow', 'noted_from', 'noted_by', 'noted_name',
                     'sort', 'order', 'lang', 'religion', 'marital',
                     'company_id', 'segment_id', 'tier', 'region', 'job_function', 'company_state'] as const;
/** 列头排序 → 后端 sort 字段（recruitCandidateOrderSql 白名单） */
const SORT_KEYS = ['id', 'years', 'edu', 'score', 'status', 'follow', 'received'] as const;

const Talent: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: Record<string, any>) => intl.formatMessage({ id }, v), [intl]);
  const location = useLocation();
  const [meta, setMeta] = useState<any>(null);
  const [cards, setCards] = useState<any>({});
  const [rows, setRows] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [search, setSearch] = useState<{ mode: string; fallback: boolean } | null>(null);
  const [favCount, setFavCount] = useState(0);
  /** 按职位 / 项目看时：隐藏了几位 AI 低分推荐、AI 一共评过几人、最高几分（后端 recruitHidesLow） */
  const [low, setLow] = useState<{ hidden: number; line: number; evaluated: number; best: number | null } | null>(null);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(20);
  const [f, setF] = useState<Record<string, any>>(() => {
    const q = new URLSearchParams(location.search);
    const init: Record<string, any> = {};
    FILTER_KEYS.forEach((k) => { const v = q.get(k); if (v !== null && v !== '') init[k] = v; });
    return init;
  });
  const [openId, setOpenId] = useState<number | null>(null);
  const [uploading, setUploading] = useState(false);
  const [changed, setChanged] = useState(0);   // 写了跟进 / 改了状态 → 团队进度跟着刷新

  useEffect(() => { recruitMeta().then((r: any) => (r?.success ? setMeta(r.data) : showErr(r, t))); }, [t]);
  const loadCards = useCallback(() => {
    recruitStats({ from: dayjs().startOf('month').format('YYYY-MM-DD'), to: dayjs().endOf('month').format('YYYY-MM-DD') })
      .then((r: any) => r?.success && setCards(r.data.cards || {}));
  }, []);
  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, any> = { page, pageSize };
      Object.entries(f).forEach(([k, v]) => { if (v !== undefined && v !== '' && v !== null && v !== false) params[k] = v === true ? 1 : v; });
      const res = await recruitListCandidates(params);
      if (res?.success) { setRows(res.data || []); setTotal(res.total || 0); setSearch(res.search || null); setFavCount(res.fav_count || 0); setLow(res.low || null); } else showErr(res, t);
    } finally { setLoading(false); }
  }, [f, page, pageSize, t]);
  useEffect(() => { load(); }, [load]);
  useEffect(() => { loadCards(); }, [loadCards]);

  const set = (patch: Record<string, any>) => { setPage(1); setF((p) => ({ ...p, ...patch })); };
  const reset = () => { setPage(1); setF({}); history.replace('/recruit/talent'); };

  // 项目 → 职位 级联（只列开放的；从链接带来的已关闭职位照样能筛，只是这里不显示）
  const jobTree = useMemo(() => {
    const m = new Map<number, any>();
    (meta?.open_jobs || []).forEach((j: any) => {
      const pid = Number(j.project_id);
      if (!m.has(pid)) m.set(pid, { value: pid, label: j.project_name, children: [] });
      m.get(pid).children.push({ value: Number(j.id), label: j.title });
    });
    return [...m.values()];
  }, [meta]);

  const monthFrom = dayjs().startOf('month').format('YYYY-MM-DD');
  const monthTo = dayjs().endOf('month').format('YYYY-MM-DD');
  const cardDefs = [
    { key: 'new_month', filter: { from: monthFrom, to: monthTo } },
    { key: 'unclaimed', filter: { owner_id: 'unclaimed' } },
    { key: 'due_today', filter: { due: true } },
    { key: 'noted_today', filter: { noted_from: dayjs().format('YYYY-MM-DD') } },
    { key: 'ai_pending', filter: { follow: 'ai' } },
    { key: 'strong_new', filter: { status: 'new', min_score: 4 } },
    { key: 'parse_failed', filter: null, files: 'failed' as const },   // 数的是文件不是人 → 去「简历解析」页
    { key: 'queue', filter: null, files: 'queue' as const },
  ];

  // 服务端排序（翻页后顺序连贯）；点列头 → f.sort / f.order
  const sorted = (key: typeof SORT_KEYS[number]) => ({
    key, sorter: true, sortDirections: ['descend', 'ascend'] as any,
    sortOrder: f.sort === key ? (f.order === 'asc' ? 'ascend' : 'descend') as any : null,
  });
  const columns = [
    // 序号：本页位置（翻页接着数）；人的唯一编号是 OH-CD-…，在候选人列里
    { title: t('pages.recruit.col.seq'), width: 56, align: 'center' as const, render: (_: any, __: any, i: number) => (
        <Text type="secondary">{(page - 1) * pageSize + i + 1}</Text>) },
    { title: t('pages.recruit.col.candidate'), width: 210, ...sorted('id'), render: (_: any, r: any) => {
        const age = ageOf(r.birth_date);
        const flags = String(r.review_flags || '').split(',').filter(Boolean);
        return (
          <>
            <div><Text strong>{r.name || t('pages.recruit.noName')}</Text> <Text type="secondary" style={{ fontSize: 12 }}>{candCode(r.id)}</Text></div>
            <Text type="secondary" style={{ fontSize: 12 }}>
              {[r.gender ? t(`pages.recruit.gender.${r.gender}`) : '', age !== null ? t('pages.recruit.age', { n: age }) : '', r.city].filter(Boolean).join(' · ') || '-'}
            </Text>
            {flags.length > 0 && <div>{flags.map((x) => <Tag key={x} color="orange" style={{ fontSize: 11 }}>{t(`pages.recruit.flag.${x}`)}</Tag>)}</div>}
            {/* 语言 / 宗教 / 婚姻（「一定要标明」）：三个固定语言位 + 简历明写的个人情况 */}
            <div style={{ marginTop: 2 }}><LangTags langs={r.languages_json} t={t} compact /></div>
            {(r.religion || r.marital_status || r.ethnicity) && <div style={{ marginTop: 2 }}><PersonalTags row={r} t={t} compact /></div>}
            {r.hit_by === 'semantic' && (
              <div><Tag color="cyan" style={{ fontSize: 11 }}>
                {t('pages.recruit.search.hit', { score: Number(r.relevance).toFixed(2), facet: r.hit_facet ? t(`pages.recruit.facet.${r.hit_facet}`) : '' })}
              </Tag></div>
            )}
            {r.hit_by === 'keyword' && <div><Tag style={{ fontSize: 11 }}>{t('pages.recruit.search.keywordHit')}</Tag></div>}
          </>
        );
      } },
    { title: t('pages.recruit.col.contact'), width: 145, render: (_: any, r: any) => {
        const ph = r.phone_display || r.phone_key;
        return (
          <>
            <div title={ph || ''}>{ph ? maskPhone(ph) : '-'}</div>
            {r.email_key && <Text type="secondary" style={{ fontSize: 12 }} title={r.email_key}>{maskEmail(r.email_key)}</Text>}
          </>
        );
      } },
    { title: t('pages.recruit.col.latest'), width: 190, ...sorted('years'), render: (_: any, r: any) => (
        <>
          <div>{r.latest_title || '-'}{r.latest_company ? <Text type="secondary"> @ {r.latest_company}</Text> : ''}</div>
          {/* 企业库标签：一行挂一个（标杆名次 > 梯队 > 在职），bench.name 是命中的那家 */}
          {!!r.bench && (r.bench.rank || r.bench.tier) && <div><BenchTag bench={r.bench} t={t} locale={intl.locale} showName={r.bench.name !== r.latest_company} /></div>}
          {r.years_exp !== null && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.years', { n: r.years_exp })}</Text>}
        </>) },
    { title: t('pages.recruit.col.edu'), width: 130, ...sorted('edu'), render: (_: any, r: any) => (
        <>
          <div>{r.highest_edu ? t(`pages.recruit.edu.${r.highest_edu}`) : '-'}</div>
          {r.latest_school && <Text type="secondary" style={{ fontSize: 12 }}>{r.latest_school}</Text>}
        </>) },
    { title: f.job_id ? t('pages.recruit.col.thisJob') : t('pages.recruit.col.bestMatch'), width: 200, ...sorted('score'), render: (_: any, r: any) => {
        const b = r.best_match;
        if (!b) return <Text type="secondary">{t('pages.recruit.noMatch')}</Text>;
        return (
          <>
            <Space size={4} wrap>
              <ScoreTag score={effScore(b)} t={t} human={b.human_score !== null && b.human_score !== undefined} />
              {r.match_count > 1 && !f.job_id && <Tooltip title={t('pages.recruit.moreMatches')}><Tag>+{r.match_count - 1}</Tag></Tooltip>}
            </Space>
            <div style={{ fontSize: 12 }}>{b.job_title} <Text type="secondary">· {b.project_name}</Text></div>
          </>
        );
      } },
    { title: t('pages.recruit.col.status'), width: 110, ...sorted('status'), render: (_: any, r: any) => {
        const due = r.next_followup_at && dayjs(r.next_followup_at).isBefore(dayjs().endOf('day'));
        return (
          <>
            <Tag color={STATUS_COLOR[r.status]}>{t(`pages.recruit.status.${r.status}`)}</Tag>
            {r.next_followup_at && <div style={{ fontSize: 12, color: due ? '#cf1322' : '#888' }}>{t('pages.recruit.nextShort', { at: fmtDay(r.next_followup_at) })}</div>}
          </>
        );
      } },
    { title: t('pages.recruit.col.follow'), width: 210, ...sorted('follow'), render: (_: any, r: any) => <FollowCell row={r} t={t} /> },
    // 收到时间并进归属列第二行（加了序号列后仍守住 ≤10 列，§6.3）
    { title: t('pages.recruit.col.ownerReceived'), width: 150, ...sorted('received'), render: (_: any, r: any) => (
        <>
          {r.owner_user_id > 0 ? r.owner_user_name : <Tag color="orange">{t('pages.recruit.unclaimed')}</Tag>}
          {(r.also_by || []).length > 0 && <div><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.alsoBy', { names: r.also_by.join(', ') })}</Text></div>}
          <div><Text type="secondary" style={{ fontSize: 12 }}>
            {fmtDay(r.first_received_at)}{r.resume_count > 1 ? ` · ${t('pages.recruit.resumeCount', { n: r.resume_count })}` : ''}
          </Text></div>
        </>) },
    // 操作：收藏 + 快速跟进（固定在右侧，横向滚动时也看得到）
    { title: t('pages.recruit.col.actions'), width: 90, fixed: 'right' as const, render: (_: any, r: any) => (
        <RowActions key={`${r.id}:${r.favorited ? 1 : 0}`} row={r} t={t} onChanged={() => { load(); loadCards(); setChanged((n) => n + 1); }} />) },
  ];

  /* 空列表说清楚为什么（「我们的简历都是这样」）：
     选了职位 = 只列已挂到该职位的人（AI 匹配或人工加），匹配没跑就是空的；另有筛选条件也可能筛空 */
  const filtered = Object.keys(f).some((k) => f[k] !== undefined && f[k] !== '' && f[k] !== false);
  const emptyView = (
    <DoodleEmpty kind={low && low.hidden > 0 ? 'nofit' : filtered ? 'search' : 'inbox'} description={
      <div style={{ maxWidth: 520, margin: '0 auto' }}>
        {/* AI 评过人但没一个够线：说清楚是「库里没有合适的人」，不是「还没匹配」 */}
        {low && low.hidden > 0
          ? <div>{t('pages.recruit.empty.noFit', { n: low.evaluated, best: low.best ?? '-', line: low.line })}</div>
          : <div>{f.job_id ? t('pages.recruit.empty.job') : filtered ? t('pages.recruit.empty.filtered') : t('pages.recruit.empty.none')}</div>}
        {!!f.job_id && !(low && low.hidden > 0) && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.empty.jobHint')}</Text>}
        {Number(cards.queue) > 0 && <div><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.empty.queue', { n: cards.queue })}</Text></div>}
      </div>}>
      <Space wrap style={{ justifyContent: 'center' }}>
        {(!!f.job_id || Number(cards.queue) > 0) && <PipelineButton type="primary" onDone={() => { load(); loadCards(); }} />}
        {filtered && <Button onClick={reset}>{t('pages.recruit.empty.clear')}</Button>}
        {!!low?.hidden && <Button onClick={() => set({ show_low: 1 })}>{t('pages.recruit.low.show', { n: low.hidden })}</Button>}
        {!!f.job_id && <Button onClick={() => set({ job_id: undefined, project_id: undefined })}>{t('pages.recruit.empty.clearJob')}</Button>}
        <Button icon={<CloudUploadOutlined />} onClick={() => setUploading(true)}>{t('pages.recruit.upload.title')}</Button>
      </Space>
    </DoodleEmpty>
  );

  return (
    <div>
      {/* 可点行 hover：盖过 AntD 的 .ant-table-cell-row-hover（§6.3） */}
      <style>{`.recruit-row:hover > td, .recruit-row > td.ant-table-cell-row-hover { background:#eef4fb !important; cursor:pointer }`}</style>
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        {cardDefs.map((c) => (
          <StatCard key={c.key} art={CARD_ART[c.key]} minWidth={150} value={cards[c.key] ?? '-'}
            title={c.key === 'due_today' ? <Tooltip title={t('pages.recruit.card.dueHint')}>{t(`pages.recruit.card.${c.key}`)}</Tooltip> : t(`pages.recruit.card.${c.key}`)}
            danger={c.key === 'parse_failed' && cards[c.key] > 0}
            onClick={() => { if ((c as any).files) history.push(`/recruit/resumes?view=${(c as any).files}`); else if (c.filter) { setPage(1); setF(c.filter); } }} />
        ))}
      </div>

      <TeamProgress t={t} reloadKey={changed} onDrill={(filter) => { setPage(1); setF(filter); }} />

      <Space wrap style={{ marginBottom: 12 }}>
        <Input allowClear prefix={<SearchOutlined />} style={{ width: 320 }} placeholder={t('pages.recruit.searchPh')}
          defaultValue={f.keyword} onPressEnter={(e) => set({ keyword: (e.target as HTMLInputElement).value })}
          onChange={(e) => { if (!e.target.value) set({ keyword: undefined }); }} />
        <Select allowClear style={{ width: 150 }} placeholder={t('pages.recruit.col.owner')} value={f.owner_id}
          onChange={(v) => set({ owner_id: v })}
          options={[{ value: 'unclaimed', label: t('pages.recruit.unclaimed') },
            ...(meta?.owners || []).map((o: any) => ({ value: String(o.id), label: o.name }))]} />
        <Select allowClear style={{ width: 140 }} placeholder={t('pages.recruit.col.status')} value={f.status}
          onChange={(v) => set({ status: v })}
          options={(meta?.enums?.status || []).map((s: string) => ({ value: s, label: t(`pages.recruit.status.${s}`) }))} />
        <Select mode="multiple" allowClear maxTagCount="responsive" style={{ width: 200 }} placeholder={t('pages.recruit.filter.lang')}
          value={f.lang ? String(f.lang).split(',').filter(Boolean) : []} onChange={(v) => set({ lang: v.length ? v.join(',') : undefined })}
          options={(meta?.enums?.language || []).filter((c: string) => c !== 'other').map((c: string) => ({ value: c, label: t(`pages.recruit.lang.${c}`) }))} />
        <Select allowClear style={{ width: 130 }} placeholder={t('pages.recruit.p.religion')} value={f.religion} onChange={(v) => set({ religion: v })}
          options={(meta?.enums?.religion || []).map((c: string) => ({ value: c, label: t(`pages.recruit.religion.${c}`) }))} />
        <Select allowClear style={{ width: 150 }} placeholder={t('pages.recruit.col.follow')} value={f.follow}
          onChange={(v) => set({ follow: v })}
          options={['human', 'ai', 'none'].map((x) => ({ value: x, label: t(`pages.recruit.follow.${x}`) }))} />
        <Cascader style={{ width: 260 }} placeholder={t('pages.recruit.projectJob')} options={jobTree} changeOnSelect allowClear
          value={f.job_id ? [Number(f.project_id) || jobTree.find((p) => p.children.some((c: any) => c.value === Number(f.job_id)))?.value, Number(f.job_id)].filter(Boolean) as any
                : f.project_id ? [Number(f.project_id)] : undefined}
          onChange={(v: any) => set({ project_id: v?.[0], job_id: v?.[1] })} />
        <Select allowClear style={{ width: 130 }} placeholder={t('pages.recruit.minScore')} value={f.min_score !== undefined ? Number(f.min_score) : undefined}
          onChange={(v) => set({ min_score: v })}
          options={[4, 3.5, 3].map((s) => ({ value: s, label: `≥ ${s}` }))} />
        <Select allowClear style={{ width: 130 }} placeholder={t('pages.recruit.minEdu')} value={f.min_edu}
          onChange={(v) => set({ min_edu: v })}
          options={['sma', 'd3', 's1', 's2'].map((e) => ({ value: e, label: `≥ ${t(`pages.recruit.edu.${e}`)}` }))} />
        <Select allowClear showSearch optionFilterProp="label" style={{ width: 170 }} placeholder={t('pages.recruit.p.industries')} value={f.industry}
          onChange={(v) => set({ industry: v })}
          options={(meta?.enums?.industry || []).map((x: string) => ({ value: x, label: t(`pages.recruit.industry.${x}`) }))} />
        {/* 企业库筛选（同一段经历同时满足：「在美妆头部企业做过销售」） */}
        {!!meta?.co?.ready && (
          <>
            <Select allowClear style={{ width: 150 }} placeholder={t('pages.recruit.co.segment')} value={f.segment_id ? String(f.segment_id) : undefined}
              onChange={(v) => set({ segment_id: v })}
              options={(meta?.co?.segments || []).map((s: any) => ({ value: String(s.id), label: segName(s, intl.locale) }))} />
            <Select allowClear style={{ width: 130 }} placeholder={t('pages.recruit.co.tier')} value={f.tier} onChange={(v) => set({ tier: v })}
              options={[{ value: 'bench', label: t('pages.recruit.co.onlyBench') }, ...(meta?.co?.tier || []).map((x: string) => ({ value: x, label: t(`pages.recruit.tier.${x}`) }))]} />
            <Select allowClear style={{ width: 120 }} placeholder={t('pages.recruit.co.region')} value={f.region} onChange={(v) => set({ region: v })}
              options={(meta?.co?.region || []).map((x: string) => ({ value: x, label: t(`pages.recruit.region.${x}`) }))} />
            <Select allowClear style={{ width: 150 }} placeholder={t('pages.recruit.co.func')} value={f.job_function} onChange={(v) => set({ job_function: v })}
              options={(meta?.co?.job_function || []).map((x: string) => ({ value: x, label: t(`pages.recruit.func.${x}`) }))} />
            <Select allowClear style={{ width: 120 }} placeholder={t('pages.recruit.co.state')} value={f.company_state} onChange={(v) => set({ company_state: v })}
              options={['current', 'former'].map((x) => ({ value: x, label: t(`pages.recruit.co.bucket.${x}`) }))} />
            {!!f.company_id && <Tag closable color="blue" onClose={() => set({ company_id: undefined })}>{t('pages.recruit.co.filterCompany', { id: f.company_id })}</Tag>}
          </>
        )}
        <DatePicker.RangePicker value={f.from && f.to ? [dayjs(f.from), dayjs(f.to)] : null}
          placeholder={[t('pages.recruit.receivedFrom'), t('pages.recruit.receivedTo')]}
          onChange={(v) => set({ from: v?.[0]?.format('YYYY-MM-DD'), to: v?.[1]?.format('YYYY-MM-DD') })} />
        <Checkbox checked={f.flags === 'review'} onChange={(e) => set({ flags: e.target.checked ? 'review' : undefined })}>{t('pages.recruit.needReview')}</Checkbox>
        <Checkbox checked={!!f.due} onChange={(e) => set({ due: e.target.checked || undefined })}>{t('pages.recruit.card.due_today')}</Checkbox>
        <Button icon={<ReloadOutlined />} onClick={reset}>{t('pages.recruit.reset')}</Button>
        <Button type="primary" icon={<CloudUploadOutlined />} onClick={() => setUploading(true)}>{t('pages.recruit.upload.title')}</Button>
      </Space>
      {parseCandCode(f.keyword)?.valid === false && (
        <div style={{ marginBottom: 8 }}><Text type="warning" style={{ fontSize: 12 }}>{t('pages.recruit.codeInvalid')}</Text></div>
      )}
      {!!f.keyword && search && (
        <div style={{ marginBottom: 8 }}>
          {search.mode === 'semantic'
            ? <Tooltip title={t('pages.recruit.search.semanticHint')}><Tag color="cyan">{t('pages.recruit.search.semantic')}</Tag></Tooltip>
            : search.fallback
              ? <Text type="warning" style={{ fontSize: 12 }}>{t('pages.recruit.search.fallback')}</Text>
              : <Tag>{t('pages.recruit.search.keyword')}</Tag>}
        </div>
      )}
      {!!low?.hidden && rows.length > 0 && (
        <div style={{ marginBottom: 8 }}>
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.low.hidden', { n: low.hidden, line: low.line })}</Text>
          <Button type="link" size="small" onClick={() => set({ show_low: 1 })}>{t('pages.recruit.low.show', { n: low.hidden })}</Button>
        </div>
      )}
      {!!f.show_low && (f.job_id || f.project_id) && (
        <div style={{ marginBottom: 8 }}>
          <Tag closable color="default" onClose={() => set({ show_low: undefined })}>{t('pages.recruit.low.showing')}</Tag>
        </div>
      )}
      {!!f.noted_from && (
        <div style={{ marginBottom: 8 }}>
          <Tag closable color="blue" onClose={() => set({ noted_from: undefined, noted_by: undefined, noted_name: undefined })}>
            {f.noted_by
              ? t('pages.recruit.drill.notedBy', { name: f.noted_name || (meta?.owners || []).find((o: any) => String(o.id) === String(f.noted_by))?.name || `#${f.noted_by}`, from: f.noted_from })
              : t('pages.recruit.drill.notedFrom', { from: f.noted_from })}
          </Tag>
        </div>
      )}
      {(f.stage_reached || f.source_user_id) && (
        <div style={{ marginBottom: 8 }}>
          <Tag closable color="blue" onClose={() => set({ stage_reached: undefined, source_user_id: undefined })}>
            {f.stage_reached ? t('pages.recruit.drill.stage', { stage: t(`pages.recruit.stage.${f.stage_reached}`) }) : t('pages.recruit.drill.handled')}
          </Tag>
        </div>
      )}

      {/* 全部 / 我的收藏（「上面可以切换，那个是我的收藏」）；收藏的在「全部」里也排最前 */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
        <Segmented value={f.fav ? 'fav' : 'all'} onChange={(v) => set({ fav: v === 'fav' ? 1 : undefined })}
          options={[
            { value: 'all', label: <span><TeamOutlined /> {t('pages.recruit.quick.all')}{!f.fav ? ` · ${total}` : ''}</span> },
            { value: 'fav', label: <span><StarFilled style={{ color: '#faad14' }} /> {t('pages.recruit.quick.myFav')} · {favCount}</span> },
          ]} />
        {!f.fav && favCount > 0 && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.quick.favTop')}</Text>}
      </div>
      <Table rowKey="id" size="middle" loading={loading} dataSource={rows} columns={columns as any} scroll={{ x: 1490 }}
        locale={{ emptyText: emptyView }}
        rowClassName={() => 'recruit-row'}
        showSorterTooltip={false}
        onChange={(_p, _f, s: any, extra) => {
          if (extra?.action !== 'sort') return;
          set({ sort: s?.order ? s.columnKey : undefined, order: s?.order === 'ascend' ? 'asc' : s?.order ? 'desc' : undefined });
        }}
        onRow={(r: any) => ({ onClick: (e) => { if ((e.target as HTMLElement).closest('a,button')) return; setOpenId(Number(r.id)); } })}
        pagination={{ current: page, pageSize, total, showSizeChanger: true, showTotal: (n) => t('pages.recruit.total', { n }),
          onChange: (p, s) => { setPage(p); setPageSize(s); } }} />

      <CandidateDrawer open={openId !== null} candidateId={openId} meta={meta} focusJobId={f.job_id ? Number(f.job_id) : undefined}
        onClose={() => setOpenId(null)} onChanged={() => { load(); loadCards(); }} />

      <UploadDrawer open={uploading} meta={meta} onClose={() => setUploading(false)} onShowQueue={() => history.push('/recruit/resumes?view=queue')}
        onUploaded={() => { loadCards(); load(); }} />
    </div>
  );
};

export default Talent;
