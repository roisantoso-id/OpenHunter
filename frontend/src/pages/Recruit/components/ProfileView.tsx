/**
 * 简历抽取结果 / 候选人档案的展示——候选人抽屉「档案」页签与简历解析页共用这一个（§6.7.3：同一份数据一份组件）。
 * uncertain：解析时模型自报没把握的字段路径（"contacts.phones"、"experience[1].end"），对应区块/行标橙色。
 * 工作内容整段换行显示、不横向拖（「工作内容需要全部看完不要拉」）。
 * translate：顶部切「原文 / 中文 / English / Indonesia」，AI 异步翻译（后端 includes/recruit_translate.php，按原文缓存），
 *            只翻给人读的句子；译文仅供阅读，匹配 / 检索 / 推荐材料都用原文。
 */
import React, { useEffect, useState } from 'react';
import { Tag, Typography, Divider, Empty, Descriptions, Tooltip, Segmented, Space, Spin, Alert, Button } from 'antd';
import { TranslationOutlined } from '@ant-design/icons';
import { DetailBlock, DetailTable } from '@/components/DetailPanel';
import { recruitTranslate, recruitTranslation } from '@/services/api';
import { useIntl } from '@umijs/max';
import { showErr, NO_HSCROLL, type T, LangTags, PersonalTags, BenchTag } from '../common';

const { Text, Paragraph } = Typography;
const UNC_BG = '#fff7e6';

interface Props {
  p: any;
  t: T;
  yearsExp?: number | string | null;
  uncertain?: string[];
  /** 简历解析页：连同姓名/联系方式一起展示（候选人抽屉顶部已有，不重复） */
  showIdentity?: boolean;
  /** 给了就显示翻译切换：resume = 这份简历的抽取结果，candidate = 候选人档案 */
  translate?: { type: 'resume' | 'candidate'; id: number };
  /** 候选人档案：各段经历命中的企业库企业（exp_index → bench，后端 recruitCandidateBench） */
  bench?: Record<string, any>;
}

type Tr = { id?: number; status: 'generating' | 'ready' | 'failed'; content?: any; error?: string };

const ProfileView: React.FC<Props> = ({ p: pIn, t, yearsExp, uncertain = [], showIdentity, translate, bench }) => {
  const locale = useIntl().locale;
  const [tl, setTl] = useState<string>('orig');
  const [tr, setTr] = useState<Tr | null>(null);
  useEffect(() => { setTl('orig'); setTr(null); }, [translate?.type, translate?.id, pIn]);
  const pick = async (lang: string) => {
    setTl(lang);
    if (lang === 'orig' || !translate) return;
    setTr({ status: 'generating' });
    const r = await recruitTranslate(translate.type, translate.id, lang);
    if (!r?.success) { showErr(r, t); setTr({ status: 'failed', error: r?.errorMessage }); return; }
    setTr(r.data);
  };
  // 异步翻译：每 2 秒看一次，翻完 / 失败停
  useEffect(() => {
    if (tr?.status !== 'generating' || !tr.id) return undefined;
    const h = setInterval(async () => {
      const r = await recruitTranslation(tr.id!);
      if (r?.success && r.data.status !== 'generating') setTr(r.data);
    }, 2000);
    return () => clearInterval(h);
  }, [tr]);
  const p = (tl !== 'orig' && tr?.status === 'ready' && tr.content) || pIn || {};
  /** 路径命中：'experience' 命中 'experience[1].end'；'experience[1]' 只命中第 2 行 */
  const unc = (path: string) => uncertain.some((u) => u === path || u.startsWith(`${path}.`) || u.startsWith(`${path}[`));
  const mark = (path: string, node: React.ReactNode) => (unc(path)
    ? <Tooltip title={t('pages.recruit.resume.uncertainHint')}><span style={{ background: UNC_BG, outline: '1px dashed #fa8c16', padding: '0 2px' }}>{node}</span></Tooltip>
    : node);
  const rowUnc = (sec: string) => (_: any, i: number) => (unc(`${sec}[${i}]`) ? 'recruit-unc-row' : '');

  return (
    <>
      <style>{`.recruit-unc-row > td { background: ${UNC_BG} !important }`}</style>
      {!!translate && (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap', marginBottom: 10 }}>
          <Space size={8}>
            <TranslationOutlined style={{ color: '#1a2ad6' }} />
            <Segmented size="small" value={tl} onChange={(v) => pick(String(v))}
              options={[{ value: 'orig', label: t('pages.recruit.tr.orig') }, { value: 'zh', label: '中文' }, { value: 'en', label: 'English' }, { value: 'id', label: 'Indonesia' }]} />
          </Space>
          {tl !== 'orig' && tr?.status === 'generating' && <Space size={6}><Spin size="small" /><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.tr.working')}</Text></Space>}
          {tl !== 'orig' && tr?.status === 'ready' && <Tag color="blue">{t('pages.recruit.tr.note')}</Tag>}
        </div>
      )}
      {!!translate && tl !== 'orig' && tr?.status === 'failed' && (
        <Alert type="warning" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.tr.failed')}
          action={<Button size="small" onClick={() => pick(tl)}>{t('pages.recruit.tr.retry')}</Button>} />
      )}
      {showIdentity && (
        <DetailBlock column={2}>
          <Descriptions.Item label={t('pages.recruit.f.name')}>{mark('person.full_name', p.person?.full_name || '-')}</Descriptions.Item>
          <Descriptions.Item label={t('pages.recruit.f.birth')}>
            {mark('person.birth_date', p.person?.birth_date || '-')}{p.person?.gender ? ` · ${t(`pages.recruit.gender.${p.person.gender}`)}` : ''}
          </Descriptions.Item>
          <Descriptions.Item label={t('pages.recruit.f.phone')}>{mark('contacts.phones', (p.contacts?.phones || []).join(' / ') || '-')}</Descriptions.Item>
          <Descriptions.Item label={t('pages.recruit.f.email')}>{mark('contacts.emails', (p.contacts?.emails || []).join(' / ') || '-')}</Descriptions.Item>
          <Descriptions.Item label={t('pages.recruit.f.city')} span={2}>
            {mark('location', [p.location?.city, p.location?.province, p.location?.country].filter(Boolean).join(', ') || '-')}
            {!!p.contacts?.linkedin && <Text type="secondary"> · {p.contacts.linkedin}</Text>}
          </Descriptions.Item>
        </DetailBlock>
      )}
      {!!p.headline && <Paragraph style={{ marginTop: 8 }}><Text strong>{mark('headline', p.headline)}</Text></Paragraph>}
      <Divider orientation="left" plain>{mark('experience', t('pages.recruit.p.experience'))}</Divider>
      {/* 工作内容整段换行、不横向滚、不分页：DetailTable 默认 x=max-content 会把长描述撑成一行 */}
      <DetailTable rowKey={(_, i) => String(i)} dataSource={p.experience || []} rowClassName={rowUnc('experience')}
        {...NO_HSCROLL} pagination={false}
        locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
        columns={[
          { title: t('pages.recruit.p.titleCompany'), width: 220, render: (_: any, x: any, i: number) => (
              <><div><Text strong>{x.title || '-'}</Text></div><Text type="secondary">{x.company}</Text>
                {!!bench?.[i] && <div><BenchTag bench={bench[i]} t={t} locale={locale} /></div>}
                {!!x.company_industry && <div><Tag color="purple" style={{ fontSize: 11 }}>{t(`pages.recruit.industry.${x.company_industry}`)}</Tag></div>}</>) },
          { title: t('pages.recruit.p.period'), width: 125, render: (_: any, x: any) => (
              <>{`${x.start || '?'} ~ ${x.is_current ? t('pages.recruit.p.present') : (x.end || '?')}`}
                {!!x.employment_type && <div><Text type="secondary" style={{ fontSize: 12 }}>{t(`pages.recruit.emp.${x.employment_type}`)}</Text></div>}</>) },
          { title: t('pages.recruit.p.desc'), render: (_: any, x: any) => (
              <div style={{ fontSize: 12, lineHeight: 1.7, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{x.description || '-'}</div>) },
        ]} />
      <Divider orientation="left" plain>{mark('education', t('pages.recruit.p.education'))}</Divider>
      <DetailTable rowKey={(_, i) => String(i)} dataSource={p.education || []} rowClassName={rowUnc('education')}
        {...NO_HSCROLL} pagination={false}
        locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
        columns={[
          { title: t('pages.recruit.p.level'), width: 110, render: (_: any, e: any) => e.level && e.level !== 'other' ? t(`pages.recruit.edu.${e.level}`) : (e.level_raw || '-') },
          { title: t('pages.recruit.p.school'), render: (_: any, e: any) => <><div>{e.school || '-'}</div><Text type="secondary">{e.major}</Text></> },
          { title: t('pages.recruit.p.period'), width: 140, render: (_: any, e: any) => `${e.start || '?'} ~ ${e.end === 'present' ? t('pages.recruit.p.present') : (e.end || '?')}` },
          { title: 'GPA', width: 80, render: (_: any, e: any) => e.gpa ? `${e.gpa}${e.gpa_scale ? `/${e.gpa_scale}` : ''}` : '-' },
        ]} />
      <DetailBlock column={1}>
        {yearsExp !== undefined && (
          <Descriptions.Item label={t('pages.recruit.p.years')}>
            {yearsExp !== null && yearsExp !== '' ? t('pages.recruit.years', { n: yearsExp }) : '-'}
          </Descriptions.Item>
        )}
        <Descriptions.Item label={mark('skills', t('pages.recruit.p.skills'))}>
          {(p.skills || []).map((s: string) => <Tag key={s}>{s}</Tag>)}
          {(p.skills_en || []).length > 0 && (
            <div style={{ marginTop: 4 }}>
              <Tooltip title={t('pages.recruit.p.skillsEnHint')}><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.p.skillsEn')}：</Text></Tooltip>
              {p.skills_en.map((s: string) => <Tag key={s} bordered={false} style={{ fontSize: 11 }}>{s}</Tag>)}
            </div>
          )}
        </Descriptions.Item>
        <Descriptions.Item label={mark('certificates', t('pages.recruit.p.certs'))}>{(p.certificates || []).map((c: any, i: number) => <Tag key={i} color="geekblue">{c.name}{c.year ? ` (${c.year})` : ''}</Tag>)}</Descriptions.Item>
        <Descriptions.Item label={mark('languages', t('pages.recruit.p.languages'))}><LangTags langs={p.languages || []} resumeLang={p.resume_language} t={t} /></Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.p.personal')}><PersonalTags row={p} t={t} /></Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.p.industries')}>{(p.industries || []).map((x: string) => <Tag key={x} color="purple">{t(`pages.recruit.industry.${x}`)}</Tag>)}</Descriptions.Item>
        {(p.projects || []).length > 0 && (
          <Descriptions.Item label={mark('projects', t('pages.recruit.p.projects'))}>
            {p.projects.map((x: any, i: number) => (
              <div key={i} style={{ fontSize: 12 }}>
                <Text strong>{x.name}</Text>{x.role ? ` · ${x.role}` : ''}{x.start || x.end ? <Text type="secondary"> ({x.start || '?'} ~ {x.end || '?'})</Text> : ''}
                {!!x.description && <div style={{ color: '#666' }}>{x.description}</div>}
              </div>))}
          </Descriptions.Item>
        )}
        {(p.awards || []).length > 0 && (
          <Descriptions.Item label={t('pages.recruit.p.awards')}>{p.awards.map((a: any, i: number) => <Tag key={i} color="gold">{a.name}{a.year ? ` (${a.year})` : ''}</Tag>)}</Descriptions.Item>
        )}
        {(p.organizations || []).length > 0 && (
          <Descriptions.Item label={t('pages.recruit.p.organizations')}>{p.organizations.map((o: any, i: number) => <Tag key={i}>{o.name}{o.role ? ` · ${o.role}` : ''}</Tag>)}</Descriptions.Item>
        )}
        <Descriptions.Item label={mark('expected_salary', t('pages.recruit.p.salary'))}>{p.expected_salary?.text || '-'}</Descriptions.Item>
        <Descriptions.Item label={t('pages.recruit.p.notice')}>{p.notice_period_text || '-'}</Descriptions.Item>
        {!!p.extra && (
          <Descriptions.Item label={t('pages.recruit.p.extra')}><Text style={{ fontSize: 12, whiteSpace: 'pre-wrap' }}>{p.extra}</Text></Descriptions.Item>
        )}
      </DetailBlock>
    </>
  );
};

export default ProfileView;
