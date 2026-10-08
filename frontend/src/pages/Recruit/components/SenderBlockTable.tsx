/**
 * 发件人黑名单表（「拉黑的邮箱和原因要显示出来」）：简历解析 ·「资产信息」与 招聘设置 · 招聘邮箱 共用。
 * 每行：邮箱 / 域名、系统自动还是谁人工拉黑、原因（AI 判定理由三语 / 人工备注）、触发的那封邮件、一起标为已拉黑的简历数与之后又挡下几封；可解除
 * （解除后被挡下的简历重新排队，这个发件人以后系统不再自动拉黑）。后端：recruitSenderBlocks / recruitSenderUnblock。
 */
import React, { useCallback, useEffect, useState } from 'react';
import { Popconfirm, Table, Tag, Typography, message } from 'antd';
import { RobotOutlined, UserOutlined } from '@ant-design/icons';
import { useIntl } from '@umijs/max';
import { recruitSenderBlocks, recruitSenderUnblock } from '@/services/api';
import { showErr, fmtMin, NO_HSCROLL } from '../common';

const { Text } = Typography;

const SenderBlockTable: React.FC<{ reloadKey?: number }> = ({ reloadKey }) => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const lang = intl.locale === 'id-ID' ? 'id' : intl.locale === 'en-US' ? 'en' : 'zh';
  const [rows, setRows] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const load = useCallback(async () => {
    setLoading(true);
    try { const r = await recruitSenderBlocks(); if (r?.success) setRows(r.data || []); else showErr(r, t); } finally { setLoading(false); }
  }, [t]);
  useEffect(() => { load(); }, [load, reloadKey]);

  return (
    <Table rowKey="id" size="small" loading={loading} dataSource={rows} pagination={{ pageSize: 20, hideOnSinglePage: true }} {...NO_HSCROLL}
      locale={{ emptyText: t('pages.recruit.block.empty') }} columns={[
        { title: t('pages.recruit.block.pattern'), width: 240, render: (_: any, r: any) => (
            <><Text strong style={{ wordBreak: 'break-all' }}>{r.pattern}</Text>
              {String(r.pattern).startsWith('@') && <div><Tag>{t('pages.recruit.block.wholeDomain')}</Tag></div>}</>) },
        { title: t('pages.recruit.block.source'), width: 130, render: (_: any, r: any) => (r.source === 'auto'
            ? <Tag icon={<RobotOutlined />} color="purple">{t('pages.recruit.block.auto')}</Tag>
            : <><Tag icon={<UserOutlined />}>{t('pages.recruit.block.manual')}</Tag><div><Text type="secondary" style={{ fontSize: 12 }}>{r.created_by_name}</Text></div></>) },
        { title: t('pages.recruit.block.reason'), render: (_: any, r: any) => (
            <>
              {!!r.reason_key && <Tag color={r.reason_key === 'manual' ? 'default' : 'volcano'}>{t(`pages.recruit.block.reasonKey.${r.reason_key}`)}</Tag>}
              {!!r.reason?.[lang] && <div style={{ fontSize: 12, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{r.reason[lang]}</div>}
              {!!r.note && <div style={{ fontSize: 12 }}>{r.note}</div>}
              {!!r.sample_subject && <div style={{ fontSize: 12, color: '#888', wordBreak: 'break-word' }}>{t('pages.recruit.block.sample', { subject: r.sample_subject })}</div>}
            </>) },
        { title: t('pages.recruit.block.effect'), width: 170, render: (_: any, r: any) => (
            <>
              <div style={{ fontSize: 12 }}>{t('pages.recruit.block.resumesN', { n: r.blocked_resumes })}</div>
              <div style={{ fontSize: 12 }}>{t('pages.recruit.block.hitsN', { n: r.hits })}</div>
              {!!r.last_hit_at && <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.block.lastHit', { at: fmtMin(r.last_hit_at) })}</Text>}
            </>) },
        { title: t('pages.recruit.block.when'), width: 130, render: (_: any, r: any) => <Text style={{ fontSize: 12 }}>{fmtMin(r.created_at)}</Text> },
        { title: t('pages.recruit.col.actions'), width: 80, render: (_: any, r: any) => (
            <Popconfirm title={t('pages.recruit.block.unblockConfirm')} onConfirm={async () => {
              const x = await recruitSenderUnblock(Number(r.id));
              if (x?.success) { message.success(t('pages.recruit.block.unblocked', { n: x.data.requeued })); load(); } else showErr(x, t);
            }}><a>{t('pages.recruit.block.unblock')}</a></Popconfirm>) },
      ]} />
  );
};

export default SenderBlockTable;
