/**
 * 招聘文案（「JD 放在编辑里谁看得到，放在职位下面点开就能直接复制；工作台也能直接复制」）。
 * 项目抽屉的职位卡片、人力工作台「在招职位」共用这一个组件：
 *   印尼语 / 英语 / 中文 三份；没有就「生成文案」（AI 按职位信息 + 要求写，客户项目不写客户名），生成完自动存；
 *   可「修改」后保存；「复制文案」时末尾附上所选投递地址——简历投到这个地址会自动归到对应招聘专员名下。
 *   「已发布到」：勾选发了哪些平台（LinkedIn / JobStreet / Instagram…），记谁、什么时候发的。
 * 后端：recruitJobPosting（生成，只传 id 时从库里取职位）/ recruitJobPostingSave（存某个语言，不动职位其它字段）/ recruitJobPostedSet。
 */
import React, { useEffect, useState } from 'react';
import { Button, Input, Segmented, Select, Space, Tag, Tooltip, Typography, message } from 'antd';
import { CopyOutlined, EditOutlined, ThunderboltOutlined } from '@ant-design/icons';
import { recruitJobPosting, recruitJobPostingSave, recruitJobPostedSet } from '@/services/api';
import { showErr, type T } from '../common';

const { Text } = Typography;
type Lang = 'id' | 'en' | 'zh';
/** 与后端 RECRUIT_POST_PLATFORMS 同序 */
export const POST_PLATFORMS = ['linkedin', 'jobstreet', 'glints', 'kalibrr', 'indeed', 'instagram', 'facebook', 'tiktok', 'other'] as const;
export type Posted = Record<string, { at: string; by_name: string }>;

interface Props {
  t: T;
  job: { id: number; posting?: Record<string, string>; posted?: Posted };
  /** 可选的投递地址（{address, user_name?}）；defaultApply 没给就用第一个 */
  addresses: { address: string; user_name?: string }[];
  defaultApply?: string;
  onSaved?: (posting: Record<string, string>) => void;
  onPosted?: (posted: Posted) => void;
}

const JobPostingBox: React.FC<Props> = ({ t, job, addresses, defaultApply, onSaved, onPosted }) => {
  const [lang, setLang] = useState<Lang>('id');
  const [posting, setPosting] = useState<Record<string, string>>({ ...(job.posting || {}) });
  const [draft, setDraft] = useState<string | null>(null);   // 非 null = 正在改
  const [busy, setBusy] = useState<'gen' | 'save' | null>(null);
  const [applyTo, setApplyTo] = useState<string | undefined>(defaultApply || addresses[0]?.address);
  const text = posting[lang] || '';
  const [posted, setPosted] = useState<Posted>({ ...(job.posted || {}) });
  // 父组件换了职位 / 重新拉了数据：本地状态跟着刷新（否则显示的还是上一个职位的文案）
  const postingKey = JSON.stringify(job.posting || {});
  const postedKey = JSON.stringify(job.posted || {});
  useEffect(() => { setPosting({ ...(job.posting || {}) }); setPosted({ ...(job.posted || {}) }); setDraft(null); }, [job.id, postingKey, postedKey]);   // eslint-disable-line react-hooks/exhaustive-deps
  const togglePosted = async (pf: string, on: boolean) => {
    const r = await recruitJobPostedSet(job.id, pf, on);
    if (!r?.success) { showErr(r, t); return; }
    setPosted(r.data.posted || {}); onPosted?.(r.data.posted || {});
  };

  const store = async (value: string) => {
    const r = await recruitJobPostingSave(job.id, lang, value);
    if (!r?.success) { showErr(r, t); return false; }
    setPosting(r.data.posting || {}); onSaved?.(r.data.posting || {});
    return true;
  };
  const gen = async () => {
    setBusy('gen');
    try {
      const r = await recruitJobPosting({ id: job.id, lang });
      // 生成完直接存（「生成之后就保存就行了」），不用再点保存
      if (r?.success) { if (await store(r.data.text)) { setDraft(null); message.success(t('pages.recruit.posting.genSaved')); } } else showErr(r, t);
    } finally { setBusy(null); }
  };
  const save = async () => {
    if (draft === null) return;
    setBusy('save');
    try { if (await store(draft)) { setDraft(null); message.success(t('pages.recruit.posting.saved')); } } finally { setBusy(null); }
  };
  const copy = () => {
    const tail = applyTo ? `\n\n${t(`pages.recruit.posting.apply.${lang}`, { email: applyTo })}` : '';
    navigator.clipboard.writeText(text + tail).then(() => message.success(t('pages.recruit.copied')));
  };

  return (
    <div onClick={(e) => e.stopPropagation()}>
      <Space wrap style={{ marginBottom: 8 }}>
        <Segmented size="small" value={lang} onChange={(v) => { setLang(v as Lang); setDraft(null); }}
          options={(['id', 'en', 'zh'] as const).map((l) => ({ value: l, label: `${t(`pages.recruit.posting.lang.${l}`)}${posting[l] ? ' ✓' : ''}` }))} />
        <Button size="small" icon={<ThunderboltOutlined />} loading={busy === 'gen'} disabled={busy === 'save'} onClick={gen}>
          {text ? t('pages.recruit.posting.regen') : t('pages.recruit.posting.gen')}
        </Button>
        {!!text && draft === null && <Button size="small" icon={<EditOutlined />} onClick={() => setDraft(text)}>{t('pages.recruit.posting.edit')}</Button>}
      </Space>
      {draft !== null ? (
        <>
          <Input.TextArea autoSize={{ minRows: 8, maxRows: 24 }} maxLength={6000} value={draft} onChange={(e) => setDraft(e.target.value)} />
          <Space style={{ marginTop: 8 }}>
            <Button size="small" type="primary" loading={busy === 'save'} onClick={save}>{t('pages.recruit.posting.save')}</Button>
            <Button size="small" onClick={() => setDraft(null)}>{t('pages.recruit.cancel')}</Button>
          </Space>
        </>
      ) : text ? (
        <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontSize: 13, lineHeight: 1.7, background: '#fafafa',
          border: '1px solid #f0f0f0', borderRadius: 8, padding: '10px 12px' }}>{text}</div>
      ) : (
        <Text type="secondary">{t('pages.recruit.posting.empty', { lang: t(`pages.recruit.posting.lang.${lang}`) })}</Text>
      )}
      {!!text && draft === null && (
        <Space wrap style={{ marginTop: 8 }}>
          <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.posting.applyTo')}</Text>
          <Select size="small" allowClear style={{ width: 340 }} value={applyTo} onChange={setApplyTo}
            options={addresses.map((a) => ({ value: a.address, label: a.user_name ? `${a.address} · ${a.user_name}` : a.address }))} />
          <Button size="small" type="primary" icon={<CopyOutlined />} onClick={copy}>{t('pages.recruit.posting.copy')}</Button>
        </Space>
      )}
      <div style={{ marginTop: 10 }}>
        <Text type="secondary" style={{ fontSize: 12, marginRight: 8 }}>{t('pages.recruit.posting.postedTo')}</Text>
        {POST_PLATFORMS.map((pf) => {
          const p = posted[pf];
          const tag = (
            <Tag.CheckableTag key={pf} checked={!!p} onChange={(on) => togglePosted(pf, on)}
              style={{ border: `1px solid ${p ? '#1a2ad6' : '#d9d9d9'}`, marginBottom: 4 }}>
              {t(`pages.recruit.platform.${pf}`)}{p ? ' ✓' : ''}
            </Tag.CheckableTag>
          );
          return p ? <Tooltip key={pf} title={t('pages.recruit.posting.postedBy', { name: p.by_name, at: String(p.at).slice(0, 16) })}>{tag}</Tooltip> : tag;
        })}
      </div>
    </div>
  );
};

export default JobPostingBox;
