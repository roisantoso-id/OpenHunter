/**
 * 上传本地简历（「很多简历在本地，也要提供上传入口，不需要非从邮箱搜」）。
 * 批量拖拽；每个文件一次请求，逐个显示结果。上传人就是「收到简历的人」（算归属，先到先得）；
 * 管理员可以替别人传或传成待认领。选了职位的，AI 解析出候选人后自动挂到该职位。
 */
import React, { useEffect, useState } from 'react';
import { Drawer, Upload, Select, Space, Typography, Tag, List, Button, Alert, Form, Row, Col } from 'antd';
import { InboxOutlined, CheckCircleOutlined, CloseCircleOutlined, CopyOutlined, LoadingOutlined } from '@ant-design/icons';
import { useIntl, useModel } from '@umijs/max';
import { API_BASE_URL } from '@/services/config';
import { DETAIL_WIDTH } from '@/components/DetailPanel';

const { Text } = Typography;
const ACCEPT = '.pdf,.docx,.doc,.jpg,.jpeg,.png';

type Item = { uid: string; name: string; state: 'uploading' | 'added' | 'duplicate' | 'error'; msg?: string };

const UploadDrawer: React.FC<{ open: boolean; meta: any; defaultJobId?: number; onClose: () => void; onUploaded?: () => void; onShowQueue?: () => void }> =
({ open, meta, defaultJobId, onClose, onUploaded, onShowQueue }) => {
  const intl = useIntl();
  const t = (id: string, v?: any) => intl.formatMessage({ id }, v);
  const { initialState } = useModel('@@initialState');
  const me = Number((initialState as any)?.currentUser?.id || 0);
  const [owner, setOwner] = useState<number>(me);
  const [jobId, setJobId] = useState<number | undefined>(defaultJobId);
  const [items, setItems] = useState<Item[]>([]);

  useEffect(() => { if (open) { setOwner(me); setJobId(defaultJobId); setItems([]); } }, [open, me, defaultJobId]);

  const done = items.filter((i) => i.state === 'added').length;
  const set = (uid: string, patch: Partial<Item>) => setItems((p) => p.map((i) => (i.uid === uid ? { ...i, ...patch } : i)));

  return (
    <Drawer open={open} onClose={() => { onClose(); if (done) onUploaded?.(); }} width={DETAIL_WIDTH.info} destroyOnClose
      title={t('pages.recruit.upload.title')}>
      <Form layout="vertical">
        <Row gutter={16}>
          <Col span={12}>
            <Form.Item label={t('pages.recruit.col.owner')} extra={t('pages.recruit.upload.ownerHint')}>
              <Select value={owner} onChange={setOwner} disabled={!meta?.can_admin}
                options={[{ value: me, label: t('pages.recruit.upload.me') },
                  ...(meta?.can_admin ? [{ value: 0, label: t('pages.recruit.unclaimed') },
                    ...(meta?.owners || []).filter((o: any) => Number(o.id) !== me).map((o: any) => ({ value: Number(o.id), label: o.name }))] : [])]} />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item label={t('pages.recruit.upload.job')} extra={t('pages.recruit.upload.jobHint')}>
              <Select allowClear showSearch optionFilterProp="label" value={jobId} onChange={setJobId} placeholder={t('pages.recruit.upload.jobPh')}
                options={(meta?.open_jobs || []).map((j: any) => ({ value: Number(j.id), label: `${j.project_name} · ${j.title}` }))} />
            </Form.Item>
          </Col>
        </Row>
      </Form>
      <Upload.Dragger multiple accept={ACCEPT} showUploadList={false} name="file"
        action={`${API_BASE_URL}?action=recruitUploadResume`}
        headers={{ Authorization: `Bearer ${localStorage.getItem('token') || ''}` }}
        data={{ owner_user_id: owner, target_job_id: jobId || 0 }}
        beforeUpload={(f) => { setItems((p) => [{ uid: f.uid, name: f.name, state: 'uploading' }, ...p]); return true; }}
        onChange={(info) => {
          const f = info.file;
          if (f.status === 'done') {
            const r: any = f.response;
            if (r?.success) set(f.uid, { state: r.data?.status === 'duplicate' ? 'duplicate' : 'added' });
            else set(f.uid, { state: 'error', msg: r?.message_key ? t(r.message_key) : r?.errorMessage });
          } else if (f.status === 'error') {
            set(f.uid, { state: 'error', msg: (f.response as any)?.errorMessage || t('pages.recruit.upload.failed') });
          }
        }}>
        <p className="ant-upload-drag-icon"><InboxOutlined /></p>
        <p className="ant-upload-text">{t('pages.recruit.upload.drop')}</p>
        <p className="ant-upload-hint">{t('pages.recruit.upload.types')}</p>
      </Upload.Dragger>

      {items.length > 0 && (
        <>
          <Alert style={{ margin: '14px 0 8px' }} type="info" showIcon
            message={t('pages.recruit.upload.after', { n: done })}
            action={onShowQueue && <Button size="small" onClick={onShowQueue}>{t('pages.recruit.upload.queue')}</Button>} />
          <List size="small" dataSource={items} renderItem={(i) => (
            <List.Item>
              <Space>
                {i.state === 'uploading' ? <LoadingOutlined /> : i.state === 'error' ? <CloseCircleOutlined style={{ color: '#cf1322' }} />
                  : i.state === 'duplicate' ? <CopyOutlined style={{ color: '#888' }} /> : <CheckCircleOutlined style={{ color: '#389e0d' }} />}
                <Text>{i.name}</Text>
              </Space>
              {i.state === 'error' ? <Text type="danger" style={{ fontSize: 12 }}>{i.msg}</Text>
                : i.state !== 'uploading' && <Tag color={i.state === 'added' ? 'green' : 'default'}>{t(`pages.recruit.upload.${i.state}`)}</Tag>}
            </List.Item>
          )} />
        </>
      )}
    </Drawer>
  );
};

export default UploadDrawer;
