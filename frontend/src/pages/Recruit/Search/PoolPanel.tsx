/**
 * 人才池展开面板（「点击之后就能展开，在下面进行处理，需要筛选可以」）：
 * 人才池列表里点一行就在这一行下面展开，不跳页签——池的条件、成员 + 按条件实时检索的结果、再加筛选、勾选批量处理都在这里。
 * 要改池的条件点「在检索中编辑」回到检索页签（带着拆好的条件）。
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Button, Input, InputNumber, Select, Space, Typography } from 'antd';
import { EditOutlined, SearchOutlined } from '@ant-design/icons';
import { recruitSearch } from '@/services/api';
import { showErr, type T } from '../common';
import { BulkBar, CondChips, ResultTable, type Lang } from './parts';

const { Text } = Typography;

const PoolPanel: React.FC<{
  pool: any; meta: any; pools: any[]; t: T; lang: Lang; onOpen: (id: number) => void; onEdit: (p: any) => void; onChanged: () => void;
}> = ({ pool, meta, pools, t, lang, onOpen, onEdit, onChanged }) => {
  const [f, setF] = useState<Record<string, any>>(() => ({ ...(pool.filters || {}) }));
  const [res, setRes] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [sel, setSel] = useState<number[]>([]);
  const [text, setText] = useState('');
  const conds = useMemo(() => pool.conds || [], [pool]);   // 稳定引用，免得 load 每次重建导致反复请求

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, any> = { pool_id: pool.id };
      if (conds.length) { params.conds = JSON.stringify(conds); params.semantic_query = pool.semantic_query || pool.query_text || ''; }
      else if (pool.query_text) params.q = pool.query_text;
      if (pool.facet) params.facet = pool.facet;
      if (Number(pool.seed_id) > 0) params.seed = pool.seed_id;
      Object.entries(f).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') params[k] = v; });
      const r = await recruitSearch(params);
      if (r?.success) setRes(r); else showErr(r, t);
    } finally { setLoading(false); }
  }, [pool, conds, f, t]);
  useEffect(() => { load(); }, [load]);

  // 在结果里再筛：姓名 / 职位 / 公司 / 学校，前端过滤，不重新检索
  const rows = useMemo(() => {
    const k = text.trim().toLowerCase();
    const all = res?.data || [];
    return k ? all.filter((r: any) => [r.name, r.latest_title, r.latest_company, r.latest_school, r.city].some((x) => String(x || '').toLowerCase().includes(k))) : all;
  }, [res, text]);
  const set = (patch: Record<string, any>) => setF((p) => ({ ...p, ...patch }));

  return (
    <div style={{ padding: '4px 4px 8px', background: '#fafbff', borderRadius: 8 }}>
      <Space direction="vertical" size={10} style={{ width: '100%' }}>
        {!!pool.query_text && <Text type="secondary" style={{ fontSize: 12 }}>「{pool.query_text}」</Text>}
        {conds.length > 0 && <CondChips conds={conds} lang={lang} t={t} />}
        <Space wrap>
          <Input allowClear prefix={<SearchOutlined />} style={{ width: 220 }} placeholder={t('pages.recruit.finder.filterInResult')} value={text} onChange={(e) => setText(e.target.value)} />
          <Select allowClear style={{ width: 130 }} placeholder={t('pages.recruit.minEdu')} value={f.min_edu} onChange={(v) => set({ min_edu: v })}
            options={['sma', 'd3', 's1', 's2'].map((e) => ({ value: e, label: `≥ ${t(`pages.recruit.edu.${e}`)}` }))} />
          <Select allowClear showSearch optionFilterProp="label" style={{ width: 170 }} placeholder={t('pages.recruit.p.industries')} value={f.industry}
            onChange={(v) => set({ industry: v })} options={(meta?.enums?.industry || []).map((x: string) => ({ value: x, label: t(`pages.recruit.industry.${x}`) }))} />
          <InputNumber min={0} max={40} style={{ width: 150 }} placeholder={t('pages.recruit.finder.minYears')} addonAfter={t('pages.recruit.finder.yearsUnit')}
            value={f.min_years !== undefined && f.min_years !== null ? Number(f.min_years) : undefined} onChange={(v) => set({ min_years: v ?? undefined })} />
          <Select allowClear style={{ width: 140 }} placeholder={t('pages.recruit.col.status')} value={f.status} onChange={(v) => set({ status: v })}
            options={(meta?.enums?.status || []).filter((s: string) => s !== 'blacklisted').map((s: string) => ({ value: s, label: t(`pages.recruit.status.${s}`) }))} />
          {pool.can_edit && <Button icon={<EditOutlined />} onClick={() => onEdit(pool)}>{t('pages.recruit.finder.editInSearch')}</Button>}
        </Space>
        {res?.fallback && <Alert type="warning" showIcon message={t('pages.recruit.finder.fallback')} />}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8, minHeight: 32 }}>
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.finder.poolBanner', { name: pool.name, n: res?.members ?? 0 })}</Text>
          <BulkBar sel={sel} setSel={setSel} t={t} meta={meta} pools={pools} poolId={Number(pool.id)} onDone={() => { load(); onChanged(); }} />
        </div>
        <ResultTable rows={rows} loading={loading} res={res} conds={conds} lang={lang} t={t} sel={sel} setSel={setSel} onOpen={onOpen} />
      </Space>
    </div>
  );
};

export default PoolPanel;
