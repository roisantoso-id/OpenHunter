/**
 * 系统设置 · 招聘设置 · 推荐信模板（「推荐信要有我们固定的格式、固定的模板和细微的修改，AI 预填，HR 能改」）。
 * 按语言（中 / 英 / 印尼）管模板：列表 + 编辑器 + 右侧实时预览（示例数据，AI 段落显示「〔AI：推荐概述〕」这样的占位）。
 * 块：固定文字（可插变量）/ AI 段落 / 手填项（可设必填）/ 档案字段；上移下移删除。
 * 内置「系统默认」只读，可「复制为新模板」再改；设为默认后新生成的推荐信都用它（已写好的信存的是快照，不受影响）。
 * 后端：recruitRecoTemplates / recruitRecoTemplateSave / recruitRecoTemplateArchive（recruit_admin）。块规则见 includes/recruit_reco_tpl.php。
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Button, Card, Checkbox, Col, Dropdown, Empty, Input, Popconfirm, Row, Segmented, Select, Space, Spin, Switch, Table, Tag, Tooltip, Typography, message,
} from 'antd';
import { ArrowDownOutlined, ArrowUpOutlined, CopyOutlined, DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useIntl } from '@umijs/max';
import dayjs from 'dayjs';
import { recruitRecoTemplates, recruitRecoTemplateSave, recruitRecoTemplateArchive } from '@/services/api';
import { showErr, fmtMin } from '../common';
import RecoDoc from './RecoDoc';

const { Text } = Typography;
type Lang = 'zh' | 'en' | 'id';
const TYPE_COLOR: Record<string, string> = { ai: 'blue', fixed: 'default', manual: 'orange', field: 'purple' };
let seq = 0;
const newKey = (p: string) => `${p}_${Date.now().toString(36)}${(seq++).toString(36)}`;

const RecoTemplatePanel: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [lang, setLang] = useState<Lang>(intl.locale === 'id-ID' ? 'id' : intl.locale === 'en-US' ? 'en' : 'zh');
  const [all, setAll] = useState<any>(null);
  const [meta, setMeta] = useState<{ vars: string[]; slots: string[]; fields: string[] } | null>(null);
  const [labels, setLabels] = useState<any>(null);
  const [edit, setEdit] = useState<any>(null);   // 正在编辑的模板（id=0 且非 builtin = 新建）
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    const r = await recruitRecoTemplates();
    if (r?.success) { setAll(r.data); setMeta({ vars: r.vars, slots: r.slots, fields: r.fields }); setLabels(r.labels); } else showErr(r, t);
  }, [t]);
  useEffect(() => { load(); }, [load]);

  const list: any[] = all?.[lang] || [];
  const startNew = (from?: any) => setEdit({
    id: 0, lang, name: from ? `${from.name} ${t('pages.recruit.tpl.copySuffix')}` : '', title: from?.title || '', is_default: false,
    blocks: (from?.blocks || []).map((b: any) => ({ ...b })),
  });
  const save = async () => {
    if (!edit.name?.trim()) { message.error(t('pages.recruit.err.nameRequired')); return; }
    if (!edit.blocks.length) { message.error(t('pages.recruit.err.tplEmpty')); return; }
    setSaving(true);
    try {
      const r = await recruitRecoTemplateSave({ ...edit, lang });
      if (r?.success) { message.success(t('pages.recruit.msg.saved')); setEdit(null); load(); } else showErr(r, t);
    } finally { setSaving(false); }
  };
  const setBlock = (i: number, patch: any) => setEdit((e: any) => ({ ...e, blocks: e.blocks.map((b: any, j: number) => (j === i ? { ...b, ...patch } : b)) }));
  const move = (i: number, d: number) => setEdit((e: any) => {
    const b = [...e.blocks]; const j = i + d;
    if (j < 0 || j >= b.length) return e;
    [b[i], b[j]] = [b[j], b[i]];
    return { ...e, blocks: b };
  });
  const addBlock = (type: string, extra: any = {}) => setEdit((e: any) => ({ ...e, blocks: [...e.blocks, { key: newKey(type), type, ...extra }] }));

  // 预览示例：AI 段落 / 手填项显示占位，看得出每块放在哪
  const lb = labels?.[lang] || {};
  const sample = useMemo(() => {
    if (!edit) return null;
    const ph = (s: string) => `〔${s}〕`;
    const letter: any = { to: 'PT Contoh Indonesia', match: [] };
    (meta?.slots || []).forEach((s) => {
      if (s === 'match') letter.match = [{ requirement: ph(t('pages.recruit.tpl.slot.match')), met: 'yes', evidence: ph('AI') }];
      else if (s === 'paragraphs') letter.paragraphs = [ph(t('pages.recruit.tpl.slot.paragraphs'))];
      else letter[s] = ph(`AI：${t(`pages.recruit.tpl.slot.${s}`)}`);
    });
    const manual: any = {};
    edit.blocks.forEach((b: any) => { if (b.type === 'manual') manual[b.key] = ph(`${t('pages.recruit.tpl.type.manual')}：${b.title || ''}`); });
    return { letter, manual, tpl: { title: edit.title, blocks: edit.blocks },
      resume: { education: [{ school: 'Universitas Indonesia', degree: 'S1', major: 'Hukum', period: '2012' }], languages: ['English', 'Bahasa Indonesia'],
        certificates: ['CHRP'], skills: ['Payroll', 'Industrial Relations'] } };
  }, [edit, meta, t]);
  const sampleMeta = { position: 'HR Manager', candidate_name: 'Budi Santoso', candidate_code: 'OH-CD-000050-45', recruiter_name: 'Recruiter',
    recruiter_email: 'recruiter@example.com', client: 'PT Contoh Indonesia' };

  const blockEditor = (b: any, i: number) => {
    const head = (
      <Space size={6}>
        <Tag color={TYPE_COLOR[b.type]}>{t(`pages.recruit.tpl.type.${b.type}`)}</Tag>
        {b.type === 'ai' && <Text>{t(`pages.recruit.tpl.slot.${b.slot}`)}</Text>}
        {b.type === 'field' && <Text>{t(`pages.recruit.tpl.field.${b.source}`)}</Text>}
      </Space>
    );
    const tools = (
      <Space size={0}>
        <Button size="small" type="text" icon={<ArrowUpOutlined />} disabled={i === 0} onClick={() => move(i, -1)} />
        <Button size="small" type="text" icon={<ArrowDownOutlined />} disabled={i === edit.blocks.length - 1} onClick={() => move(i, 1)} />
        <Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={() => setEdit((e: any) => ({ ...e, blocks: e.blocks.filter((_: any, j: number) => j !== i) }))} />
      </Space>
    );
    return (
      <Card key={b.key} size="small" title={head} extra={tools} style={{ marginBottom: 8 }}>
        <Input size="small" style={{ marginBottom: 6 }} value={b.title} onChange={(e) => setBlock(i, { title: e.target.value })}
          placeholder={t(b.type === 'manual' ? 'pages.recruit.tpl.titleReq' : 'pages.recruit.tpl.titleOpt')} />
        {b.type === 'fixed' && (
          <>
            <Input.TextArea autoSize={{ minRows: 2 }} value={b.text} onChange={(e) => setBlock(i, { text: e.target.value })} placeholder={t('pages.recruit.tpl.fixedPh')} />
            <Space size={[4, 4]} wrap style={{ marginTop: 6 }}>
              <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.tpl.insertVar')}</Text>
              {(meta?.vars || []).map((v) => (
                <Tooltip key={v} title={t(`pages.recruit.tpl.var.${v}`)}>
                  <Tag style={{ cursor: 'pointer' }} onClick={() => setBlock(i, { text: `${b.text || ''}{{${v}}}` })}>{`{{${v}}}`}</Tag>
                </Tooltip>))}
              <Checkbox checked={!!b.small} onChange={(e) => setBlock(i, { small: e.target.checked })}>{t('pages.recruit.tpl.small')}</Checkbox>
            </Space>
          </>
        )}
        {b.type === 'manual' && (
          <Space direction="vertical" style={{ width: '100%' }} size={6}>
            <Input size="small" value={b.hint} onChange={(e) => setBlock(i, { hint: e.target.value })} placeholder={t('pages.recruit.tpl.hintPh')} />
            <Space><Switch size="small" checked={!!b.required} onChange={(v) => setBlock(i, { required: v })} /><Text>{t('pages.recruit.tpl.required')}</Text></Space>
          </Space>
        )}
        {b.type === 'ai' && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.tpl.aiNote')}</Text>}
        {b.type === 'field' && <Text type="secondary" style={{ fontSize: 12 }}>{t(b.source === 'signature' ? 'pages.recruit.tpl.fromAccount' : 'pages.recruit.tpl.fromResume')}</Text>}
      </Card>
    );
  };

  if (!all) return <Spin style={{ display: 'block', margin: '40px auto' }} />;

  return (
    <div>
      <Space style={{ marginBottom: 12 }} wrap>
        <Segmented value={lang} onChange={(v) => { setLang(v as Lang); setEdit(null); }}
          options={(['zh', 'en', 'id'] as Lang[]).map((l) => ({ value: l, label: t(`pages.recruit.reco.lang.${l}`) }))} />
        <Button type="primary" icon={<PlusOutlined />} onClick={() => startNew()}>{t('pages.recruit.tpl.new')}</Button>
        <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.tpl.panelHint')}</Text>
      </Space>

      <Table rowKey={(r: any) => `${r.builtin ? 'b' : r.id}`} size="small" pagination={false} dataSource={list} style={{ marginBottom: 16 }}
        columns={[
          { title: t('pages.recruit.tpl.name'), render: (_: any, r: any) => (
              <Space size={6} wrap><Text strong>{r.name}</Text>{r.builtin && <Tag>{t('pages.recruit.tpl.builtin')}</Tag>}
                {r.is_default && <Tag color="green">{t('pages.recruit.tpl.default')}</Tag>}{r.status === 'archived' && <Tag color="default">{t('pages.recruit.tpl.archived')}</Tag>}</Space>) },
          { title: t('pages.recruit.tpl.blocks'), width: 380, render: (_: any, r: any) => (
              <Space size={[4, 4]} wrap>{(r.blocks || []).map((b: any) => (
                <Tag key={b.key} color={TYPE_COLOR[b.type]} style={{ marginInlineEnd: 0 }}>
                  {b.title || (b.type === 'ai' ? t(`pages.recruit.tpl.slot.${b.slot}`) : b.type === 'field' ? t(`pages.recruit.tpl.field.${b.source}`) : t(`pages.recruit.tpl.type.${b.type}`))}
                </Tag>))}</Space>) },
          { title: t('pages.recruit.tpl.updated'), width: 150, render: (_: any, r: any) => (r.builtin ? '-' : <><div>{r.updated_by_name || '-'}</div><Text type="secondary" style={{ fontSize: 12 }}>{fmtMin(r.updated_at)}</Text></>) },
          { title: '', width: 230, align: 'right' as const, render: (_: any, r: any) => (
              <Space size={0} wrap>
                {!r.builtin && r.status !== 'archived' && <Button size="small" type="link" icon={<EditOutlined />} onClick={() => setEdit({ ...r, blocks: r.blocks.map((b: any) => ({ ...b })) })}>{t('pages.recruit.tpl.edit')}</Button>}
                <Button size="small" type="link" icon={<CopyOutlined />} onClick={() => startNew(r)}>{t('pages.recruit.tpl.copy')}</Button>
                {!r.builtin && !r.is_default && r.status !== 'archived' && (
                  <Button size="small" type="link" onClick={async () => { const x = await recruitRecoTemplateSave({ ...r, is_default: true }); if (x?.success) load(); else showErr(x, t); }}>
                    {t('pages.recruit.tpl.setDefault')}</Button>)}
                {!r.builtin && (
                  <Popconfirm title={t(r.status === 'archived' ? 'pages.recruit.tpl.restoreConfirm' : 'pages.recruit.tpl.archiveConfirm')}
                    onConfirm={async () => { const x = await recruitRecoTemplateArchive(Number(r.id), r.status === 'archived'); if (x?.success) load(); else showErr(x, t); }}>
                    <Button size="small" type="link" danger={r.status !== 'archived'}>{t(r.status === 'archived' ? 'pages.recruit.tpl.restoreTpl' : 'pages.recruit.tpl.archive')}</Button>
                  </Popconfirm>)}
              </Space>) },
        ]} />

      {!edit ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('pages.recruit.tpl.pickToEdit')} /> : (
        <Row gutter={16}>
          <Col xs={24} xl={11}>
            <Card size="small" title={t(edit.id ? 'pages.recruit.tpl.editing' : 'pages.recruit.tpl.creating')}
              extra={<Space><Button onClick={() => setEdit(null)}>{t('pages.recruit.cancel')}</Button><Button type="primary" loading={saving} onClick={save}>{t('pages.recruit.save')}</Button></Space>}>
              <Row gutter={8}>
                <Col span={12}><Input value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} placeholder={t('pages.recruit.tpl.namePh')} /></Col>
                <Col span={12}><Input value={edit.title} onChange={(e) => setEdit({ ...edit, title: e.target.value })} placeholder={t('pages.recruit.tpl.docTitlePh')} /></Col>
              </Row>
              <Checkbox style={{ margin: '8px 0' }} checked={!!edit.is_default} onChange={(e) => setEdit({ ...edit, is_default: e.target.checked })}>{t('pages.recruit.tpl.asDefault')}</Checkbox>
              {edit.blocks.map(blockEditor)}
              <Space wrap>
                <Button size="small" icon={<PlusOutlined />} onClick={() => addBlock('fixed', { text: '' })}>{t('pages.recruit.tpl.type.fixed')}</Button>
                <Dropdown menu={{ items: (meta?.slots || []).map((s) => ({ key: s, label: t(`pages.recruit.tpl.slot.${s}`) })), onClick: ({ key }) => addBlock('ai', { slot: key }) }}>
                  <Button size="small" icon={<PlusOutlined />}>{t('pages.recruit.tpl.type.ai')}</Button>
                </Dropdown>
                <Button size="small" icon={<PlusOutlined />} onClick={() => addBlock('manual', { title: '', required: false })}>{t('pages.recruit.tpl.type.manual')}</Button>
                <Dropdown menu={{ items: (meta?.fields || []).map((s) => ({ key: s, label: t(`pages.recruit.tpl.field.${s}`) })), onClick: ({ key }) => addBlock('field', { source: key }) }}>
                  <Button size="small" icon={<PlusOutlined />}>{t('pages.recruit.tpl.type.field')}</Button>
                </Dropdown>
              </Space>
            </Card>
          </Col>
          <Col xs={24} xl={13}>
            <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.tpl.previewHint')}</Text>
            {/* A4 预览缩到 65%，与推荐材料抽屉、PDF 用同一个 RecoDoc */}
            <div style={{ background: '#f5f5f5', padding: 12, marginTop: 4, overflow: 'hidden', height: 1123 * 0.65 + 24 }}>
              <div style={{ transform: 'scale(0.65)', transformOrigin: 'top left', width: 794 }}>
                {labels ? <RecoDoc kind="letter" content={sample} labels={lb} meta={sampleMeta} date={dayjs().format('YYYY-MM-DD')} /> : <Spin />}
              </div>
            </div>
          </Col>
        </Row>
      )}
    </div>
  );
};

export default RecoTemplatePanel;
