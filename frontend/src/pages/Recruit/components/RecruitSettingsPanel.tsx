/**
 * 系统设置 · 招聘设置（「这些应该放到系统设置里管理」）。
 * 原先要手改 system_settings 的开关与参数都在这里：自动解析总开关、AI 单价与每日预算、保温提醒、语义匹配参数、
 * 高分人选提醒（接收人 + 分数线）、招聘抄送人（关键事件与保温汇总都抄送）、推荐信模板（RecoTemplatePanel）、招聘邮箱与专员代码；以及「招聘权限」——谁能进招聘、谁能看全部（按人开关，角色授予的在「权限配置」改）。
 * 后端：recruitConfigGet / recruitConfigSave（recruit_admin），数值越界会被夹回合理范围。
 */
import React, { useCallback, useEffect, useState } from 'react';
import { Card, Form, Switch, InputNumber, Button, Space, Alert, Typography, Row, Col, Statistic, Tabs, Spin, Select, message } from 'antd';
import { useIntl } from '@umijs/max';
import { recruitConfigGet, recruitConfigSave } from '@/services/api';
import { showErr } from '../common';
import MailboxPanel from './MailboxPanel';
import RecruitPermPanel from './RecruitPermPanel';
import RecoTemplatePanel from './RecoTemplatePanel';
import PromptLabPanel from './PromptLabPanel';

const { Text } = Typography;
const FACETS = ['skills', 'experience', 'industry', 'headline'];

const RecruitSettingsPanel: React.FC = () => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [cfg, setCfg] = useState<any>(null);
  const [saving, setSaving] = useState(false);
  const [form] = Form.useForm();

  const load = useCallback(async () => {
    const r = await recruitConfigGet();
    if (r?.success) { setCfg(r.data); form.setFieldsValue(r.data); } else showErr(r, t);
  }, [form, t]);
  useEffect(() => { load(); }, [load]);

  const save = async (part: string[]) => {
    const v = form.getFieldsValue(true);
    setSaving(true);
    try {
      const r = await recruitConfigSave(Object.fromEntries(part.map((k) => [k, v[k]])));
      if (r?.success) { message.success(t('pages.recruit.msg.saved')); setCfg((c: any) => ({ ...c, ...r.data })); form.setFieldsValue(r.data); } else showErr(r, t);
    } finally { setSaving(false); }
  };
  const saveBtn = (part: string[]) => <Button type="primary" loading={saving} onClick={() => save(part)}>{t('pages.recruit.save')}</Button>;

  if (!cfg) return <Spin style={{ display: 'block', margin: '60px auto' }} />;
  const u = cfg.usage || {};

  const aiTab = (
    <Space direction="vertical" size={16} style={{ width: '100%', maxWidth: 820 }}>
      <Card size="small" title={t('pages.recruit.cfg.parseTitle')} extra={saveBtn(['parse_enabled'])}>
        <Form.Item name="parse_enabled" valuePropName="checked" label={t('pages.recruit.cfg.parseEnabled')} extra={t('pages.recruit.cfg.parseEnabledHint')}>
          <Switch />
        </Form.Item>
        <Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.cfg.model', { model: cfg.model || '-' })}</Text>
      </Card>
      <Card size="small" title={t('pages.recruit.cfg.costTitle')} extra={saveBtn(['price', 'daily_token_budget'])}>
        <Row gutter={16} style={{ marginBottom: 12 }}>
          <Col span={6}><Statistic title={t('pages.recruit.cfg.monthUsd')} value={Number(u.month_usd || 0)} precision={4} prefix="$" /></Col>
          <Col span={6}><Statistic title={t('pages.recruit.cfg.monthTokens')} value={Number(u.month_tokens || 0)} /></Col>
          <Col span={6}><Statistic title={t('pages.recruit.cfg.perResume')} value={u.per_resume_tokens ?? '-'} /></Col>
          <Col span={6}><Statistic title={t('pages.recruit.cfg.today')} value={Number(u.today_tokens || 0)} suffix={`/ ${Number(cfg.daily_token_budget).toLocaleString()}`} /></Col>
        </Row>
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.cfg.priceHint')} />
        <Row gutter={16}>
          <Col span={8}><Form.Item name={['price', 'chat_in']} label={t('pages.recruit.cfg.chatIn')}><InputNumber min={0} max={100} step={0.01} addonBefore="$" style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={8}><Form.Item name={['price', 'chat_out']} label={t('pages.recruit.cfg.chatOut')}><InputNumber min={0} max={100} step={0.01} addonBefore="$" style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={8}><Form.Item name={['price', 'embed_in']} label={t('pages.recruit.cfg.embedIn')}><InputNumber min={0} max={100} step={0.01} addonBefore="$" style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        <Form.Item name="daily_token_budget" label={t('pages.recruit.cfg.budget')} extra={t('pages.recruit.cfg.budgetHint')}>
          <InputNumber min={10000} max={100000000} step={100000} style={{ width: 240 }} formatter={(v) => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
        </Form.Item>
      </Card>
    </Space>
  );

  const warmTab = (
    <Card size="small" style={{ maxWidth: 820 }} title={t('pages.recruit.cfg.warmTitle')} extra={saveBtn(['warm_remind_enabled', 'warm'])}>
      <Form.Item name="warm_remind_enabled" valuePropName="checked" label={t('pages.recruit.cfg.warmEnabled')} extra={t('pages.recruit.cfg.warmEnabledHint')}><Switch /></Form.Item>
      <Row gutter={16}>
        <Col span={8}><Form.Item name={['warm', 'active_days']} label={t('pages.recruit.cfg.activeDays')}><InputNumber min={1} max={60} addonAfter={t('pages.recruit.cfg.days')} style={{ width: '100%' }} /></Form.Item></Col>
        <Col span={8}><Form.Item name={['warm', 'pool_days']} label={t('pages.recruit.cfg.poolDays')}><InputNumber min={1} max={180} addonAfter={t('pages.recruit.cfg.days')} style={{ width: '100%' }} /></Form.Item></Col>
        <Col span={8}><Form.Item name={['warm', 'strong_score']} label={t('pages.recruit.cfg.strongScore')}><InputNumber min={0} max={5} step={0.5} style={{ width: '100%' }} /></Form.Item></Col>
      </Row>
    </Card>
  );

  const semTab = (
    <Card size="small" style={{ maxWidth: 820 }} title={t('pages.recruit.cfg.semTitle')} extra={saveBtn(['sem'])}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.cfg.semHint')} />
      <Row gutter={16}>
        {FACETS.map((f) => (
          <Col key={f} span={6}><Form.Item name={['sem', 'weights', f]} label={t('pages.recruit.cfg.weight', { f: t(`pages.recruit.facet.${f}`) })}>
            <InputNumber min={0} max={1} step={0.05} style={{ width: '100%' }} /></Form.Item></Col>))}
      </Row>
      <Row gutter={16}>
        <Col span={6}><Form.Item name={['sem', 'threshold']} label={t('pages.recruit.cfg.threshold')}><InputNumber min={0} max={1} step={0.05} style={{ width: '100%' }} /></Form.Item></Col>
        <Col span={6}><Form.Item name={['sem', 'top_per_job']} label={t('pages.recruit.cfg.topJob')}><InputNumber min={1} max={500} style={{ width: '100%' }} /></Form.Item></Col>
        <Col span={6}><Form.Item name={['sem', 'top_per_candidate']} label={t('pages.recruit.cfg.topCand')}><InputNumber min={1} max={50} style={{ width: '100%' }} /></Form.Item></Col>
        <Col span={6}><Form.Item name={['sem', 'search_min']} label={t('pages.recruit.cfg.searchMin')}><InputNumber min={0} max={1} step={0.05} style={{ width: '100%' }} /></Form.Item></Col>
      </Row>
    </Card>
  );

  const userOptions = (cfg.users || []).map((x: any) => ({ value: Number(x.id), label: x.name }));
  const alertTab = (
    <Space direction="vertical" size={16} style={{ width: '100%', maxWidth: 820 }}>
    <Card size="small" title={t('pages.recruit.cfg.ccTitle')} extra={saveBtn(['notify_cc_user_ids'])}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.cfg.ccHint')} />
      <Form.Item name="notify_cc_user_ids" label={t('pages.recruit.cfg.ccUsers')}>
        <Select mode="multiple" allowClear showSearch optionFilterProp="label" options={userOptions} />
      </Form.Item>
    </Card>
    <Card size="small" title={t('pages.recruit.cfg.alertTitle')} extra={saveBtn(['alert'])}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.cfg.alertHint')} />
      <Form.Item name={['alert', 'enabled']} valuePropName="checked" label={t('pages.recruit.cfg.alertEnabled')}
        extra={cfg.alert?.enabled && cfg.alert?.since ? t('pages.recruit.cfg.alertSince', { at: String(cfg.alert.since).slice(0, 16) }) : t('pages.recruit.cfg.alertEnabledHint')}>
        <Switch />
      </Form.Item>
      <Row gutter={16}>
        <Col span={8}><Form.Item name={['alert', 'threshold']} label={t('pages.recruit.cfg.alertThreshold')}>
          <InputNumber min={1} max={5} step={0.5} style={{ width: '100%' }} /></Form.Item></Col>
        <Col span={16}><Form.Item name={['alert', 'user_ids']} label={t('pages.recruit.cfg.alertUsers')} extra={t('pages.recruit.cfg.alertUsersHint')}>
          <Select mode="multiple" allowClear showSearch optionFilterProp="label" options={userOptions} /></Form.Item></Col>
      </Row>
    </Card>
    </Space>
  );

  return (
    // component={false}：不渲染 <form>，邮箱页签里 MailboxPanel 自带表单，避免 form 嵌套
    <Form form={form} layout="vertical" component={false}>
      <Tabs items={[
        { key: 'perm', label: t('pages.recruit.perm.tab'), children: <RecruitPermPanel /> },
        { key: 'mailbox', label: t('pages.recruit.mailbox.title'), children: <MailboxPanel /> },
        { key: 'ai', label: t('pages.recruit.cfg.tabAi'), children: aiTab },
        { key: 'alert', label: t('pages.recruit.cfg.tabAlert'), children: alertTab },
        { key: 'recoTpl', label: t('pages.recruit.tpl.tab'), children: <RecoTemplatePanel /> },
        { key: 'prompt', label: t('pages.recruit.prompt.tab'), children: <PromptLabPanel /> },
        { key: 'warm', label: t('pages.recruit.cfg.tabWarm'), children: warmTab },
        { key: 'sem', label: t('pages.recruit.cfg.tabSem'), children: semTab },
      ]} />
    </Form>
  );
};

export default RecruitSettingsPanel;
