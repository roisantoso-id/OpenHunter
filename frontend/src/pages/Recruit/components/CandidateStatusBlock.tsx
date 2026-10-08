/**
 * 候选人现状（「跟进要记候选人现状」）：现薪 / 期望薪资 / 通知期 / 是否在职 / 意向 / 可到岗。
 * 人工维护，存独立列（不进 locked_fields、不触发 AI 重建）。空着时灰显简历里写的原话作参考。
 */
import React, { useState } from 'react';
import { Space, Tag, Button, InputNumber, Select, DatePicker, Typography, message } from 'antd';
import { EditOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { recruitUpdateCandidateStatus } from '@/services/api';
import { showErr, money, fmtDay, T } from '../common';

const { Text } = Typography;
const INTENT_COLOR: Record<string, string> = { hot: 'red', warm: 'orange', cold: 'blue' };

const CandidateStatusBlock: React.FC<{ data: any; meta: any; t: T; onSaved: () => void }> = ({ data, meta, t, onSaved }) => {
  const [editing, setEditing] = useState(false);
  const [f, setF] = useState<any>({});
  const [saving, setSaving] = useState(false);
  const ccy = data.salary_currency || 'IDR';
  const resumeExpect = data.profile?.expected_salary?.text || '';
  const resumeNotice = data.profile?.notice_period_text || '';
  const start = () => {
    setF({ current_salary: data.current_salary !== null && data.current_salary !== undefined ? Number(data.current_salary) : null,
      expected_salary_amt: data.expected_salary_amt !== null && data.expected_salary_amt !== undefined ? Number(data.expected_salary_amt) : null,
      salary_currency: ccy, notice_period_days: data.notice_period_days ?? null,
      is_employed: data.is_employed === null || data.is_employed === undefined ? '' : String(Number(data.is_employed)),
      intent: data.intent || '', availability_date: data.availability_date || '' });
    setEditing(true);
  };
  const save = async () => {
    setSaving(true);
    try {
      const r = await recruitUpdateCandidateStatus({ id: data.id, ...f, current_salary: f.current_salary ?? '', expected_salary_amt: f.expected_salary_amt ?? '',
        notice_period_days: f.notice_period_days ?? '' });
      if (r?.success) { message.success(t('pages.recruit.msg.saved')); setEditing(false); onSaved(); } else showErr(r, t);
    } finally { setSaving(false); }
  };
  if (editing) {
    return (
      <Space wrap size={8}>
        <Select size="small" style={{ width: 80 }} value={f.salary_currency} onChange={(v) => setF({ ...f, salary_currency: v })}
          options={(meta?.lc?.currency || ['IDR']).map((c: string) => ({ value: c, label: c }))} />
        <InputNumber size="small" style={{ width: 150 }} min={0} placeholder={t('pages.recruit.cs.current')} value={f.current_salary}
          onChange={(v) => setF({ ...f, current_salary: v })} formatter={(x) => (x ? `${x}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '')} />
        <InputNumber size="small" style={{ width: 150 }} min={0} placeholder={t('pages.recruit.cs.expected')} value={f.expected_salary_amt}
          onChange={(v) => setF({ ...f, expected_salary_amt: v })} formatter={(x) => (x ? `${x}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '')} />
        <InputNumber size="small" style={{ width: 130 }} min={0} max={365} placeholder={t('pages.recruit.cs.notice')} addonAfter={t('pages.recruit.terms.dayUnit')}
          value={f.notice_period_days} onChange={(v) => setF({ ...f, notice_period_days: v })} />
        <Select size="small" style={{ width: 110 }} value={f.is_employed} onChange={(v) => setF({ ...f, is_employed: v })}
          options={[{ value: '', label: t('pages.recruit.cs.unknown') }, { value: '1', label: t('pages.recruit.cs.employed') }, { value: '0', label: t('pages.recruit.cs.unemployed') }]} />
        <Select size="small" style={{ width: 110 }} value={f.intent} onChange={(v) => setF({ ...f, intent: v })}
          options={[{ value: '', label: t('pages.recruit.cs.intent') }, ...(meta?.lc?.intent || []).map((i: string) => ({ value: i, label: t(`pages.recruit.intent.${i}`) }))]} />
        <DatePicker size="small" placeholder={t('pages.recruit.cs.available')} value={f.availability_date ? dayjs(f.availability_date) : null}
          onChange={(v) => setF({ ...f, availability_date: v ? v.format('YYYY-MM-DD') : '' })} />
        <Button size="small" type="primary" loading={saving} onClick={save}>{t('pages.recruit.save')}</Button>
        <Button size="small" onClick={() => setEditing(false)}>{t('pages.recruit.cancel')}</Button>
      </Space>
    );
  }
  const has = (v: any) => v !== null && v !== undefined && v !== '';
  return (
    <Space wrap size={6}>
      <Text>{t('pages.recruit.cs.current')} {has(data.current_salary) ? money(data.current_salary, ccy) : <Text type="secondary">-</Text>}</Text>
      <Text>{t('pages.recruit.cs.expected')} {has(data.expected_salary_amt) ? money(data.expected_salary_amt, ccy)
        : resumeExpect ? <Text type="secondary" title={t('pages.recruit.cs.fromResume')}>「{String(resumeExpect)}」</Text> : <Text type="secondary">-</Text>}</Text>
      <Text>{t('pages.recruit.cs.notice')} {has(data.notice_period_days) ? t('pages.recruit.terms.days', { n: data.notice_period_days })
        : resumeNotice ? <Text type="secondary">「{String(resumeNotice)}」</Text> : <Text type="secondary">-</Text>}</Text>
      {has(data.is_employed) && <Tag style={{ marginInlineEnd: 0 }}>{t(Number(data.is_employed) ? 'pages.recruit.cs.employed' : 'pages.recruit.cs.unemployed')}</Tag>}
      {!!data.intent && <Tag color={INTENT_COLOR[data.intent]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.intent.${data.intent}`)}</Tag>}
      {!!data.availability_date && <Text>{t('pages.recruit.cs.available')} {fmtDay(data.availability_date)}</Text>}
      {meta?.lc_ready && <Button size="small" type="link" icon={<EditOutlined />} onClick={start}>{t('pages.recruit.edit')}</Button>}
    </Space>
  );
};

export default CandidateStatusBlock;
