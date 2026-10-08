/**
 * 合同与文件：甲方合同、保证条款、候选人劳动合同都能上传。
 * 项目总览与候选人抽屉共用。图片出缩略图 + Image.PreviewGroup，PDF / Word 点开新窗口（§6.3）。
 * 文件一律经 recruitGetDocumentFile 鉴权取 blob，后端不下发 file_path。
 */
import React, { useEffect, useState } from 'react';
import { Drawer, Form, Select, Input, DatePicker, Upload, Button, Space, Tag, Image, Typography, Popconfirm, message } from 'antd';
import { UploadOutlined, InboxOutlined, DeleteOutlined, FilePdfOutlined, FileWordOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { DetailTable, DETAIL_WIDTH } from '@/components/DetailPanel';
import { API_BASE_URL } from '@/services/config';
import { recruitGetDocumentFile, recruitDeleteDocument } from '@/services/api';
import { showErr, fmtDay, NO_HSCROLL, T } from '../common';

const { Text } = Typography;
const IMG = ['jpg', 'jpeg', 'png'];
export type DocEntity = { type: 'project' | 'placement' | 'candidate'; id: number; label: string };

const Thumb: React.FC<{ id: number }> = ({ id }) => {
  const [url, setUrl] = useState<string>();
  useEffect(() => {
    let u = '';
    recruitGetDocumentFile(id).then((b: Blob) => { u = URL.createObjectURL(b); setUrl(u); }).catch(() => {});
    return () => { if (u) URL.revokeObjectURL(u); };
  }, [id]);
  return url ? <Image src={url} width={56} height={56} style={{ objectFit: 'cover', borderRadius: 4 }} /> : <div style={{ width: 56, height: 56, background: '#f5f5f5', borderRadius: 4 }} />;
};

const openFile = async (d: any) => {
  const b = await recruitGetDocumentFile(Number(d.id));
  const typed = d.file_ext === 'pdf' ? new Blob([b], { type: 'application/pdf' }) : b;
  window.open(URL.createObjectURL(typed), '_blank');
};

const UploadDocDrawer: React.FC<{ open: boolean; t: T; meta: any; entities: DocEntity[]; onClose: () => void; onDone: () => void }> =
({ open, t, meta, entities, onClose, onDone }) => {
  const [form] = Form.useForm();
  const [file, setFile] = useState<File | null>(null);
  const [saving, setSaving] = useState(false);
  const ent = Form.useWatch('entity', form);
  const cur = entities.find((e) => `${e.type}:${e.id}` === ent);
  const types: string[] = (meta?.lc?.doc_type || {})[cur?.type || 'project'] || [];
  const adminTypes: string[] = meta?.lc?.doc_admin || [];
  useEffect(() => { if (open) { form.resetFields(); setFile(null); if (entities[0]) form.setFieldValue('entity', `${entities[0].type}:${entities[0].id}`); } }, [open, entities, form]);

  const submit = async () => {
    const v = await form.validateFields();
    if (!file) { message.warning(t('pages.recruit.doc2.pickFile')); return; }
    const fd = new FormData();
    const [type, id] = String(v.entity).split(':');
    fd.append('file', file); fd.append('entity_type', type); fd.append('entity_id', id); fd.append('doc_type', v.doc_type);
    fd.append('title', v.title || ''); fd.append('notes', v.notes || '');
    fd.append('signed_at', v.signed_at ? dayjs(v.signed_at).format('YYYY-MM-DD') : '');
    fd.append('valid_until', v.valid_until ? dayjs(v.valid_until).format('YYYY-MM-DD') : '');
    setSaving(true);
    try {
      const r = await fetch(`${API_BASE_URL}?action=recruitUploadDocument`, { method: 'POST', body: fd,
        headers: { Authorization: `Bearer ${localStorage.getItem('token') || ''}` } }).then((x) => x.json()).catch(() => null);
      if (r?.success) { message.success(t('pages.recruit.msg.saved')); onDone(); } else showErr(r, t);
    } finally { setSaving(false); }
  };

  return (
    <Drawer open={open} onClose={onClose} width={DETAIL_WIDTH.form} destroyOnClose title={t('pages.recruit.doc2.upload')}
      extra={<Space><Button onClick={onClose}>{t('pages.recruit.cancel')}</Button><Button type="primary" loading={saving} onClick={submit}>{t('pages.recruit.save')}</Button></Space>}>
      <Form form={form} layout="vertical">
        <Form.Item name="entity" label={t('pages.recruit.doc2.belongsTo')} rules={[{ required: true }]}>
          <Select options={entities.map((e) => ({ value: `${e.type}:${e.id}`, label: e.label }))} onChange={() => form.setFieldValue('doc_type', undefined)} />
        </Form.Item>
        <Form.Item name="doc_type" label={t('pages.recruit.doc2.type')} rules={[{ required: true }]}>
          <Select options={types.map((d) => ({ value: d, label: t(`pages.recruit.docType.${d}`),
            disabled: cur?.type === 'project' && adminTypes.includes(d) && !meta?.can_admin }))} />
        </Form.Item>
        <Form.Item name="title" label={t('pages.recruit.doc2.title')}><Input maxLength={191} /></Form.Item>
        <Space size={12}>
          <Form.Item name="signed_at" label={t('pages.recruit.terms.signedAt')}><DatePicker /></Form.Item>
          <Form.Item name="valid_until" label={t('pages.recruit.doc2.validUntil')}><DatePicker /></Form.Item>
        </Space>
        <Form.Item name="notes" label={t('pages.recruit.pl.notes')}><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
        <Upload.Dragger maxCount={1} accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" beforeUpload={(f) => { setFile(f as any); return false; }}
          onRemove={() => setFile(null)} fileList={file ? [{ uid: '1', name: file.name, status: 'done' } as any] : []}>
          <p className="ant-upload-drag-icon"><InboxOutlined /></p>
          <p>{t('pages.recruit.doc2.dragHint')}</p>
        </Upload.Dragger>
      </Form>
    </Drawer>
  );
};

const DocumentsTab: React.FC<{ docs: any[]; t: T; meta: any; entities: DocEntity[]; onChanged: () => void }> = ({ docs, t, meta, entities, onChanged }) => {
  const [uploading, setUploading] = useState(false);
  const label = (d: any) => entities.find((e) => e.type === d.entity_type && e.id === Number(d.entity_id))?.label || `${d.entity_type} #${d.entity_id}`;
  const canDel = (d: any) => !(d.entity_type === 'project' && (meta?.lc?.doc_admin || []).includes(d.doc_type)) || !!meta?.can_admin;
  return (
    <>
      <div style={{ textAlign: 'right', marginBottom: 10 }}>
        <Button type="primary" icon={<UploadOutlined />} onClick={() => setUploading(true)} disabled={!entities.length}>{t('pages.recruit.doc2.upload')}</Button>
      </div>
      <Image.PreviewGroup>
        <DetailTable rowKey="id" dataSource={docs} pagination={false} {...NO_HSCROLL}
          columns={[
            { title: t('pages.recruit.doc2.file'), render: (_: any, d: any) => (
                <Space align="start">
                  {IMG.includes(d.file_ext) ? <Thumb id={Number(d.id)} />
                    : <Button type="link" style={{ padding: 0, fontSize: 28, height: 'auto' }} onClick={() => openFile(d)}
                        icon={d.file_ext === 'pdf' ? <FilePdfOutlined style={{ color: '#cf1322' }} /> : <FileWordOutlined style={{ color: '#1677ff' }} />} />}
                  <div style={{ wordBreak: 'break-word' }}>
                    <a onClick={() => openFile(d)}>{d.title || d.file_name}</a>
                    {d.title && <div style={{ fontSize: 12, color: '#8c8c8c' }}>{d.file_name}</div>}
                    {!!d.notes && <div style={{ fontSize: 12, whiteSpace: 'pre-wrap' }}>{d.notes}</div>}
                  </div>
                </Space>) },
            { title: t('pages.recruit.doc2.type'), width: 170, render: (_: any, d: any) => (
                <Space direction="vertical" size={2}>
                  <Tag color={d.doc_type === 'client_agreement' ? 'green' : 'blue'} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.docType.${d.doc_type}`)}</Tag>
                  <Text type="secondary" style={{ fontSize: 12 }}>{label(d)}</Text>
                </Space>) },
            { title: t('pages.recruit.doc2.dates'), width: 140, render: (_: any, d: any) => (
                <div style={{ fontSize: 12 }}>
                  {d.signed_at && <div>{t('pages.recruit.terms.signedAt')} {fmtDay(d.signed_at)}</div>}
                  {d.valid_until && <div>{t('pages.recruit.doc2.validUntil')} {fmtDay(d.valid_until)}</div>}
                  <Text type="secondary">{d.uploaded_by_name} · {fmtDay(d.created_at)}</Text>
                </div>) },
            { title: '', width: 60, render: (_: any, d: any) => canDel(d) && (
                <Popconfirm title={t('pages.recruit.doc2.delConfirm')} onConfirm={async () => {
                  const r = await recruitDeleteDocument(Number(d.id));
                  if (r?.success) { message.success(t('pages.recruit.msg.saved')); onChanged(); } else showErr(r, t);
                }}><Button type="text" danger size="small" icon={<DeleteOutlined />} /></Popconfirm>) },
          ]} />
      </Image.PreviewGroup>
      <UploadDocDrawer open={uploading} t={t} meta={meta} entities={entities} onClose={() => setUploading(false)} onDone={() => { setUploading(false); onChanged(); }} />
    </>
  );
};

export default DocumentsTab;
