/**
 * 商务条款（recruit_admin：每份合同保证期都不一样，条款只有招聘管理员能改）。
 * 合作模式切换 → 计费方式跟着变（月费三种只能按人按月）；收款里程碑合计必须 100%（或全 0 = 财务手工开单）。
 * 两列表单（字段 > 8，§6.5）；二级抽屉，不用 Modal。后端 recruitSaveProjectTermsCore 同样校验。
 */
import React, { useEffect, useMemo, useState } from 'react';
import { Drawer, Form, Segmented, InputNumber, Select, DatePicker, Input, Row, Col, Space, Button, Typography, Alert, Switch, message } from 'antd';
import dayjs from 'dayjs';
import { DETAIL_WIDTH } from '@/components/DetailPanel';
import { recruitSaveProjectTerms, recruitSaveJobTerms } from '@/services/api';
import { showErr, calcPlacementFee, money, feeText, guaranteeText, MONTHLY_SERVICES, T } from '../common';

const { Text } = Typography;
const MILESTONES = ['offer_accepted', 'start', 'guarantee_end'];

/**
 * job 传了 = 编辑某个职位的条款（混合项目：一个项目里有的职位走猎头、有的走 EOR / RPO）。
 * 职位条款要么「沿用项目默认」，要么整组单独约定；币种、合同、签约主体只在项目上。
 */
const ProjectTermsDrawer: React.FC<{ open: boolean; project: any; job?: any; meta: any; t: T; onClose: () => void; onSaved: () => void }> =
({ open, project, job, meta, t, onClose, onSaved }) => {
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const isJob = !!job;
  const [inherit, setInherit] = useState(false);
  const [ms, setMs] = useState<Record<string, number>>({});
  const svc = Form.useWatch('service_type', form) || 'contingency';
  const feeType = Form.useWatch('fee_type', form);
  const remedy = Form.useWatch('guarantee_remedy', form);
  const pct = Form.useWatch('fee_percent', form);
  const basis = Form.useWatch('fee_basis_months', form);
  const ccy = Form.useWatch('currency', form) || 'IDR';
  const monthly = MONTHLY_SERVICES.includes(svc);
  const lc = meta?.lc || {};

  useEffect(() => {
    if (!open || !project) return;
    form.resetFields();
    setInherit(isJob && !job.own);
    // 职位：没单独定过就拿项目默认条款当起点
    const p = isJob ? { ...project, ...(job.own ? job : {}) } : project;
    const d = (v: any) => (v ? dayjs(v) : undefined);
    form.setFieldsValue({
      service_type: p.service_type || 'contingency', fee_type: p.fee_type || 'percent_of_salary',
      fee_percent: p.fee_percent !== null && p.fee_percent !== undefined ? Number(p.fee_percent) : undefined,
      fee_basis_months: Number(p.fee_basis_months || 12), flat_fee: p.flat_fee !== null ? Number(p.flat_fee) || undefined : undefined,
      monthly_fee: p.monthly_fee !== null ? Number(p.monthly_fee) || undefined : undefined, currency: p.currency || 'IDR',
      guarantee_days: Number(p.guarantee_days ?? 90), guarantee_remedy: p.guarantee_remedy || 'replacement', replacement_count: Number(p.replacement_count ?? 1),
      deposit_amount: p.deposit_amount !== null ? Number(p.deposit_amount) || undefined : undefined,
      contract_status: p.contract_status || 'draft', contract_signed_at: d(p.contract_signed_at), contract_start: d(p.contract_start), contract_end: d(p.contract_end),
      client_entity_id: Number(p.client_entity_id) > 0 ? Number(p.client_entity_id) : undefined, terms_note: p.terms_note,
      bill_day: Number(p.billing_terms?.bill_day || 1),
    });
    const m: Record<string, number> = {};
    (p.billing_terms?.milestones || []).forEach((x: any) => { m[x.code] = Number(x.percent); });
    setMs(m);
  }, [open, project, job, isJob, form]);

  // 换模式：计费方式与里程碑给默认值（与后端 recruitDefaultBillingTerms 一致）
  const onSvc = (v: string) => {
    const mon = MONTHLY_SERVICES.includes(v);
    form.setFieldValue('fee_type', mon ? 'monthly' : (form.getFieldValue('fee_type') === 'monthly' ? 'percent_of_salary' : form.getFieldValue('fee_type')));
    setMs(mon ? {} : v === 'retained' ? { offer_accepted: 30, start: 70 } : { start: 100 });
  };
  const sum = MILESTONES.reduce((a, c) => a + Number(ms[c] || 0), 0);
  const sumOk = monthly || sum === 0 || sum === 100;
  const sample = useMemo(() => calcPlacementFee({ fee_type: 'percent_of_salary', fee_percent: pct, fee_basis_months: basis }, 10000000), [pct, basis]);

  const submit = async () => {
    if (isJob && inherit) {
      setSaving(true);
      try {
        const res = await recruitSaveJobTerms({ id: job.id, inherit: 1 });
        if (res?.success) { message.success(t('pages.recruit.msg.saved')); onSaved(); } else showErr(res, t);
      } finally { setSaving(false); }
      return;
    }
    const v = await form.validateFields();
    if (!sumOk) { message.error(t('pages.recruit.err.milestoneSum')); return; }
    const f = (x: any) => (x ? dayjs(x).format('YYYY-MM-DD') : '');
    const billing = monthly ? { bill_day: v.bill_day || 1 } : { milestones: MILESTONES.filter((c) => ms[c] !== undefined && ms[c] !== null).map((c) => ({ code: c, percent: Number(ms[c] || 0) })) };
    if (isJob) {
      setSaving(true);
      try {
        const res = await recruitSaveJobTerms({ id: job.id, service_type: v.service_type, fee_type: v.fee_type, fee_percent: v.fee_percent,
          fee_basis_months: v.fee_basis_months, flat_fee: v.flat_fee, monthly_fee: v.monthly_fee, guarantee_days: v.guarantee_days,
          guarantee_remedy: v.guarantee_remedy, replacement_count: v.replacement_count, billing_terms: billing });
        if (res?.success) { message.success(t('pages.recruit.msg.saved')); onSaved(); } else showErr(res, t);
      } finally { setSaving(false); }
      return;
    }
    setSaving(true);
    try {
      const res = await recruitSaveProjectTerms({
        ...v, id: project.id, contract_signed_at: f(v.contract_signed_at), contract_start: f(v.contract_start), contract_end: f(v.contract_end),
        client_entity_id: v.client_entity_id || 0,
        billing_terms: billing,
      });
      if (res?.success) { message.success(t('pages.recruit.msg.saved')); onSaved(); }
      else showErr(res, t);
    } finally { setSaving(false); }
  };

  return (
    <Drawer open={open} onClose={onClose} width={DETAIL_WIDTH.info} destroyOnClose
      title={isJob ? `${t('pages.recruit.terms.jobTitle')} · ${job.title}` : t('pages.recruit.terms.title')}
      extra={<Space><Button onClick={onClose}>{t('pages.recruit.cancel')}</Button><Button type="primary" loading={saving} onClick={submit}>{t('pages.recruit.save')}</Button></Space>}>
      {isJob && (
        <div style={{ marginBottom: 16 }}>
          <Space><Switch checked={inherit} onChange={setInherit} /><Text strong>{t('pages.recruit.terms.inherit')}</Text></Space>
          {inherit && (
            <Alert style={{ marginTop: 10 }} type="info" showIcon message={t('pages.recruit.terms.inheritHint')}
              description={`${t(`pages.recruit.svc.${project?.service_type}`)} · ${feeText(project, t)} · ${guaranteeText(project, t)}`} />)}
        </div>
      )}
      <Form form={form} layout="vertical" style={isJob && inherit ? { display: 'none' } : undefined}>
        <Form.Item name="service_type" label={t('pages.recruit.terms.serviceType')} extra={t(`pages.recruit.svcHint.${svc}`)}>
          <Segmented onChange={(v) => onSvc(String(v))} options={(lc.service_type || []).map((s: string) => ({ value: s, label: t(`pages.recruit.svc.${s}`) }))} />
        </Form.Item>
        <Row gutter={16}>
          <Col span={12}>
            <Form.Item name="fee_type" label={t('pages.recruit.terms.feeType')}>
              <Select options={(lc.fee_type || []).filter((f: string) => (monthly ? f === 'monthly' : f !== 'monthly'))
                .map((f: string) => ({ value: f, label: t(`pages.recruit.feeType.${f}`) }))} />
            </Form.Item>
          </Col>
          {!isJob && (
            <Col span={12}>
              <Form.Item name="currency" label={t('pages.recruit.terms.currency')}>
                <Select options={(lc.currency || ['IDR']).map((c: string) => ({ value: c, label: c }))} />
              </Form.Item>
            </Col>)}
          {feeType === 'percent_of_salary' && (
            <>
              <Col span={12}>
                <Form.Item name="fee_percent" label={t('pages.recruit.terms.feePercent')} rules={[{ required: true, message: t('pages.recruit.err.feePercentRequired') }]}
                  extra={sample !== null ? t('pages.recruit.terms.feeSample', { fee: money(sample, 'IDR') }) : undefined}>
                  <InputNumber min={0} max={100} step={0.5} addonAfter="%" style={{ width: '100%' }} />
                </Form.Item>
              </Col>
              <Col span={12}>
                <Form.Item name="fee_basis_months" label={t('pages.recruit.terms.basisMonths')} extra={t('pages.recruit.terms.basisHint')}>
                  <InputNumber min={1} max={24} style={{ width: '100%' }} />
                </Form.Item>
              </Col>
            </>
          )}
          {feeType === 'flat_per_hire' && (
            <Col span={12}>
              <Form.Item name="flat_fee" label={t('pages.recruit.terms.flatFee')} rules={[{ required: true, message: t('pages.recruit.err.flatFeeRequired') }]}>
                <InputNumber min={0} style={{ width: '100%' }} addonBefore={ccy} formatter={(v) => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
              </Form.Item>
            </Col>
          )}
          {feeType === 'monthly' && (
            <>
              <Col span={12}>
                <Form.Item name="monthly_fee" label={t('pages.recruit.terms.monthlyFee')} rules={[{ required: true, message: t('pages.recruit.err.monthlyFeeRequired') }]}>
                  <InputNumber min={0} style={{ width: '100%' }} addonBefore={ccy} formatter={(v) => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
                </Form.Item>
              </Col>
              <Col span={12}>
                <Form.Item name="bill_day" label={t('pages.recruit.terms.billDay')}><InputNumber min={1} max={28} style={{ width: '100%' }} /></Form.Item>
              </Col>
            </>
          )}
          <Col span={12}>
            <Form.Item name="guarantee_days" label={t('pages.recruit.terms.guaranteeDays')}>
              <InputNumber min={0} max={365} addonAfter={t('pages.recruit.terms.dayUnit')} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="guarantee_remedy" label={t('pages.recruit.terms.remedy')}>
              <Select options={(lc.remedy || []).map((r: string) => ({ value: r, label: t(`pages.recruit.remedy.${r}`) }))} />
            </Form.Item>
          </Col>
          {remedy === 'replacement' && (
            <Col span={12}>
              <Form.Item name="replacement_count" label={t('pages.recruit.terms.replacementCount')}><InputNumber min={0} max={5} style={{ width: '100%' }} /></Form.Item>
            </Col>
          )}
          {svc === 'retained' && !isJob && (
            <Col span={12}>
              <Form.Item name="deposit_amount" label={t('pages.recruit.terms.deposit')}>
                <InputNumber min={0} style={{ width: '100%' }} addonBefore={ccy} formatter={(v) => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
              </Form.Item>
            </Col>
          )}
        </Row>

        {!monthly && (
          <Form.Item label={t('pages.recruit.terms.milestones')} extra={t('pages.recruit.terms.milestonesHint')}>
            <Space direction="vertical" style={{ width: '100%' }}>
              {MILESTONES.map((c) => (
                <Space key={c}>
                  <Text style={{ display: 'inline-block', width: 160 }}>{t(`pages.recruit.milestone.${c}`)}</Text>
                  <InputNumber min={0} max={100} addonAfter="%" value={ms[c]} onChange={(v) => setMs((p) => ({ ...p, [c]: v === null ? (undefined as any) : Number(v) }))} />
                </Space>))}
              {!sumOk && <Alert type="error" showIcon message={t('pages.recruit.terms.sumBad', { sum })} />}
            </Space>
          </Form.Item>
        )}

        {!isJob && (<>
        <Row gutter={16}>
          <Col span={12}>
            <Form.Item name="contract_status" label={t('pages.recruit.terms.contractStatus')}>
              <Select options={(lc.contract_status || []).map((s: string) => ({ value: s, label: t(`pages.recruit.contract.${s}`) }))} />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="contract_signed_at" label={t('pages.recruit.terms.signedAt')}><DatePicker style={{ width: '100%' }} /></Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="contract_start" label={t('pages.recruit.terms.contractStart')}><DatePicker style={{ width: '100%' }} /></Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="contract_end" label={t('pages.recruit.terms.contractEnd')}><DatePicker style={{ width: '100%' }} /></Form.Item>
          </Col>
        </Row>
        <Form.Item name="client_entity_id" label={t('pages.recruit.terms.entity')} extra={!(project?.entities || []).length ? t('pages.recruit.terms.noEntity') : undefined}>
          <Select allowClear options={(project?.entities || []).map((e: any) => ({ value: Number(e.id), label: e.entity_name }))} />
        </Form.Item>
        <Form.Item name="terms_note" label={t('pages.recruit.terms.note')}>
          <Input.TextArea rows={3} maxLength={2000} placeholder={t('pages.recruit.terms.notePh')} />
        </Form.Item>
        </>)}
      </Form>
    </Drawer>
  );
};

export default ProjectTermsDrawer;
