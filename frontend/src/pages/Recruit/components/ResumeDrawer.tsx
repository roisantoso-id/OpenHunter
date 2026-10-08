/**
 * 一份简历的解析详情（简历解析页点行打开）：左边原件、右边抽取结果，对照着看解析得对不对。
 * 模型没把握的字段（uncertain_fields）在抽取结果里标橙色。
 * ⛔ 原件只经鉴权接口取 blob（接口不下发 file_path）。
 */
import React, { useCallback, useEffect, useState } from 'react';
import { Drawer, Tabs, Tag, Space, Button, Typography, Spin, Empty, Image, Popconfirm, Alert, Descriptions, Row, Col, message, Tooltip } from 'antd';
import { ReloadOutlined, UserOutlined, DownloadOutlined, ColumnWidthOutlined, FullscreenExitOutlined } from '@ant-design/icons';
import { useIntl } from '@umijs/max';
import { DetailBlock, DetailTable } from '@/components/DetailPanel';
import { recruitGetResume, recruitGetResumeFile, recruitReparseResume } from '@/services/api';
import { showErr, fmtMin, candCode, NO_HSCROLL } from '../common';
import ProfileView from './ProfileView';
import PdfPages from './PdfPages';

const { Text } = Typography;
const MIME: Record<string, string> = { pdf: 'application/pdf', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg' };

interface Props {
  open: boolean;
  resumeId: number | null;
  onClose: () => void;
  onChanged?: () => void;
  onOpenCandidate?: (id: number) => void;
}

const ResumeDrawer: React.FC<Props> = ({ open, resumeId, onClose, onChanged, onOpenCandidate }) => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: Record<string, any>) => intl.formatMessage({ id }, v), [intl]);
  const [d, setD] = useState<any>(null);
  const [fileUrl, setFileUrl] = useState('');
  const [fileBlob, setFileBlob] = useState<Blob | null>(null);
  const [fileLoading, setFileLoading] = useState(false);

  const load = useCallback(async () => {
    if (!resumeId) return;
    const res = await recruitGetResume(resumeId);
    if (res?.success) setD(res.data); else showErr(res, t);
  }, [resumeId, t]);
  useEffect(() => { if (open) { setD(null); load(); } }, [open, load]);

  // 原件：带 token 取 blob，按扩展名补 MIME（OSS 回的类型不一定对）。PDF 交给 PdfPages 逐页画成白底图片
  useEffect(() => {
    let url = '';
    setFileUrl('');
    setFileBlob(null);
    if (!open || !d?.id || !MIME[d.file_ext]) return undefined;
    setFileLoading(true);
    recruitGetResumeFile(d.id).then(async (blob: Blob) => {
      if (blob.type.includes('json')) { showErr(JSON.parse(await blob.text()), t, 'pages.recruit.file.failed'); return; }
      const typed = new Blob([blob], { type: MIME[d.file_ext] });
      url = URL.createObjectURL(typed);
      setFileBlob(typed);
      setFileUrl(url);
    }).catch(() => message.error(t('pages.recruit.file.failed'))).finally(() => setFileLoading(false));
    return () => { if (url) URL.revokeObjectURL(url); };
  }, [open, d?.id, d?.file_ext, t]);

  const onPdfError = useCallback(() => message.error(t('pages.recruit.file.failed')), [t]);

  const download = async () => {
    const blob = await recruitGetResumeFile(d.id);
    const u = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = u; a.download = d.file_name || `resume-${d.id}`; a.click();
    setTimeout(() => URL.revokeObjectURL(u), 1000);
  };

  /* 抽屉宽度可调（「放宽一点，抽屉会加宽」）：拖左边缘改宽度、右上角「加宽/还原」一键近全屏；
     宽度记在 localStorage，下次打开沿用。原件预览占一半宽度，PDF「适合宽度」跟着变 */
  const vw = () => (typeof window !== 'undefined' ? window.innerWidth : 1400);
  const DEFAULT_W = Math.min(1280, vw() - 60);
  const [width, setWidth] = useState<number>(() => {
    const saved = Number(localStorage.getItem('recruit.resumeDrawerWidth') || 0);
    return saved ? Math.max(900, Math.min(vw() - 40, saved)) : DEFAULT_W;
  });
  const saveWidth = (w: number) => { setWidth(w); localStorage.setItem('recruit.resumeDrawerWidth', String(w)); };
  const wide = width >= vw() - 60;
  const startDrag = (e: React.MouseEvent) => {
    e.preventDefault();
    const move = (ev: MouseEvent) => setWidth(Math.max(900, Math.min(vw() - 40, vw() - ev.clientX)));
    const up = (ev: MouseEvent) => {
      window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up);
      document.body.style.cursor = ''; document.body.style.userSelect = '';
      saveWidth(Math.max(900, Math.min(vw() - 40, vw() - ev.clientX)));
    };
    document.body.style.cursor = 'col-resize'; document.body.style.userSelect = 'none';
    window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
  };
  const uncertain: string[] = d?.parsed?.uncertain_fields || [];
  const flags = String(d?.review_flags || '').split(',').filter(Boolean);

  const preview = !d ? null : !d.file_ext ? (
    <div style={{ padding: 12, background: '#fafafa', height: '100%', overflow: 'auto', whiteSpace: 'pre-wrap', fontSize: 12 }}>
      <Text type="secondary">{t('pages.recruit.bodyOnly')}</Text>{'\n\n'}{d.raw_text}
    </div>
  ) : !MIME[d.file_ext] ? (
    <Empty style={{ marginTop: 80 }} description={t('pages.recruit.resume.noPreview', { ext: d.file_ext.toUpperCase() })}>
      <Button icon={<DownloadOutlined />} onClick={download}>{t('pages.recruit.resume.download')}</Button>
    </Empty>
  ) : fileLoading || !fileUrl ? <Spin style={{ display: 'block', marginTop: 80 }} />
    : d.file_ext === 'pdf'
      ? (fileBlob && <PdfPages blob={fileBlob} onError={onPdfError} onWiden={() => saveWidth(vw() - 40)} />)
      : <div style={{ height: '100%', overflow: 'auto', textAlign: 'center' }}><Image src={fileUrl} style={{ maxWidth: '100%' }} /></div>;

  const source = d && (
    <DetailBlock column={2}>
      <Descriptions.Item label={t('pages.recruit.resume.source')} span={2}>
        <Space wrap size={4}>
          <Tag>{t(`pages.recruit.origin.${d.origin}`)}</Tag>
          {d.origin === 'email' && <>
            <Text>{d.mailbox || '-'}</Text>
            {!!d.plus_code && <Tag color="blue">+{d.plus_code}</Tag>}
            {!!d.from_addr && <Text type="secondary">{t('pages.recruit.resume.from', { from: d.from_addr })}</Text>}
          </>}
          {d.origin === 'upload' && <Text>{t('pages.recruit.resume.uploadedBy', { name: d.uploaded_by_name || '-' })}</Text>}
          {!!d.target_job_title && <Tag color="purple">{t('pages.recruit.resume.targetJob', { job: d.target_job_title })}</Tag>}
        </Space>
        {!!d.subject && <div><Text type="secondary" style={{ fontSize: 12 }}>{d.subject}</Text></div>}
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.col.receivedBy')}>
        {fmtMin(d.received_at)} · {d.source_user_name || t('pages.recruit.unclaimed')}
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.resume.docType')}>
        {d.doc_type ? t(`pages.recruit.doc.${d.doc_type}`) : '-'}{d.doc_type !== 'cv' && d.parse_status === 'parsed' ? ` · ${t('pages.recruit.resume.notCvHint')}` : ''}
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.col.parse')} span={2}>
        <Space wrap size={4}>
          <Tag color={d.parse_status === 'parsed' ? 'green' : d.parse_status === 'failed' ? 'red' : 'default'}>{t(`pages.recruit.parse.${d.parse_status}`)}</Tag>
          {!!d.parse_mode && <Text type="secondary">{t(`pages.recruit.resume.mode.${d.parse_mode}`)}</Text>}
          <Text type="secondary">{t('pages.recruit.attempts', { n: d.attempts })}</Text>
          {!!d.parse_error_kind && d.parse_status !== 'parsed' && <Tag color="orange">{t(`pages.recruit.errKind.${d.parse_error_kind}`)}</Tag>}
          {d.outdated && <Tag>{t('pages.recruit.resume.outdated')}</Tag>}
          {Number(d.needs_review) === 1 && <Tag color="orange">{t('pages.recruit.resume.review')}</Tag>}
          {flags.map((f) => <Tag key={f} color="orange">{t(`pages.recruit.flag.${f}`)}</Tag>)}
        </Space>
      </Descriptions.Item>
      <Descriptions.Item label={t('pages.recruit.col.candidate')} span={2}>
        {Number(d.candidate_id) > 0
          ? <Button type="link" size="small" style={{ padding: 0 }} icon={<UserOutlined />} onClick={() => onOpenCandidate?.(Number(d.candidate_id))}>
              {candCode(d.candidate_id)} {d.candidate_name}</Button>
          : <Text type="secondary">{t('pages.recruit.resume.noCandidate')}</Text>}
      </Descriptions.Item>
    </DetailBlock>
  );

  const tabs = d && [
    { key: 'parsed', label: t('pages.recruit.resume.tabParsed'), children: d.parsed
      ? <>
          {uncertain.length > 0 && <Alert type="warning" showIcon style={{ marginBottom: 10 }}
            message={t('pages.recruit.resume.uncertain', { fields: uncertain.join(', ') })} />}
          <ProfileView p={d.parsed} t={t} uncertain={uncertain} showIdentity translate={{ type: 'resume', id: Number(d.id) }} />
        </>
      : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t(`pages.recruit.parse.${d.parse_status}`)} /> },
    { key: 'text', label: t('pages.recruit.resume.tabText', { n: d.text_chars }), children: (
        <>
          {Number(d.text_chars) < 200 && !!d.file_ext && <Alert type="info" showIcon style={{ marginBottom: 8 }} message={t('pages.recruit.resume.visionHint')} />}
          <pre style={{ whiteSpace: 'pre-wrap', fontSize: 12, background: '#fafafa', padding: 10, maxHeight: '60vh', overflow: 'auto' }}>{d.raw_text || '-'}</pre>
        </>) },
    { key: 'log', label: t('pages.recruit.resume.tabLog', { n: (d.calls || []).length }), children: (
        <>
          {['pending', 'retry', 'processing'].includes(d.parse_status) && (d.calls || []).length === 0 && (
            <Alert type="info" showIcon style={{ marginBottom: 8 }} message={t('pages.recruit.resume.queueHint')} />)}
          {!!d.parse_error && d.parse_status !== 'parsed' && <Alert type="error" style={{ marginBottom: 8 }} message={d.parse_error} />}
          <DetailTable {...NO_HSCROLL} rowKey={(_, i) => String(i)} dataSource={d.calls || []} columns={[
            { title: t('pages.recruit.resume.calledAt'), width: 150, render: (_: any, c: any) => fmtMin(c.called_at) },
            { title: t('pages.recruit.resume.mode.label'), width: 130, render: (_: any, c: any) => t(`pages.recruit.resume.mode.${String(c.scene).endsWith('_vision') ? 'vision' : 'text'}`) },
            { title: t('pages.recruit.col.parse'), width: 90, render: (_: any, c: any) => <Tag color={c.status === 'success' ? 'green' : 'red'}>{c.status}</Tag> },
            { title: 'Tokens', width: 120, align: 'right' as const, render: (_: any, c: any) => (c.total_tokens
                ? <Tooltip title={t('pages.recruit.cost.inOut', { i: c.prompt_tokens, o: c.completion_tokens })}>
                    <div>{Number(c.total_tokens).toLocaleString()}</div><Text type="secondary" style={{ fontSize: 12 }}>${Number(c.usd).toFixed(5)}</Text></Tooltip>
                : '-') },
            { title: t('pages.recruit.resume.elapsed'), width: 80, align: 'right' as const, render: (_: any, c: any) => `${Number(c.elapsed).toFixed(1)}s` },
            { title: t('pages.recruit.resume.error'), render: (_: any, c: any) => <Text type="secondary" style={{ fontSize: 12 }}>{c.error || '-'}</Text> },
          ]} />
        </>) },
  ];

  return (
    <Drawer open={open} onClose={onClose} width={width} destroyOnClose
      title={d ? `${d.file_name || t('pages.recruit.bodyOnly')}` : t('pages.recruit.resume.title')}
      styles={{ body: { position: 'relative' } }}
      extra={d && (
        <Space>
          <Tooltip title={t(wide ? 'pages.recruit.resume.narrow' : 'pages.recruit.resume.widen')}>
            <Button icon={wide ? <FullscreenExitOutlined /> : <ColumnWidthOutlined />} onClick={() => saveWidth(wide ? DEFAULT_W : vw() - 40)}>
              {t(wide ? 'pages.recruit.resume.narrow' : 'pages.recruit.resume.widen')}</Button>
          </Tooltip>
          {['failed', 'retry', 'parsed'].includes(d.parse_status) && (
        <Popconfirm title={t('pages.recruit.reparseConfirm')} onConfirm={async () => {
          const res = await recruitReparseResume(d.id);
          if (res?.success) { message.success(t('pages.recruit.msg.queued')); load(); onChanged?.(); } else showErr(res, t);
        }}>
          <Button icon={<ReloadOutlined />}>{d.outdated ? t('pages.recruit.resume.reparseNew') : t('pages.recruit.reparse')}</Button>
        </Popconfirm>
          )}
        </Space>
      )}>
      {/* 左边缘拖拽条：按住往左拖加宽 */}
      <Tooltip title={t('pages.recruit.resume.dragHint')} placement="right">
        <div onMouseDown={startDrag}
          style={{ position: 'absolute', left: 0, top: 0, bottom: 0, width: 6, cursor: 'col-resize', zIndex: 10, background: 'transparent' }}
          onMouseEnter={(e) => { (e.currentTarget as HTMLDivElement).style.background = '#adc6ff'; }}
          onMouseLeave={(e) => { (e.currentTarget as HTMLDivElement).style.background = 'transparent'; }} />
      </Tooltip>
      {!d ? <Spin style={{ display: 'block', margin: '60px auto' }} /> : (
        <Row gutter={16} style={{ height: '100%' }}>
          {/* 抽屉拉到最宽时原件多占一些（14:10），看细节更舒服 */}
          <Col span={wide ? 14 : 12} style={{ height: 'calc(100vh - 110px)' }}>{preview}</Col>
          <Col span={wide ? 10 : 12} style={{ height: 'calc(100vh - 110px)', overflow: 'auto' }}>
            {source}
            <Tabs style={{ marginTop: 8 }} items={tabs as any} />
          </Col>
        </Row>
      )}
    </Drawer>
  );
};

export default ResumeDrawer;
