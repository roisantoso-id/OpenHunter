/**
 * 职位行上的「投递链接」：每个招聘专员在每个职位上一条自己的公开链接，
 * 发到 LinkedIn / WhatsApp 群，候选人免登录打开投简历；投进来的人归链接主人、自动挂到这个职位。
 * 显示打开数 / 投递数；撤销后旧链接失效，再点会生成新的。后端 includes/handlers/recruit_apply.php。
 */
import React, { useState } from 'react';
import { Button, Popover, Input, Space, Typography, Popconfirm, Alert, Spin, Tooltip, message } from 'antd';
import { LinkOutlined, CopyOutlined, ExportOutlined, StopOutlined } from '@ant-design/icons';
import { recruitJobLink, recruitRevokeJobLink } from '@/services/api';
import { showErr, type T } from '../common';

const { Text } = Typography;

const JobApplyLink: React.FC<{ t: T; job: any }> = ({ t, job }) => {
  const [open, setOpen] = useState(false);
  const [link, setLink] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const load = async () => {
    setLoading(true);
    try { const r = await recruitJobLink(Number(job.id)); if (r?.success) setLink(r.data); else { showErr(r, t); setOpen(false); } }
    finally { setLoading(false); }
  };
  const url = link ? `${window.location.origin}/apply/${link.slug ? `${link.slug}-` : ''}${link.token}` : '';   // 职位名 + 8 位码，只有码是凭证
  const panel = (
    <div style={{ width: 420 }} onClick={(e) => e.stopPropagation()}>
      <Spin spinning={loading}>
        <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.jobLink.hint')}</Text>
        {job.status !== 'open' && <Alert type="warning" showIcon style={{ margin: '8px 0' }} message={t('pages.recruit.jobLink.closedHint')} />}
        {link && (
          <>
            <Space.Compact style={{ width: '100%', margin: '8px 0' }}>
              <Input value={url} readOnly />
              <Button icon={<CopyOutlined />} onClick={() => navigator.clipboard.writeText(url).then(() => message.success(t('pages.recruit.copied')))}>{t('pages.recruit.copy')}</Button>
              <Button icon={<ExportOutlined />} href={url} target="_blank" />
            </Space.Compact>
            <Space style={{ width: '100%', justifyContent: 'space-between' }}>
              <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.jobLink.stats', { v: link.views, a: link.applies })}</Text>
              <Popconfirm title={t('pages.recruit.jobLink.revokeConfirm')} onConfirm={async () => {
                const r = await recruitRevokeJobLink(Number(job.id));
                if (r?.success) { message.success(t('pages.recruit.jobLink.revoked')); load(); } else showErr(r, t);
              }}>
                <Button size="small" danger icon={<StopOutlined />}>{t('pages.recruit.jobLink.revoke')}</Button>
              </Popconfirm>
            </Space>
          </>)}
      </Spin>
    </div>);
  return (
    <Popover trigger="click" open={open} onOpenChange={(v) => { setOpen(v); if (v) load(); }} title={t('pages.recruit.jobLink.title')} content={panel} placement="bottomRight">
      <Tooltip title={t('pages.recruit.jobLink.btn')}><Button icon={<LinkOutlined />} /></Tooltip>
    </Popover>
  );
};

export default JobApplyLink;
