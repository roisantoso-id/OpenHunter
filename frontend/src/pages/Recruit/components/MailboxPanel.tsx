/**
 * 招聘邮箱与专员代码（recruit_admin）。在「系统设置 · 招聘设置」里（「放到系统设置里管理」）。
 * 替代命令行 scripts/ops/recruit_mailbox_setup.php。
 * 保存前后端先试登录 Gmail IMAP，成功才存（密码加密存储，接口永不回传）。
 * 「只拉以后的邮件」默认不勾 = 首次同步拉全部历史邮件。
 * 下半部分：招聘专员代码（plus 地址 +ab / +cd…）分配给谁——谁的地址收到的简历归谁。
 * 代码建了就不能改名（地址已经发出去了），不用了就停用；换人只影响以后进来的邮件。
 */
import React, { useCallback, useEffect, useState } from 'react';
import { Form, Input, Checkbox, Button, Space, Typography, Tag, Alert, Switch, message, Descriptions, Divider, Select, Table } from 'antd';
import { useIntl } from '@umijs/max';
import { DetailBlock } from '@/components/DetailPanel';
import { recruitMailboxes, recruitSaveMailbox, recruitSaveSource, recruitMailRetry } from '@/services/api';
import { showErr, fmtMin } from '../common';
import SenderBlockTable from './SenderBlockTable';

const { Text } = Typography;

const MailboxPanel: React.FC<{ onChanged?: () => void }> = ({ onChanged }) => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [rows, setRows] = useState<any[]>([]);
  const [sources, setSources] = useState<any[]>([]);
  const [users, setUsers] = useState<any[]>([]);
  const [failures, setFailures] = useState<any[]>([]);
  const [newCode, setNewCode] = useState<{ code?: string; user_id?: number }>({});
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const [editId, setEditId] = useState<number>(0);

  const load = useCallback(async () => {
    const r = await recruitMailboxes();
    if (r?.success) { setRows(r.data || []); setSources(r.sources || []); setUsers(r.users || []); setFailures(r.failures || []); } else showErr(r, t);
  }, [t]);
  useEffect(() => { load(); }, [load]);


  const save = async () => {
    const v = await form.validateFields();
    setSaving(true);
    try {
      const res = await recruitSaveMailbox({ ...v, id: editId, skip_existing: v.skip_existing ? 1 : 0, enabled: v.enabled ? 1 : 0 });
      if (res?.success) {
        message.success(t('pages.recruit.mailbox.saved'));
        setEditId(0); form.resetFields(); load(); onChanged?.();
      } else showErr(res, t);
    } finally { setSaving(false); }
  };
  const toggle = async (m: any, enabled: boolean) => {
    const res = await recruitSaveMailbox({ id: m.id, username: m.username, name: m.name, enabled: enabled ? 1 : 0 });
    if (res?.success) { load(); onChanged?.(); } else showErr(res, t);
  };

  const saveSource = async (data: Record<string, any>) => {
    const res = await recruitSaveSource(data);
    if (res?.success) { message.success(t('pages.recruit.msg.saved')); load(); onChanged?.(); return true; }
    showErr(res, t); return false;
  };
  const base = rows.find((m) => Number(m.enabled) === 1)?.username || rows[0]?.username || '';
  const plusOf = (code: string) => (base ? base.replace('@', `+${code}@`) : `+${code}`);
  const userOptions = users.map((u: any) => ({ value: Number(u.id), label: u.name }));

  return (
    <div style={{ maxWidth: 820 }}>
      {rows.map((m) => (
        <DetailBlock key={m.id} column={2}>
          <Descriptions.Item label={t('pages.recruit.mailbox.address')} span={2}>
            <Space>
              <Text strong>{m.username}</Text>
              <Switch size="small" checked={Number(m.enabled) === 1} onChange={(v) => toggle(m, v)} />
              <Text type="secondary">{Number(m.enabled) === 1 ? t('pages.recruit.mailbox.on') : t('pages.recruit.mailbox.off')}</Text>
              <Button size="small" type="link" onClick={() => { setEditId(Number(m.id)); form.setFieldsValue({ username: m.username, name: m.name, enabled: Number(m.enabled) === 1 }); }}>
                {t('pages.recruit.edit')}
              </Button>
            </Space>
          </Descriptions.Item>
          <Descriptions.Item label={t('pages.recruit.mailbox.lastSync')}>{fmtMin(m.last_sync_at)}</Descriptions.Item>
          <Descriptions.Item label={t('pages.recruit.mailbox.cursor')}>UID {m.last_uid}</Descriptions.Item>
          {!!m.last_error && <Descriptions.Item label={t('pages.recruit.mailbox.error')} span={2}><Text type="danger">{m.last_error}</Text></Descriptions.Item>}
          {failures.some((f) => Number(f.mailbox_id) === Number(m.id)) && (
            <Descriptions.Item label={t('pages.recruit.mailbox.failures')} span={2}>
              <Space direction="vertical" size={4} style={{ width: '100%' }}>
                <Space wrap>
                  <Tag color="orange">{t('pages.recruit.mailbox.failPending', { n: failures.filter((f) => Number(f.mailbox_id) === Number(m.id) && f.status === 'pending').length })}</Tag>
                  <Tag color="red">{t('pages.recruit.mailbox.failGaveUp', { n: failures.filter((f) => Number(f.mailbox_id) === Number(m.id) && f.status === 'gave_up').length })}</Tag>
                  {failures.some((f) => Number(f.mailbox_id) === Number(m.id) && f.status === 'gave_up') && (
                    <Button size="small" onClick={async () => { const r = await recruitMailRetry({ mailbox_id: m.id }); if (r?.success) { message.success(t('pages.recruit.msg.queued')); load(); } else showErr(r, t); }}>
                      {t('pages.recruit.mailbox.retryAll')}</Button>)}
                </Space>
                <Table rowKey="id" size="small" pagination={{ pageSize: 5, hideOnSinglePage: true }}
                  dataSource={failures.filter((f) => Number(f.mailbox_id) === Number(m.id))}
                  columns={[
                    { title: 'UID', dataIndex: 'imap_uid', width: 80 },
                    { title: t('pages.recruit.mailbox.failTries'), width: 90, render: (_: any, f: any) => (
                        <Tag color={f.status === 'gave_up' ? 'red' : 'orange'}>{f.attempts} · {t(`pages.recruit.mailbox.failStatus.${f.status}`)}</Tag>) },
                    { title: t('pages.recruit.mailbox.error'), render: (_: any, f: any) => <Text type="secondary" style={{ fontSize: 12 }}>{f.last_error}</Text> },
                    { title: t('pages.recruit.mailbox.lastTry'), width: 130, render: (_: any, f: any) => fmtMin(f.last_try_at) },
                  ]} />
              </Space>
            </Descriptions.Item>
          )}
        </DetailBlock>
      ))}

      <Alert style={{ margin: '14px 0' }} type="info" showIcon message={t('pages.recruit.mailbox.howto')} />
      <Form form={form} layout="vertical" initialValues={{ enabled: true, skip_existing: false }}>
        <Form.Item name="username" label={t('pages.recruit.mailbox.address')} rules={[{ required: true, type: 'email', message: t('pages.recruit.err.mailboxAddress') }]}
          extra={t('pages.recruit.mailbox.addressHint')}>
          <Input placeholder="hr@example.com" autoComplete="off" />
        </Form.Item>
        <Form.Item name="name" label={t('pages.recruit.mailbox.name')}><Input maxLength={100} /></Form.Item>
        <Form.Item name="password" label={t('pages.recruit.mailbox.password')} rules={editId ? [] : [{ required: true, message: t('pages.recruit.err.mailboxPassword') }]}
          extra={editId ? t('pages.recruit.mailbox.passwordKeep') : t('pages.recruit.mailbox.passwordHint')}>
          <Input.Password autoComplete="new-password" placeholder="xxxx xxxx xxxx xxxx" />
        </Form.Item>
        <Form.Item name="enabled" valuePropName="checked"><Checkbox>{t('pages.recruit.mailbox.enableNow')}</Checkbox></Form.Item>
        <Form.Item name="skip_existing" valuePropName="checked" extra={t('pages.recruit.mailbox.skipHint')}>
          <Checkbox>{t('pages.recruit.mailbox.skip')}</Checkbox>
        </Form.Item>
        <Space>
          <Button type="primary" loading={saving} onClick={save}>{editId ? t('pages.recruit.save') : t('pages.recruit.mailbox.add')}</Button>
          {editId > 0 && <Button onClick={() => { setEditId(0); form.resetFields(); }}>{t('pages.recruit.cancel')}</Button>}
          {saving && <Tag>{t('pages.recruit.mailbox.testing')}</Tag>}
        </Space>
      </Form>

      <Divider orientation="left">{t('pages.recruit.source.title')}</Divider>
      <Alert type="info" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.source.hint')} />
      <Table rowKey="id" size="small" pagination={false} dataSource={sources} columns={[
        { title: t('pages.recruit.source.address'), render: (_: any, r: any) => (
            <><Text strong copyable={{ text: plusOf(r.code) }}>{plusOf(r.code)}</Text>
              <div><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.source.resumes', { n: r.resumes })}</Text></div></>) },
        { title: t('pages.recruit.source.user'), width: 200, render: (_: any, r: any) => (
            <Select size="small" style={{ width: 180 }} showSearch optionFilterProp="label" value={Number(r.user_id) || undefined}
              options={userOptions} onChange={(v) => saveSource({ id: r.id, user_id: v, active: r.active })} />) },
        { title: t('pages.recruit.source.active'), width: 80, render: (_: any, r: any) => (
            <Switch size="small" checked={Number(r.active) === 1} onChange={(v) => saveSource({ id: r.id, user_id: r.user_id, active: v ? 1 : 0 })} />) },
      ]} />
      <Space.Compact style={{ marginTop: 10 }}>
        <Input style={{ width: 140 }} placeholder={t('pages.recruit.source.codePh')} value={newCode.code} maxLength={16}
          onChange={(e) => setNewCode({ ...newCode, code: e.target.value.toLowerCase().replace(/[^a-z0-9]/g, '') })} />
        <Select style={{ width: 200 }} showSearch optionFilterProp="label" placeholder={t('pages.recruit.source.user')} value={newCode.user_id}
          options={userOptions} onChange={(v) => setNewCode({ ...newCode, user_id: v })} />
        <Button type="primary" disabled={!newCode.code || !newCode.user_id}
          onClick={async () => { if (await saveSource({ ...newCode, active: 1 })) setNewCode({}); }}>{t('pages.recruit.source.add')}</Button>
      </Space.Compact>
      {!!newCode.code && <div><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.source.preview', { addr: plusOf(newCode.code) })}</Text></div>}

      <Divider orientation="left">{t('pages.recruit.block.title')}</Divider>
      <Alert type="info" showIcon style={{ marginBottom: 10 }} message={t('pages.recruit.block.hint')} />
      <SenderBlockTable />
    </div>
  );
};

export default MailboxPanel;
