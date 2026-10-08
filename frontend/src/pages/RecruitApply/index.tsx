/**
 * 职位投递页 /apply/:token（「开放一个候选人的外链」→ 职位投递链接）。
 * 候选人免登录：看职位 → 填姓名 / WhatsApp / 邮箱 → 勾选数据授权 → 上传简历 → 提交。layout:false，没有任何 CRM 菜单。
 * 默认印尼语（候选人大多是印尼人），可切英文 / 中文；地址栏 ?lang=en 也行。
 * 后端只给职位标题 / 地点 / 类型 / 薪资 / 描述，不给客户公司名、归属人等内部信息（includes/recruit_apply.php）。
 */
import React, { useEffect, useMemo, useState } from 'react';
import { useParams, getIntl } from '@umijs/max';
import { Button, Card, Checkbox, Form, Input, Result, Segmented, Spin, Tag, Typography, Upload, message } from 'antd';
import { EnvironmentOutlined, InboxOutlined, WalletOutlined, ClockCircleOutlined } from '@ant-design/icons';
import { recruitApplyInfo, recruitApplySubmit } from '@/services/api';

const { Title, Text } = Typography;
const LOCALE: Record<string, string> = { id: 'id-ID', en: 'en-US', zh: 'zh-CN' };
const ACCEPT = '.pdf,.doc,.docx,.jpg,.jpeg,.png';

/**
 * JD 纯文本 → 有格式的段落（「这个很乱七八糟的，你需要给我有点格式」）：
 * 按空行分块；块首行以冒号结尾、或是短标题（如 Requirements / Kualifikasi）→ 小标题；
 * 以 - • * · ✓ 或「1.」「1)」开头的行、以及标题下面的各行 → 圆点列表；其余是普通段落。**粗体** 去掉星号。
 */
const HEAD_WORDS = /^(requirements?|qualifications?|responsibilit(y|ies)|job ?desc(ription)?|benefits?|kualifikasi|persyaratan|tanggung jawab|deskripsi pekerjaan|tunjangan|fasilitas|岗位职责|任职要求|职位描述|工作内容|福利)\b/i;
const BULLET = /^\s*(?:[-•*·✓✔▪►]|\d{1,2}[.)、])\s*/;
const clean = (x: string) => x.replace(/\*\*(.+?)\*\*/g, '$1').trim();
const JdText: React.FC<{ text: string }> = ({ text }) => {
  const blocks = String(text || '').replace(/\r/g, '').split(/\n\s*\n/).map((b) => b.split('\n').map((l) => l.trimEnd()).filter((l) => l.trim() !== '')).filter((b) => b.length);
  const isHead = (l: string) => { const c = clean(l); return (/[:：]$/.test(c) && c.length <= 80) || (HEAD_WORDS.test(c) && c.length <= 40); };
  return (
    <div className="jd">
      {blocks.map((b, i) => {
        const head = isHead(b[0]) ? clean(b[0]).replace(/[:：]$/, '') : '';
        const body = head ? b.slice(1) : b;
        const list = body.length > 1 || (body.length === 1 && BULLET.test(body[0])) || (head !== '' && body.length > 0);
        return (
          <div key={i} style={{ marginBottom: 14 }}>
            {head && <div style={{ fontWeight: 700, fontSize: 15, color: '#1f1f1f', margin: '4px 0 6px' }}>{head}</div>}
            {list ? (
              <ul style={{ margin: 0, paddingInlineStart: 20 }}>
                {body.map((l, j) => <li key={j} style={{ margin: '3px 0', lineHeight: 1.65 }}>{clean(l.replace(BULLET, ''))}</li>)}
              </ul>
            ) : body.map((l, j) => <p key={j} style={{ margin: '0 0 4px', lineHeight: 1.7 }}>{clean(l)}</p>)}
          </div>);
      })}
    </div>);
};

const RecruitApply: React.FC = () => {
  const { token = '' } = useParams<{ token: string }>();
  const [lang, setLang] = useState<string>(() => {
    const q = new URLSearchParams(window.location.search).get('lang') || '';
    return LOCALE[q] ? q : 'id';
  });
  // 按本页语言单独取文案，不用 setLocale：招聘专员在自己浏览器里预览链接时，不能把他 CRM 的界面语言也改成印尼语
  const intl = useMemo(() => getIntl(LOCALE[lang]), [lang]);
  const t = (id: string, v?: any) => intl.formatMessage({ id }, v);
  const [job, setJob] = useState<any>(null);
  const [invalid, setInvalid] = useState(false);
  const [loading, setLoading] = useState(true);
  const [file, setFile] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [done, setDone] = useState<'' | 'added' | 'duplicate'>('');
  const [form] = Form.useForm();
  const counted = React.useRef(false);   // 只有第一次打开算一次浏览，切语言重取不算
  const byLang = React.useRef<Record<string, any>>({});   // 浏览器里也缓存每种语言翻好的内容：切回来立刻显示、不再请求（后端本来就有缓存）

  useEffect(() => {
    let alive = true;
    setLoading(true);
    let timer: any = null;
    let tries = 0;
    const hit = byLang.current[lang];
    if (hit) { setJob(hit); setLoading(false); return () => { alive = false; }; }
    const load = (first: boolean) => recruitApplyInfo(token, lang, first).then((r: any) => {
      if (!alive) return;
      if (!r?.success) { setInvalid(true); return; }
      setJob(r.data);
      if (!r.data.translating) byLang.current[lang] = r.data;   // 译文到了（或本来就不用翻）才缓存；还在翻的不缓存，免得切回来卡在原文
      // 这个语言的译文还在路上：先显示原文，每 3 秒再取一次，最多约 1 分钟
      if (r.data.translating && tries++ < 20) timer = setTimeout(() => load(false), 3000);
    }).catch(() => alive && setInvalid(true)).finally(() => alive && setLoading(false));
    load(!counted.current);
    counted.current = true;
    return () => { alive = false; if (timer) clearTimeout(timer); };
  }, [token, lang]);

  const submit = async (v: any) => {
    if (!file) { message.error(t('pages.recruit.err.apply.fileRequired')); return; }
    setSending(true);
    try {
      const fd = new FormData();
      fd.append('token', token);
      fd.append('name', v.name || '');
      fd.append('phone', v.phone || '');
      fd.append('email', v.email || '');
      fd.append('consent', v.consent ? '1' : '');
      fd.append('website', v.website || '');   // 隐藏字段：人看不见
      fd.append('lang', lang);
      fd.append('file', file);
      const r: any = await recruitApplySubmit(fd);
      if (r?.success) setDone(r.data.status === 'duplicate' ? 'duplicate' : 'added');
      else if (r?.message_key === 'pages.recruit.err.linkInvalid') setInvalid(true);
      else message.error(r?.message_key ? t(r.message_key) : t('pages.recruit.err.apply.failed'));
    } catch { message.error(t('pages.recruit.err.apply.failed')); }
    finally { setSending(false); }
  };

  const shell = (children: React.ReactNode) => (
    <div style={{ minHeight: '100vh', background: 'linear-gradient(180deg,#eef2ff 0%,#f7f8fc 260px)', padding: '28px 14px 48px' }}>
      <div style={{ maxWidth: 720, margin: '0 auto' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <img src="/recruit/art/mark-64.jpg" alt="" style={{ width: 34, height: 34, borderRadius: 9 }} />
            <Text strong style={{ fontSize: 16 }}>{t('pages.recruit.apply.brand')}</Text>
          </div>
          <Segmented size="small" value={lang} onChange={(v) => setLang(String(v))}
            options={[{ value: 'id', label: 'Bahasa' }, { value: 'en', label: 'English' }, { value: 'zh', label: '中文' }]} />
        </div>
        {children}
      </div>
    </div>);

  if (loading && !job) return shell(<Card style={{ borderRadius: 16, textAlign: 'center', padding: 40 }}><Spin /></Card>);
  if (invalid) return shell(<Card style={{ borderRadius: 16 }}><Result status="404" title={t('pages.recruit.apply.invalidTitle')} subTitle={t('pages.recruit.apply.invalidDesc')} /></Card>);
  if (done) return shell(<Card style={{ borderRadius: 16 }}><Result status="success" title={t(done === 'duplicate' ? 'pages.recruit.apply.dupTitle' : 'pages.recruit.apply.okTitle')} subTitle={t('pages.recruit.apply.okDesc', { job: job?.title || '' })} /></Card>);

  return shell(
    <>
      <Card style={{ borderRadius: 16, marginBottom: 16 }}>
        <Title level={3} style={{ marginTop: 0, marginBottom: 8 }}>{job.title}</Title>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
          {!!job.location && <Tag icon={<EnvironmentOutlined />} color="blue">{job.location}</Tag>}
          {!!job.employment_type && <Tag icon={<ClockCircleOutlined />}>{t(`pages.recruit.emp.${job.employment_type}`)}</Tag>}
          {!!job.salary && <Tag icon={<WalletOutlined />} color="green">{job.salary}</Tag>}
          <Tag>{t('pages.recruit.apply.confidential')}</Tag>
        </div>
        {(job.translated || job.translating) && (
          <div style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 10 }}>{t(job.translating ? 'pages.recruit.apply.translating' : 'pages.recruit.apply.aiTranslated')}</div>)}
        {!!job.description && <div style={{ color: '#434343', wordBreak: 'break-word', fontSize: 14 }}><JdText text={job.description} /></div>}
      </Card>

      <Card style={{ borderRadius: 16 }} title={t('pages.recruit.apply.formTitle')}>
        <Form form={form} layout="vertical" onFinish={submit} requiredMark={false}>
          <Form.Item name="name" label={t('pages.recruit.apply.name')} rules={[{ required: true, message: t('pages.recruit.err.apply.nameRequired') }]}>
            <Input size="large" maxLength={100} placeholder={t('pages.recruit.apply.namePh')} />
          </Form.Item>
          <Form.Item name="phone" label={t('pages.recruit.apply.phone')} rules={[{ required: true, message: t('pages.recruit.err.apply.phoneInvalid') }]}>
            <Input size="large" maxLength={20} inputMode="tel" placeholder="08xx xxxx xxxx" />
          </Form.Item>
          <Form.Item name="email" label={t('pages.recruit.apply.email')} rules={[{ type: 'email', message: t('pages.recruit.err.apply.emailInvalid') }]}>
            <Input size="large" maxLength={191} inputMode="email" placeholder="nama@email.com" />
          </Form.Item>
          {/* 隐藏字段挡机器人：真人看不见也不会填 */}
          <Form.Item name="website" style={{ position: 'absolute', left: -9999, width: 1, height: 1, overflow: 'hidden' }} aria-hidden>
            <Input tabIndex={-1} autoComplete="off" />
          </Form.Item>
          <Form.Item label={t('pages.recruit.apply.file')} required>
            <Upload.Dragger accept={ACCEPT} maxCount={1} beforeUpload={(f) => {
              if (f.size > 20 * 1024 * 1024) { message.error(t('pages.recruit.err.apply.fileSize')); return Upload.LIST_IGNORE; }
              setFile(f); return false;
            }} onRemove={() => { setFile(null); }} fileList={file ? [{ uid: '1', name: file.name, status: 'done' } as any] : []}>
              <p className="ant-upload-drag-icon"><InboxOutlined /></p>
              <p className="ant-upload-text">{t('pages.recruit.apply.fileHint')}</p>
              <p className="ant-upload-hint">PDF · Word · JPG · PNG · ≤ 20MB</p>
            </Upload.Dragger>
          </Form.Item>
          <Form.Item name="consent" valuePropName="checked" rules={[{ validator: (_, v) => (v ? Promise.resolve() : Promise.reject(new Error(t('pages.recruit.err.apply.consentRequired')))) }]}>
            <Checkbox><span style={{ fontSize: 13 }}>{t('pages.recruit.apply.consent')}</span></Checkbox>
          </Form.Item>
          <Button type="primary" size="large" block htmlType="submit" loading={sending}>{t('pages.recruit.apply.submit')}</Button>
        </Form>
      </Card>
    </>);
};

export default RecruitApply;
