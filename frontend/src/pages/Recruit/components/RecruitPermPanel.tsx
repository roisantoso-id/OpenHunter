/**
 * 招聘权限（「权限要能配置谁可以看、谁不可以」）——系统设置 · 招聘设置 · 招聘权限。
 * 每个在职的人对三个招聘模块：
 *   recruit       进招聘模块，只看自己名下的候选人 / 自己收到或上传的简历
 *   recruit_all   看全部候选人与简历 + 招聘绩效看板
 *   recruit_admin 管理：认领/合并/设置（含看全部）
 * 角色授予的（「权限配置」里按角色勾的）这里只显示、关不掉；这里的开关只改个人授予（users.extra_modules）。
 * 仅系统管理员可用（后端 recruitRequireSysAdmin）。
 */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Table, Switch, Tooltip, Input, Checkbox, Space, Tag, Alert, Typography, message } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
import { useIntl } from '@umijs/max';
import { recruitPermList, recruitPermSet } from '@/services/api';
import { showErr } from '../common';

const { Text } = Typography;
const MODS = ['recruit', 'recruit_all', 'recruit_admin'] as const;

const RecruitPermPanel: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [rows, setRows] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [kw, setKw] = useState('');
  const [onlyGranted, setOnlyGranted] = useState(true);
  const [busy, setBusy] = useState<string>('');

  const load = useCallback(async () => {
    setLoading(true);
    try { const r = await recruitPermList(); if (r?.success) setRows(r.data || []); else showErr(r, t); } finally { setLoading(false); }
  }, [t]);
  useEffect(() => { load(); }, [load]);

  const has = (r: any, m: string) => r[m].admin || r[m].role || r[m].extra;
  const list = useMemo(() => rows.filter((r) => (!kw || String(r.name).toLowerCase().includes(kw.toLowerCase()))
    && (!onlyGranted || MODS.some((m) => has(r, m)))), [rows, kw, onlyGranted]);

  const toggle = async (r: any, m: string, on: boolean) => {
    setBusy(`${r.id}:${m}`);
    try {
      const res = await recruitPermSet({ user_id: r.id, module: m, on: on ? 1 : 0 });
      if (res?.success) { message.success(t('pages.recruit.msg.saved')); load(); } else showErr(res, t);
    } finally { setBusy(''); }
  };

  const cell = (r: any, m: string) => {
    const p = r[m];
    const locked = p.admin || p.role;
    const sw = <Switch size="small" checked={has(r, m)} disabled={locked} loading={busy === `${r.id}:${m}`} onChange={(v) => toggle(r, m, v)} />;
    return (
      <Space size={4}>
        {locked ? <Tooltip title={t(p.admin ? 'pages.recruit.perm.byAdmin' : 'pages.recruit.perm.byRole')}>{sw}</Tooltip> : sw}
        {p.role && !p.admin && <Tag style={{ fontSize: 11 }}>{t('pages.recruit.perm.role')}</Tag>}
        {p.extra && <Tag color="blue" style={{ fontSize: 11 }}>{t('pages.recruit.perm.personal')}</Tag>}
      </Space>
    );
  };

  return (
    <div style={{ maxWidth: 900 }}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.perm.hint')} />
      <Space style={{ marginBottom: 10 }}>
        <Input allowClear prefix={<SearchOutlined />} placeholder={t('pages.recruit.perm.search')} style={{ width: 220 }} value={kw} onChange={(e) => setKw(e.target.value)} />
        <Checkbox checked={onlyGranted} onChange={(e) => setOnlyGranted(e.target.checked)}>{t('pages.recruit.perm.onlyGranted')}</Checkbox>
        <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.perm.count', { n: rows.filter((r) => has(r, 'recruit') || has(r, 'recruit_all') || has(r, 'recruit_admin')).length })}</Text>
      </Space>
      <Table rowKey="id" size="small" loading={loading} dataSource={list} pagination={{ pageSize: 20, hideOnSinglePage: true }}
        columns={[
          { title: t('pages.recruit.perm.user'), render: (_: any, r: any) => <><Text strong>{r.name}</Text> <Text type="secondary" style={{ fontSize: 12 }}>{t(`pages.settings.users.role.${r.role}`)}</Text></> },
          ...MODS.map((m) => ({
            title: <Tooltip title={t(`pages.recruit.perm.desc.${m}`)}>{t(`pages.settings.roles.module.${m}`)}</Tooltip>,
            width: 200, render: (_: any, r: any) => cell(r, m),
          })),
        ]} />
    </div>
  );
};

export default RecruitPermPanel;
