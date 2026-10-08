/**
 * 人才检索页的共用件：条件标签、结果表、批量操作条。检索页签与人才池展开面板用同一套（同一份结果一份实现）。
 * 条件结构见后端 includes/recruit_query.php：{ type, label: {zh,en,id}, keywords: string[], must }。
 */
import React, { useState } from 'react';
import { Button, Empty, Input, Popconfirm, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { PlusOutlined, UserAddOutlined } from '@ant-design/icons';
import { recruitBulkAddMatch, recruitPoolMembers } from '@/services/api';
import { FacetBars, ScoreTag, STATUS_COLOR, effScore, showErr, candCode, type T } from '../common';
import DoodleEmpty from '../components/DoodleEmpty';

const { Text } = Typography;
export type Lang = 'zh' | 'en' | 'id';
export type Cond = { type: string; label: Record<Lang, string>; keywords: string[]; must: boolean };
export const TYPE_COLOR: Record<string, string> = {
  skill: 'blue', title: 'geekblue', industry: 'purple', experience: 'cyan', education: 'gold', location: 'green',
  language: 'magenta', certificate: 'volcano', other: 'default',
};
export const condLabel = (c: Cond, lang: Lang) => c.label?.[lang] || c.label?.zh || c.keywords[0];

/** 拆出来的条件逐条列出：点标签切「必须 / 加分」，× 删除，悬停看三语关键字；末尾可手动加一条 */
export const CondChips: React.FC<{ conds: Cond[]; lang: Lang; t: T; onChange?: (c: Cond[]) => void }> = ({ conds, lang, t, onChange }) => {
  const [adding, setAdding] = useState('');
  const add = () => {
    const v = adding.trim();
    if (v.length < 2 || !onChange) return;
    onChange([...conds, { type: 'other', label: { zh: v, en: v, id: v }, keywords: [v.toLowerCase()], must: false }]);
    setAdding('');
  };
  return (
    <Space size={[6, 8]} wrap>
      {conds.map((c, i) => (
        <Tooltip key={`${i}-${c.keywords[0]}`} title={<>{t(`pages.recruit.finder.type.${c.type}`)} · {t('pages.recruit.finder.keywords')}<br />{c.keywords.join(' / ')}</>}>
          <Tag color={c.must ? TYPE_COLOR[c.type] : undefined} closable={!!onChange}
            onClose={(e) => { e.preventDefault(); onChange?.(conds.filter((_, j) => j !== i)); }}
            style={{ cursor: onChange ? 'pointer' : 'default', borderStyle: c.must ? 'solid' : 'dashed', padding: '2px 8px', fontSize: 13, marginInlineEnd: 0 }}
            onClick={() => onChange?.(conds.map((x, j) => (j === i ? { ...x, must: !x.must } : x)))}>
            <Text style={{ fontSize: 11, opacity: 0.75, marginInlineEnd: 4 }}>{t(c.must ? 'pages.recruit.finder.must' : 'pages.recruit.finder.bonus')}</Text>
            {condLabel(c, lang)}
          </Tag>
        </Tooltip>
      ))}
      {!!onChange && (
        <Input size="small" style={{ width: 150 }} value={adding} placeholder={t('pages.recruit.finder.addCond')} prefix={<PlusOutlined />}
          onChange={(e) => setAdding(e.target.value)} onPressEnter={add} onBlur={add} />
      )}
    </Space>
  );
};

/**
 * 列排序（「这里需要可以直接排序」）：检索结果最多 100 人、一次全给，前端排即可，不再请求后端。
 * 数字列第一次点是从高到低；空值（没分、没年限）不管升降都排最后，免得一点排序先看到一排「-」。
 */
const numSorter = (get: (r: any) => number | null | undefined) => (a: any, b: any, order?: 'ascend' | 'descend' | null) => {
  const x = get(a); const y = get(b);
  const nx = x === null || x === undefined || Number.isNaN(x); const ny = y === null || y === undefined || Number.isNaN(y);
  if (nx && ny) return 0;
  if (nx) return order === 'ascend' ? 1 : -1;
  if (ny) return order === 'ascend' ? -1 : 1;
  return (x as number) - (y as number);
};
const num = (v: any) => (v === null || v === undefined || v === '' ? null : Number(v));
/** 状态按流程先后排（新进 → 联系中 → 流程中 → 暂缓 → 已入职 → 不感兴趣 / 联系不上） */
const STATUS_ORDER = ['new', 'contacting', 'in_process', 'on_hold', 'placed', 'not_interested', 'unreachable', 'blacklisted'];
const DESC_FIRST = ['descend', 'ascend'] as ('descend' | 'ascend')[];

/** 结果表：命中条件逐条打勾（按条件检索时）或相关度条（语义 / 以人找人） */
export const ResultTable: React.FC<{
  rows: any[]; loading: boolean; res: any; conds: Cond[]; lang: Lang; t: T;
  sel: number[]; setSel: (v: number[]) => void; onOpen: (id: number) => void;
}> = ({ rows, loading, res, conds, lang, t, sel, setSel, onOpen }) => {
  const tokens: string[] = res?.tokens || [];
  const columns = [
    { title: t('pages.recruit.col.candidate'), width: 230, sorter: (a: any, b: any) => String(a.name || '').localeCompare(String(b.name || '')),
      render: (_: any, r: any) => (
        <>
          <div><Text strong>{r.name || t('pages.recruit.noName')}</Text>
            {r.in_pool && <Tag color="gold" style={{ marginInlineStart: 6, fontSize: 11 }}>{t('pages.recruit.finder.inPool')}</Tag>}</div>
          <Text type="secondary" style={{ fontSize: 12 }}>{candCode(r.id)}</Text>
          <div><Text type="secondary" style={{ fontSize: 12 }}>
            {[r.city, ...(r.industries || []).slice(0, 2).map((x: string) => t(`pages.recruit.industry.${x}`))].filter(Boolean).join(' · ') || '-'}
          </Text></div>
        </>) },
    { title: t('pages.recruit.col.latest'), width: 250, sorter: numSorter((r) => num(r.years_exp)), sortDirections: DESC_FIRST,
      render: (_: any, r: any) => (
        <>
          <div>{r.latest_title || '-'}{r.latest_company ? <Text type="secondary"> @ {r.latest_company}</Text> : ''}</div>
          <Text type="secondary" style={{ fontSize: 12 }}>
            {[r.years_exp !== null ? t('pages.recruit.years', { n: r.years_exp }) : '', r.highest_edu ? t(`pages.recruit.edu.${r.highest_edu}`) : '', r.latest_school].filter(Boolean).join(' · ')}
          </Text>
        </>) },
    { title: conds.length ? t('pages.recruit.finder.col.hits') : t('pages.recruit.related.col.relevance'), width: 260,
      sorter: numSorter((r) => (res?.mode === 'keyword' ? num(r.hits) : num(r.score))), sortDirections: DESC_FIRST,
      render: (_: any, r: any) => {
        const pct = r.score === null || r.score === undefined ? null : <Text strong style={{ color: '#1a2ad6' }}>{Math.round(Number(r.score) * 100)}%</Text>;
        if (res?.mode === 'conds') {
          const hit = new Set<number>((r.hit_conds || []).map(Number));
          return (
            <>
              <div>{pct}</div>
              <Space size={[4, 4]} wrap>
                {conds.map((c, i) => (
                  <Tag key={i} color={hit.has(i) ? 'success' : undefined} style={{ fontSize: 11, marginInlineEnd: 0, textDecoration: hit.has(i) ? undefined : 'line-through', opacity: hit.has(i) ? 1 : 0.55 }}>
                    {hit.has(i) ? '✓ ' : ''}{condLabel(c, lang)}
                  </Tag>))}
              </Space>
            </>);
        }
        if (res?.mode === 'keyword' && r.hits !== null && r.hits !== undefined) return <Tag>{t('pages.recruit.finder.hits', { n: r.hits, m: Math.max(tokens.length, 1) })}</Tag>;
        if (!pct) return <Text type="secondary">-</Text>;
        return <>{pct}<FacetBars facets={r.facets} t={t} /></>;
      } },
    { title: t('pages.recruit.col.bestMatch'), width: 200, sorter: numSorter((r) => (r.best_match ? effScore(r.best_match) : null)), sortDirections: DESC_FIRST,
      render: (_: any, r: any) => {
        const b = r.best_match;
        if (!b) return <Text type="secondary">{t('pages.recruit.noMatch')}</Text>;
        return (<><ScoreTag score={effScore(b)} t={t} human={b.human_score !== null && b.human_score !== undefined} />
          <div style={{ fontSize: 12 }}>{b.job_title} <Text type="secondary">· {b.project_name}</Text></div></>);
      } },
    { title: t('pages.recruit.col.status'), width: 130,
      sorter: (a: any, b: any) => STATUS_ORDER.indexOf(a.status) - STATUS_ORDER.indexOf(b.status), render: (_: any, r: any) => (
        <><Tag color={STATUS_COLOR[r.status]}>{t(`pages.recruit.status.${r.status}`)}</Tag>
          <div><Text type="secondary" style={{ fontSize: 12 }}>{r.owner_user_id > 0 ? r.owner_user_name : t('pages.recruit.unclaimed')}</Text></div></>) },
  ];
  return (
    <Table rowKey="id" size="middle" loading={loading} dataSource={rows} columns={columns as any} scroll={{ x: 1100 }} showSorterTooltip={false}
      pagination={{ pageSize: 20, showSizeChanger: true, hideOnSinglePage: true }}
      rowSelection={{ selectedRowKeys: sel, onChange: (k) => setSel(k.map(Number)) }}
      rowClassName={() => 'recruit-row'}
      onRow={(r: any) => ({ onClick: (e) => { if ((e.target as HTMLElement).closest('a,button,.ant-checkbox-wrapper,.ant-table-selection-column')) return; onOpen(Number(r.id)); } })}
      locale={{ emptyText: <DoodleEmpty kind="search" description={t('pages.recruit.finder.empty')} /> }} />
  );
};

/** 勾选后的批量操作：加入在招职位（AI 随后打分）/ 加入人才池 / 移出当前池 */
export const BulkBar: React.FC<{
  sel: number[]; setSel: (v: number[]) => void; t: T; meta: any; pools: any[]; poolId?: number; onDone: () => void;
}> = ({ sel, setSel, t, meta, pools, poolId, onDone }) => {
  const [job, setJob] = useState<number>();
  const [toPool, setToPool] = useState<number>();
  if (!sel.length) return null;
  const jobOptions = (() => {
    const m = new Map<string, any>();
    (meta?.open_jobs || []).forEach((j: any) => {
      if (!m.has(j.project_name)) m.set(j.project_name, { label: j.project_name, options: [] });
      m.get(j.project_name).options.push({ value: Number(j.id), label: j.title });
    });
    return [...m.values()];
  })();
  const run = async (p: Promise<any>, okKey: string, vals: (d: any) => any) => {
    const r = await p;
    if (!r?.success) { showErr(r, t); return; }
    message.success(t(okKey, vals(r.data)), 6);
    setSel([]); onDone();
  };
  return (
    <Space wrap>
      <Text strong>{t('pages.recruit.finder.selected', { n: sel.length })}</Text>
      <Select showSearch optionFilterProp="label" style={{ width: 240 }} placeholder={t('pages.recruit.finder.pickJob')} value={job} onChange={setJob} options={jobOptions} />
      <Button type="primary" icon={<UserAddOutlined />} disabled={!job} onClick={() => run(recruitBulkAddMatch(job!, sel), 'pages.recruit.finder.addedJob', (d) => d)}>
        {t('pages.recruit.finder.addJob')}</Button>
      <Select style={{ width: 180 }} placeholder={t('pages.recruit.finder.pickPool')} value={toPool} onChange={setToPool}
        options={pools.filter((p) => Number(p.id) !== poolId).map((p: any) => ({ value: Number(p.id), label: p.name }))} />
      <Button disabled={!toPool} onClick={() => run(recruitPoolMembers(toPool!, sel), 'pages.recruit.finder.addedPool', (d) => ({ n: d.added }))}>
        {t('pages.recruit.finder.addPool')}</Button>
      {!!poolId && (
        <Popconfirm title={t('pages.recruit.finder.removePool')} onConfirm={() => run(recruitPoolMembers(poolId, [], sel), 'pages.recruit.finder.removedPool', (d) => ({ n: d.removed }))}>
          <Button danger>{t('pages.recruit.finder.removePool')}</Button></Popconfirm>)}
      <Button type="text" onClick={() => setSel([])}>{t('pages.recruit.finder.clearSel')}</Button>
    </Space>
  );
};
