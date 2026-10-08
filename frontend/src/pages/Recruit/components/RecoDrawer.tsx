/**
 * 推荐材料：一个「候选人 × 职位」按语言各一份——推荐信 + 推荐版简历，公司抬头 + logo。
 *   - 中文 / English / Indonesia 切换；没有就「AI 生成」（异步，页面每 3 秒查一次）
 *   - 预览 / 编辑切换：编辑只改给客户看的措辞（候选人档案的纠错在「修正档案」），**…** 标匹配点
 *   - 导出 PDF（预览 DOM → html2pdf，与报价单同一做法）/ Word（后端拼 docx，同一份抬头文案）
 * 从候选人抽屉的匹配页签打开，叠在候选人抽屉上（抽屉叠抽屉，不用 Modal，§6.3）。
 * 推荐信按模板编辑（2026-09-24）：顶部选模板；按块逐段改——AI 段落可「AI 改写」（按要求重写一段，只用档案事实）、
 * 固定文字可单封微调 / 恢复模板原文、手填项必填的没填不许导出；档案字段在「推荐简历」里改。
 * 「标记已发送」后锁定（记谁、何时、发给谁），recruit_admin 可解锁。模板管理在 系统设置 · 招聘设置 · 推荐信模板。
 */
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Drawer, Segmented, Button, Space, Alert, Empty, Spin, Form, Input, Select, Row, Col, Divider, Popconfirm, Popover, Tag, Typography, Card, message } from 'antd';
import { ThunderboltOutlined, EditOutlined, FilePdfOutlined, FileWordOutlined, PlusOutlined, MinusCircleOutlined, ReloadOutlined,
  RobotOutlined, SendOutlined, LockOutlined, UnlockOutlined, UndoOutlined } from '@ant-design/icons';
import { useIntl } from '@umijs/max';
import dayjs from 'dayjs';
import { recruitRecoGet, recruitRecoGenerate, recruitRecoSave, recruitRecoDocx, recruitRecoRewrite, recruitRecoMarkSent, recruitRecoUnlock,
  recruitRecoSetClient, recruitSearchClients } from '@/services/api';
import { showErr, fmtMin } from '../common';
import RecoDoc, { missingManual } from './RecoDoc';
import DoodleEmpty from './DoodleEmpty';

const { Text } = Typography;
type Lang = 'zh' | 'en' | 'id';
type Kind = 'letter' | 'resume';

/** 表单 ↔ 内容：经历 bullets 在表单里是「一行一条」的文本 */
const toForm = (c: any, fallbackTpl?: any) => ({
  ...c,
  tpl: c?.tpl?.blocks?.length ? c.tpl : fallbackTpl,   // 模板系统前的旧信没快照：编辑时套当前默认模板
  manual: c?.manual || {}, fixed: c?.fixed || {},
  resume: { ...(c?.resume || {}), experience: (c?.resume?.experience || []).map((x: any) => ({ ...x, bullets: (x.bullets || []).join('\n') })) },
});
const fromForm = (v: any) => ({
  ...v,
  resume: { ...(v?.resume || {}), experience: (v?.resume?.experience || []).map((x: any) => ({ ...x, bullets: String(x.bullets || '').split('\n').map((s) => s.trim()).filter(Boolean) })) },
});

const RecoDrawer: React.FC<{ open: boolean; candidateId: number; jobId: number; onClose: () => void }> = ({ open, candidateId, jobId, onClose }) => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [lang, setLang] = useState<Lang>(intl.locale === 'id-ID' ? 'id' : intl.locale === 'en-US' ? 'en' : 'zh');
  const [kind, setKind] = useState<Kind>('letter');
  const [data, setData] = useState<any>(null);
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [form] = Form.useForm();
  const docRef = useRef<HTMLDivElement>(null);
  const [rewrite, setRewrite] = useState<{ path: any[]; ask: string } | null>(null);
  const [rewriting, setRewriting] = useState(false);
  const [sentNote, setSentNote] = useState('');
  // 推荐给哪个客户（「生成推荐报告需要选择客户」）：默认项目的客户；抬头「致」写它；标记已发送时阶段变「已推给客户」
  const [clientId, setClientId] = useState<number | undefined>();
  const [clientOpts, setClientOpts] = useState<{ value: number; label: string }[]>([]);
  const searchClients = async (kw: string) => {
    const r = await recruitSearchClients('customer', kw);
    if (r?.success) setClientOpts((r.data || []).map((c: any) => ({ value: Number(c.id), label: c.group_name })));
  };

  const load = useCallback(async () => {
    const r = await recruitRecoGet(candidateId, jobId);
    if (r?.success) setData(r.data); else showErr(r, t);
  }, [candidateId, jobId, t]);
  useEffect(() => { if (open) { setData(null); setEditing(false); load(); } }, [open, load]);

  const item = data?.items?.[lang];
  const labels = data?.labels?.[lang] || {};
  // 当前语言这份信选的客户；没有就用项目的客户
  useEffect(() => {
    if (!data) return;
    const id = Number(item?.client_customer_id || 0) || Number(data.default_client?.id || 0);
    const name = Number(item?.client_customer_id || 0) ? item.client_name : data.default_client?.group_name;
    setClientId(id || undefined);
    setClientOpts(id ? [{ value: id, label: name || `#${id}` }] : []);
  }, [data, item?.client_customer_id, item?.client_name]);
  const clientName = clientOpts.find((o) => o.value === clientId)?.label || '';
  const tplList: any[] = data?.templates?.[lang] || [];
  const defaultTpl = tplList.find((x) => x.is_default) || tplList[tplList.length - 1];
  const snap = (x: any) => (x ? { id: x.id, name: x.name, title: x.title, blocks: x.blocks } : undefined);
  const locked = !!item?.sent_at;
  // 必填手填项没填：导出 PDF / Word、标记已发送都拦（Word 与标记已发送后端也会再拦一次）
  const blockIfMissing = () => {
    const miss = missingManual(item?.content?.tpl ? item.content : { ...item?.content, tpl: snap(defaultTpl) });
    if (miss.length) { message.error(t('pages.recruit.err.recoManualMissing', { fields: miss.join('、') }), 6); return true; }
    return false;
  };
  // 生成中：每 3 秒查一次
  useEffect(() => {
    if (!open || item?.status !== 'generating') return undefined;
    const h = setInterval(load, 3000);
    return () => clearInterval(h);
  }, [open, item?.status, load]);

  const generate = async () => {
    if (data?.client_required && !clientId) { message.error(t('pages.recruit.err.recoClientRequired')); return; }
    setBusy(true);
    try {
      const r = await recruitRecoGenerate({ candidate_id: candidateId, job_id: jobId, lang, customer_id: clientId });
      if (r?.success) { setEditing(false); await load(); } else showErr(r, t);
    } finally { setBusy(false); }
  };
  const startEdit = () => { form.setFieldsValue(toForm(item.content, snap(defaultTpl))); setEditing(true); };
  /** 换模板：换快照；手填 / 固定微调按 key 保留（新模板里没有的键保存时后端会丢） */
  const switchTpl = (id: number) => {
    const tp = tplList.find((x) => Number(x.id) === id);
    if (tp) form.setFieldValue('tpl', snap(tp));
  };
  /** AI 改写一段：用表单里当前的文字 + 要求，拿回新文字直接填进表单（不落库，满意再保存） */
  const doRewrite = async () => {
    if (!rewrite?.ask.trim()) return;
    const text = String(form.getFieldValue(rewrite.path) || '');
    if (!text.trim()) { message.warning(t('pages.recruit.reco.rewriteEmpty')); return; }
    setRewriting(true);
    try {
      const r = await recruitRecoRewrite({ id: item.id, text, instruction: rewrite.ask });
      if (!r?.success) { showErr(r, t); return; }
      form.setFieldValue(rewrite.path, r.data.text);
      setRewrite(null);
      message.success(t('pages.recruit.reco.rewritten'));
    } finally { setRewriting(false); }
  };
  /** 已生成的信换客户：后端改抬头「致」（没发送前才能换） */
  const changeClient = async (id: number) => {
    setClientId(id);
    if (!item?.id || item.status !== 'ready' || item.sent_at) return;
    const r = await recruitRecoSetClient(item.id, id);
    if (r?.success) { message.success(t('pages.recruit.reco.clientChanged')); await load(); } else showErr(r, t);
  };
  const markSent = async () => {
    if (blockIfMissing()) return;
    const r = await recruitRecoMarkSent(item.id, sentNote);
    if (r?.success) { message.success(t('pages.recruit.reco.sentOk')); setSentNote(''); await load(); } else showErr(r, t);
  };
  const unlock = async () => {
    const r = await recruitRecoUnlock(item.id);
    if (r?.success) await load(); else showErr(r, t);
  };
  const save = async () => {
    setBusy(true);
    try {
      const r = await recruitRecoSave({ id: item.id, content: fromForm(form.getFieldsValue(true)) });
      if (r?.success) { message.success(t('pages.recruit.msg.saved')); setEditing(false); await load(); } else showErr(r, t);
    } finally { setBusy(false); }
  };
  const fileBase = `${kind === 'letter' ? labels.letter_title : labels.resume_title}_${item?.content?.resume?.name || data?.meta?.candidate_name || ''}_${data?.meta?.position || ''}`
    .replace(/[\\/:*?"<>|]+/g, '-');   // 职位名常带 /
  const exportPdf = async () => {
    if (!docRef.current) return;
    if (kind === 'letter' && blockIfMissing()) return;
    setBusy(true);
    try {
      // @ts-ignore 无类型声明（报价单同样用法）
      const { default: html2pdf } = await import('html2pdf.js');
      await html2pdf().set({
        margin: 0, filename: `${fileBase}.pdf`, image: { type: 'jpeg', quality: 0.95 },
        html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff', logging: false },
        jsPDF: { unit: 'px', format: [794, 1123], orientation: 'portrait', hotfixes: ['px_scaling'] },
        pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', 'li'] },
      }).from(docRef.current).save();
    } catch (e: any) {
      message.error(t('pages.recruit.reco.exportFailed'));
    } finally { setBusy(false); }
  };
  const exportWord = async () => {
    setBusy(true);
    try {
      const blob = await recruitRecoDocx(item.id, kind);
      if (blob.type.includes('json')) {
        const e = JSON.parse(await blob.text());
        if (e?.message_key === 'pages.recruit.err.recoManualMissing') message.error(t(e.message_key, { fields: (e.fields || []).join('、') }), 6);
        else showErr(e, t);
        return;
      }
      const u = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = u; a.download = `${fileBase}.docx`; a.click();
      setTimeout(() => URL.revokeObjectURL(u), 1000);
    } catch { message.error(t('pages.recruit.reco.exportFailed')); } finally { setBusy(false); }
  };

  const del = (remove: (i: number) => void, i: number) => <MinusCircleOutlined style={{ color: '#cf1322', marginTop: 8 }} onClick={() => remove(i)} />;
  const listOf = (name: any[], fields: [string, number, string][], addLabel: string, rows = 1) => (
    <Form.List name={name}>{(fs, { add, remove }) => (
      <>
        {fs.map(({ key, name: n }) => (
          <Row key={key} gutter={8}>
            {fields.map(([f, span, ph]) => (
              <Col key={f} span={span}>
                <Form.Item name={[n, f]}>{rows > 1 && f !== fields[0][0] ? <Input.TextArea rows={rows} placeholder={ph} /> : <Input placeholder={ph} />}</Form.Item>
              </Col>))}
            <Col span={1}>{del(remove, n)}</Col>
          </Row>
        ))}
        <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add({})} style={{ marginBottom: 12 }}>{addLabel}</Button>
      </>
    )}</Form.List>
  );
  const tags = (name: any[], label: string) => <Form.Item name={name} label={label}><Select mode="tags" tokenSeparators={[',', '，']} /></Form.Item>;

  const editor = (
    <Form form={form} layout="vertical" size="small">
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.reco.markHint')} />
      {kind === 'letter' ? (
        <>
          <Row gutter={16}>
            <Col span={12}>
              <Form.Item label={t('pages.recruit.tpl.pick')} extra={t('pages.recruit.tpl.pickHint')}>
                <Form.Item noStyle shouldUpdate>{() => (
                  <Select value={Number(form.getFieldValue(['tpl', 'id']) ?? defaultTpl?.id ?? 0)} onChange={switchTpl}
                    options={tplList.map((x) => ({ value: Number(x.id), label: `${x.name}${x.is_default ? `（${t('pages.recruit.tpl.default')}）` : ''}` }))} />)}
                </Form.Item>
              </Form.Item>
            </Col>
            <Col span={12}><Form.Item name={['letter', 'to']} label={labels.to}><Input /></Form.Item></Col>
          </Row>
          {/* 按模板逐块编辑：每块一张卡，标出类型；AI 段落可改写，固定文字可恢复模板原文 */}
          <Form.Item noStyle shouldUpdate={(a, b) => a.tpl !== b.tpl}>{() => (form.getFieldValue(['tpl', 'blocks']) || []).map((b: any) => {
            const typeTag = <Tag color={({ ai: 'blue', fixed: 'default', manual: 'orange', field: 'purple' } as any)[b.type]}>{t(`pages.recruit.tpl.type.${b.type}`)}</Tag>;
            const title = b.title || (b.type === 'ai' ? t(`pages.recruit.tpl.slot.${b.slot}`) : b.type === 'field' ? t(`pages.recruit.tpl.field.${b.source}`) : '');
            const rewriteBtn = (path: any[]) => (
              <Popover trigger="click" open={!!rewrite && JSON.stringify(rewrite.path) === JSON.stringify(path)}
                onOpenChange={(o) => setRewrite(o ? { path, ask: '' } : null)}
                content={(
                  <div style={{ width: 340 }}>
                    <Space size={[4, 4]} wrap style={{ marginBottom: 6 }}>
                      {['concise', 'formal', 'highlight', 'longer'].map((k) => (
                        <Tag key={k} style={{ cursor: 'pointer' }} onClick={() => setRewrite((r) => (r ? { ...r, ask: t(`pages.recruit.reco.ask.${k}`) } : r))}>
                          {t(`pages.recruit.reco.ask.${k}`)}</Tag>))}
                    </Space>
                    <Input.TextArea rows={2} value={rewrite?.ask} placeholder={t('pages.recruit.reco.askPh')}
                      onChange={(e) => setRewrite((r) => (r ? { ...r, ask: e.target.value } : r))} />
                    <div style={{ textAlign: 'right', marginTop: 6 }}>
                      <Button size="small" type="primary" icon={<RobotOutlined />} loading={rewriting} onClick={doRewrite}>{t('pages.recruit.reco.rewrite')}</Button>
                    </div>
                  </div>)}>
                <Button size="small" type="link" icon={<RobotOutlined />}>{t('pages.recruit.reco.rewrite')}</Button>
              </Popover>);
            let body: React.ReactNode = null; let extra: React.ReactNode = null;
            if (b.type === 'fixed') {
              body = <Form.Item name={['fixed', b.key]} initialValue={b.text} style={{ marginBottom: 0 }}><Input.TextArea autoSize={{ minRows: 2 }} /></Form.Item>;
              extra = <Button size="small" type="link" icon={<UndoOutlined />} onClick={() => form.setFieldValue(['fixed', b.key], b.text)}>{t('pages.recruit.tpl.restore')}</Button>;
            } else if (b.type === 'manual') {
              body = (
                <Form.Item name={['manual', b.key]} style={{ marginBottom: 0 }} extra={b.hint}
                  rules={b.required ? [{ required: true, whitespace: true, message: t('pages.recruit.tpl.requiredMsg') }] : []}>
                  <Input.TextArea autoSize={{ minRows: 2 }} status={b.required && !String(form.getFieldValue(['manual', b.key]) || '').trim() ? 'warning' : undefined} />
                </Form.Item>);
            } else if (b.type === 'field') {
              body = <Text type="secondary" style={{ fontSize: 12 }}>{t(b.source === 'signature' ? 'pages.recruit.tpl.fromAccount' : 'pages.recruit.tpl.fromResume')}</Text>;
            } else if (b.slot === 'match') {
              body = (
                <Form.List name={['letter', 'match']}>{(fs, { add, remove }) => (
                  <>
                    {fs.map(({ key, name: n }) => (
                      <Row key={key} gutter={8}>
                        <Col span={7}><Form.Item name={[n, 'requirement']}><Input.TextArea autoSize placeholder={labels.req} /></Form.Item></Col>
                        <Col span={5}><Form.Item name={[n, 'met']}>
                          <Select options={['yes', 'partial', 'no', 'unknown'].map((m) => ({ value: m, label: labels.met?.[m] || m }))} /></Form.Item></Col>
                        <Col span={11}><Form.Item name={[n, 'evidence']}><Input.TextArea autoSize placeholder={labels.evidence} /></Form.Item></Col>
                        <Col span={1}>{del(remove, n)}</Col>
                      </Row>))}
                    <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add({ met: 'unknown' })}>{t('pages.recruit.pe.add')}</Button>
                  </>
                )}</Form.List>);
            } else if (b.slot === 'paragraphs') {
              body = (
                <Form.List name={['letter', 'paragraphs']}>{(fs, { add, remove }) => (
                  <>
                    {fs.map(({ key, name: n }) => (
                      <Row key={key} gutter={8} align="top">
                        <Col span={20}><Form.Item name={n}><Input.TextArea autoSize={{ minRows: 2 }} /></Form.Item></Col>
                        <Col span={3}>{rewriteBtn(['letter', 'paragraphs', n])}</Col>
                        <Col span={1}>{del(remove, n)}</Col>
                      </Row>))}
                    <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add('')}>{t('pages.recruit.pe.add')}</Button>
                  </>
                )}</Form.List>);
            } else {
              body = <Form.Item name={['letter', b.slot]} style={{ marginBottom: 0 }}><Input.TextArea autoSize={{ minRows: b.slot === 'intro' ? 3 : 1 }} /></Form.Item>;
              extra = rewriteBtn(['letter', b.slot]);
            }
            return (
              <Card key={b.key} size="small" style={{ marginBottom: 8, borderColor: b.type === 'manual' && b.required ? '#ffd591' : undefined }}
                title={<Space size={6}>{typeTag}<span>{title}</span>{b.type === 'manual' && b.required && <Text type="danger">*</Text>}</Space>} extra={extra}>
                {body}
              </Card>);
          })}</Form.Item>
        </>
      ) : (
        <>
          <Row gutter={16}>
            <Col span={8}><Form.Item name={['resume', 'name']} label={t('pages.recruit.f.name')}><Input /></Form.Item></Col>
            <Col span={10}><Form.Item name={['resume', 'headline']} label={t('pages.recruit.pe.headline')}><Input /></Form.Item></Col>
            <Col span={6}><Form.Item name={['resume', 'location']} label={t('pages.recruit.f.city')}><Input /></Form.Item></Col>
          </Row>
          <Form.Item name={['resume', 'summary']} label={labels.summary}><Input.TextArea rows={3} /></Form.Item>
          <Divider orientation="left" plain>{labels.highlights}</Divider>
          {listOf(['resume', 'highlights'], [['title', 7, labels.req], ['text', 16, labels.evidence]], t('pages.recruit.pe.add'))}
          <Divider orientation="left" plain>{labels.experience}</Divider>
          <Form.List name={['resume', 'experience']}>{(fs, { add, remove }) => (
            <>
              {fs.map(({ key, name: n }) => (
                <div key={key} style={{ borderBottom: '1px dashed #e8e8e8', marginBottom: 8 }}>
                  <Row gutter={8}>
                    <Col span={8}><Form.Item name={[n, 'title']}><Input placeholder={t('pages.recruit.pe.title')} /></Form.Item></Col>
                    <Col span={9}><Form.Item name={[n, 'company']}><Input placeholder={t('pages.recruit.pe.company')} /></Form.Item></Col>
                    <Col span={6}><Form.Item name={[n, 'period']}><Input placeholder={t('pages.recruit.p.period')} /></Form.Item></Col>
                    <Col span={1}>{del(remove, n)}</Col>
                  </Row>
                  <Form.Item name={[n, 'bullets']} extra={t('pages.recruit.reco.bulletsHint')}><Input.TextArea rows={3} /></Form.Item>
                </div>))}
              <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add({ bullets: '' })} style={{ marginBottom: 12 }}>{t('pages.recruit.pe.addExp')}</Button>
            </>
          )}</Form.List>
          <Divider orientation="left" plain>{labels.projects}</Divider>
          {listOf(['resume', 'projects'], [['name', 7, t('pages.recruit.pe.name')], ['period', 4, t('pages.recruit.p.period')], ['text', 12, t('pages.recruit.p.desc')]], t('pages.recruit.pe.add'))}
          <Divider orientation="left" plain>{labels.education}</Divider>
          {listOf(['resume', 'education'], [['school', 8, t('pages.recruit.p.school')], ['degree', 4, t('pages.recruit.p.level')], ['major', 6, t('pages.recruit.pe.major')], ['period', 5, t('pages.recruit.p.period')]], t('pages.recruit.pe.addEdu'))}
          <Row gutter={16}>
            <Col span={8}>{tags(['resume', 'skills'], labels.skills)}</Col>
            <Col span={8}>{tags(['resume', 'certificates'], labels.certs)}</Col>
            <Col span={8}>{tags(['resume', 'languages'], labels.langs)}</Col>
          </Row>
        </>
      )}
    </Form>
  );

  const width = Math.min(1100, (typeof window !== 'undefined' ? window.innerWidth : 1100) - 40);
  const ready = item?.status === 'ready' && !!item?.content;

  return (
    <Drawer open={open} onClose={onClose} width={width} destroyOnClose
      title={data ? t('pages.recruit.reco.title', { name: data.meta.candidate_name, job: data.meta.position }) : t('pages.recruit.reco.titleShort')}
      extra={ready && (
        <Space>
          {editing
            ? <><Button onClick={() => setEditing(false)}>{t('pages.recruit.cancel')}</Button><Button type="primary" loading={busy} onClick={save}>{t('pages.recruit.save')}</Button></>
            : <>
                {!locked && <Button icon={<EditOutlined />} onClick={startEdit}>{t('pages.recruit.reco.edit')}</Button>}
                <Button icon={<FilePdfOutlined />} loading={busy} onClick={exportPdf}>PDF</Button>
                <Button icon={<FileWordOutlined />} loading={busy} onClick={exportWord}>Word</Button>
                {/* 标记已发送：记谁、何时、发给谁，之后锁定——这是「人是我们推荐的」的凭据 */}
                {!locked && (
                  <Popconfirm title={clientName ? t('pages.recruit.reco.markSentTo', { client: clientName }) : t('pages.recruit.reco.markSent')}
                    okText={t('pages.recruit.reco.markSentOk')} onConfirm={markSent}
                    description={<Input style={{ width: 260 }} value={sentNote} onChange={(e) => setSentNote(e.target.value)} placeholder={t('pages.recruit.reco.sentNotePh')} />}>
                    <Button type="primary" icon={<SendOutlined />}>{t('pages.recruit.reco.markSent')}</Button>
                  </Popconfirm>)}
                {locked && data.can_admin && (
                  <Popconfirm title={t('pages.recruit.reco.unlockConfirm')} onConfirm={unlock}>
                    <Button icon={<UnlockOutlined />}>{t('pages.recruit.reco.unlock')}</Button>
                  </Popconfirm>)}
              </>}
        </Space>
      )}>
      {!data ? <Spin style={{ display: 'block', margin: '60px auto' }} /> : (
        <>
          <Space wrap style={{ marginBottom: 8 }} align="center">
            <Text strong>{t('pages.recruit.reco.client')}</Text>
            <Select showSearch allowClear={!data.client_required} style={{ width: 280 }} value={clientId} placeholder={t('pages.recruit.reco.clientPh')}
              filterOption={false} onSearch={searchClients} onFocus={() => !clientOpts.length && searchClients('')}
              onChange={(v) => (v ? changeClient(Number(v)) : setClientId(undefined))} options={clientOpts} disabled={!!item?.sent_at || editing}
              status={data.client_required && !clientId ? 'warning' : undefined} />
            <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.reco.clientHint')}</Text>
          </Space>
          <Space wrap style={{ marginBottom: 12 }}>
            <Segmented value={lang} onChange={(v) => { setLang(v as Lang); setEditing(false); }}
              options={(['zh', 'en', 'id'] as Lang[]).map((l) => ({ value: l, label: <span>{t(`pages.recruit.reco.lang.${l}`)}{data.items?.[l]?.status === 'ready' ? ' ✓' : ''}</span> }))} />
            <Segmented value={kind} onChange={(v) => setKind(v as Kind)}
              options={[{ value: 'letter', label: labels.letter_title }, { value: 'resume', label: labels.resume_title }]} />
            {ready && !editing && !locked && (
              <Popconfirm title={t(item.edited_at ? 'pages.recruit.reco.regenEdited' : 'pages.recruit.reco.regenConfirm')} onConfirm={generate}>
                <Button icon={<ReloadOutlined />} loading={busy}>{t('pages.recruit.reco.regenerate')}</Button>
              </Popconfirm>
            )}
            {ready && <Text type="secondary" style={{ fontSize: 12 }}>
              {item.edited_at ? t('pages.recruit.reco.editedAt', { at: fmtMin(item.edited_at) }) : t('pages.recruit.reco.generatedAt', { at: fmtMin(item.generated_at) })}</Text>}
          </Space>
          {ready && item.stale && !locked && <Alert type="warning" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.reco.stale')} />}
          {locked && (
            <Alert type="success" showIcon icon={<LockOutlined />} style={{ marginBottom: 10 }}
              message={t('pages.recruit.reco.sentInfo', { by: item.sent_by_name || '-', at: fmtMin(item.sent_at) })}
              description={item.sent_note ? `${t('pages.recruit.reco.sentTo')}${item.sent_note}` : t('pages.recruit.reco.lockedHint')} />
          )}

          {!item || (item.status === 'failed' && !item.content) ? (
            <DoodleEmpty kind="list" description={item?.status === 'failed'
              ? <><div>{t('pages.recruit.reco.failed')}</div><Text type="secondary" style={{ fontSize: 12 }}>{item.error === 'budget' ? t('pages.recruit.err.budget') : item.error}</Text></>
              : t('pages.recruit.reco.none')}>
              <Button type="primary" icon={<ThunderboltOutlined />} loading={busy} onClick={generate}>{t('pages.recruit.reco.generate', { lang: t(`pages.recruit.reco.lang.${lang}`) })}</Button>
              <div style={{ marginTop: 8 }}><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.reco.generateHint')}</Text></div>
            </DoodleEmpty>
          ) : item.status === 'generating' ? (
            <div style={{ textAlign: 'center', padding: 60 }}><Spin /><div style={{ marginTop: 12 }}>{t('pages.recruit.reco.generating')}</div></div>
          ) : (
            <>
              {item.status === 'failed' && <Alert type="error" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.reco.failed')} description={item.error} />}
              {editing ? editor : (
                <div style={{ background: '#f5f5f5', padding: 16, overflow: 'auto' }}>
                  <RecoDoc ref={docRef} kind={kind} content={item.content} labels={labels} meta={data.meta} date={dayjs().format('YYYY-MM-DD')} fallbackTpl={snap(defaultTpl)} />
                </div>
              )}
            </>
          )}
          {!!data.items && Object.keys(data.items).length > 0 && (
            <div style={{ marginTop: 8 }}>
              {(['zh', 'en', 'id'] as Lang[]).filter((l) => data.items[l]).map((l) => (
                <Tag key={l} color={data.items[l].status === 'ready' ? 'green' : data.items[l].status === 'generating' ? 'processing' : 'red'}>
                  {t(`pages.recruit.reco.lang.${l}`)} · {t(`pages.recruit.reco.status.${data.items[l].status}`)}</Tag>))}
            </div>
          )}
        </>
      )}
    </Drawer>
  );
};

export default RecoDrawer;
