/**
 * 简历解析 ·「资产信息」（「增加一个新的 tab：资产信息，包含我们拉黑的邮箱和原因等」）：
 *   ① 收简历的邮箱与各招聘专员的投递地址（只读；增删改在 设置 · 招聘 · 招聘邮箱）
 *   ② 发件人黑名单：系统自动拉黑的（解析失败 + AI 判广告 / 垃圾）与人工拉黑的，带原因，可解除
 */
import React from 'react';
import { Alert, Card, Table, Tag, Typography } from 'antd';
import { NO_HSCROLL, type T } from '../common';
import SenderBlockTable from '../components/SenderBlockTable';

const { Text } = Typography;

const AssetsPanel: React.FC<{ t: T; meta: any; mailboxes: any[]; reloadKey?: number }> = ({ t, meta, mailboxes, reloadKey }) => (
  <div>
    <Card size="small" style={{ marginBottom: 14 }} title={t('pages.recruit.assets.mail')}
      extra={<Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.assets.mailHint')}</Text>}>
      <div style={{ marginBottom: 8 }}>
        {mailboxes.map((m: any) => <Tag key={m.id} color="blue" style={{ marginBottom: 4 }}>{m.username}</Tag>)}
      </div>
      <Table rowKey="address" size="small" pagination={false} dataSource={meta?.addresses || []} {...NO_HSCROLL} columns={[
        { title: t('pages.recruit.source.address'), render: (_: any, a: any) => <Text copyable style={{ fontFamily: 'monospace', fontSize: 12 }}>{a.address}</Text> },
        { title: t('pages.recruit.source.user'), width: 200, render: (_: any, a: any) => a.user_name || '-' },
        { title: t('pages.recruit.col.status'), width: 110, render: (_: any, a: any) => (a.enabled
            ? <Tag color="green">{t('pages.recruit.assets.on')}</Tag> : <Tag>{t('pages.recruit.assets.off')}</Tag>) },
      ]} />
    </Card>
    <Card size="small" title={t('pages.recruit.block.title')}>
      <Alert type="info" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.block.autoHint')} />
      <SenderBlockTable reloadKey={reloadKey} />
    </Card>
  </div>
);

export default AssetsPanel;
