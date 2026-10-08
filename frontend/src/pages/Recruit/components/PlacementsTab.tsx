/**
 * 入职记录（placement）列表 + 登记 / 编辑 / 状态流转抽屉。项目总览「入职与保证期」页签与候选人抽屉「入职记录」页签共用这一份
 * （§6.7.3：同一笔业务只有一份详情组件）。
 * 状态机与权限以后端 recruit_lifecycle.php RECRUIT_PL_TRANSITIONS 为准（meta.lc.pl_actions 下发），这里只按它显隐按钮。
 * ⛔ 抽屉里不嵌 Modal（§6.3）：动作都开二级 Drawer。
 */
import React, { useEffect, useMemo, useState } from 'react';
import { Drawer, Form, Input, InputNumber, Select, DatePicker, Space, Button, Tag, Typography, Tooltip, Alert, message } from 'antd';
import { EditOutlined, SwapOutlined, WarningOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { DetailTable, DETAIL_WIDTH } from '@/components/DetailPanel';
import { recruitSavePlacement, recruitPlacementAction, recruitProjectPipeline } from '@/services/api';
import { showErr, fmtDay, money, candCode, calcPlacementFee, NO_HSCROLL, PL_STATUS_COLOR, SERVICE_COLOR, T } from '../common';

const { Text } = Typography;
const ACTION_ORDER = ['start', 'pass_guarantee', 'leave', 'no_show', 'backed_out', 'replace', 'refund_recorded', 'close'];
const day = (v: any) => (v ? dayjs(v).format('YYYY-MM-DD') : '');
const isDraft = (r: any) => r.status === 'offer_accepted' && (!r.expected_start_date || (r.offer_salary_monthly === null && r.fee_amount === null));

// ====================================================================== 登记 / 编辑
export const PlacementFormDrawer: React.FC<{
  open: boolean; t: T; meta: any; linkId?: number; placement?: any; project?: any; title?: string; onClose: () => void; onSaved: () => void;
}> = ({ open, t, meta, linkId, placement, project, title, onClose, onSaved }) => {
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const salary = Form.useWatch('offer_salary_monthly', form);
  const canAdmin = !!meta?.can_admin;
  const status = placement?.status || 'offer_accepted';
  const active = ['offer_accepted', 'started', 'guarantee_passed'].includes(status);
  useEffect(() => {
    if (!open) return;
    form.resetFields();
    const p = placement || {};
    const d = (v: any) => (v ? dayjs(v) : undefined);
    form.setFieldsValue({
      offer_salary_monthly: p.offer_salary_monthly !== null && p.offer_salary_monthly !== undefined ? Number(p.offer_salary_monthly) : undefined,
      allowances_text: p.allowances_text, offer_date: d(p.offer_date),
      offer_accepted_at: d(p.offer_accepted_at) || (placement ? undefined : dayjs()), expected_start_date: d(p.expected_start_date),
      actual_start_date: d(p.actual_start_date), notes: p.notes,
      fee_amount: p.fee_amount !== null && p.fee_amount !== undefined ? Number(p.fee_amount) : undefined,
    });
  }, [open, placement, project, form]);
  const preview = project ? calcPlacementFee(project, salary) : null;

  const submit = async () => {
    const v = await form.validateFields();
    const data: Record<string, any> = { notes: v.notes || '' };
    if (active) {
      Object.assign(data, { offer_salary_monthly: v.offer_salary_monthly ?? '', allowances_text: v.allowances_text || '',
        offer_date: day(v.offer_date), offer_accepted_at: day(v.offer_accepted_at), expected_start_date: day(v.expected_start_date) });
      if (placement && status !== 'offer_accepted') data.actual_start_date = day(v.actual_start_date);
    }
    // 费用：只有 admin 且真改了才传（传了 = 人工定价，之后改薪资不再自动重算）
    if (canAdmin && v.fee_amount !== undefined && (placement ? Number(v.fee_amount) !== Number(placement.fee_amount) : true) && v.fee_amount !== null) {
      data.fee_amount = v.fee_amount; data.fee_note = 'manual';
    }
    setSaving(true);
    try {
      const res = await recruitSavePlacement(placement ? { id: placement.id, ...data } : { candidate_job_id: linkId, ...data });
      if (res?.success) { message.success(t('pages.recruit.msg.saved')); onSaved(); } else showErr(res, t);
    } finally { setSaving(false); }
  };

  return (
    <Drawer open={open} onClose={onClose} width={DETAIL_WIDTH.form} destroyOnClose title={title || t(placement ? 'pages.recruit.pl.edit' : 'pages.recruit.pl.register')}
      extra={<Space><Button onClick={onClose}>{t('pages.recruit.cancel')}</Button><Button type="primary" loading={saving} onClick={submit}>{t('pages.recruit.save')}</Button></Space>}>
      {!active && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={t('pages.recruit.pl.terminalEdit')} />}
      <Form form={form} layout="vertical" disabled={false}>
        {active && (
          <>
            {/* 币种跟项目合同走（后端校验），这里不让选 */}
            <Form.Item name="offer_salary_monthly" label={t('pages.recruit.pl.salary')}
              extra={preview !== null ? t('pages.recruit.pl.feePreview', { fee: money(preview, project?.currency) }) : t('pages.recruit.pl.ccyHint')}>
              <InputNumber min={0} style={{ width: '100%' }} addonBefore={placement?.currency || project?.currency || undefined}
                formatter={(x) => `${x}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
            </Form.Item>
            <Form.Item name="allowances_text" label={t('pages.recruit.pl.allowances')}><Input maxLength={255} /></Form.Item>
            <Space style={{ width: '100%' }} size={12} wrap>
              <Form.Item name="offer_date" label={t('pages.recruit.pl.offerDate')}><DatePicker /></Form.Item>
              <Form.Item name="offer_accepted_at" label={t('pages.recruit.pl.acceptedAt')}><DatePicker /></Form.Item>
              <Form.Item name="expected_start_date" label={t('pages.recruit.pl.expectedStart')}
                rules={placement ? [] : [{ required: true, message: t('pages.recruit.err.plStartRequired') }]}><DatePicker /></Form.Item>
              {placement && status !== 'offer_accepted' && (
                <Form.Item name="actual_start_date" label={t('pages.recruit.pl.actualStart')} extra={t('pages.recruit.pl.actualStartHint')}><DatePicker /></Form.Item>
              )}
            </Space>
          </>
        )}
        {canAdmin && (
          <Form.Item name="fee_amount" label={t('pages.recruit.pl.fee')} extra={t('pages.recruit.pl.feeAdminHint')}>
            <InputNumber min={0} style={{ width: '100%' }} formatter={(x) => `${x}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
          </Form.Item>
        )}
        <Form.Item name="notes" label={t('pages.recruit.pl.notes')}><Input.TextArea rows={3} maxLength={2000} /></Form.Item>
      </Form>
    </Drawer>
  );
};

// ====================================================================== 状态流转
const ActionDrawer: React.FC<{ open: boolean; t: T; meta: any; row: any; action: string; onClose: () => void; onDone: () => void }> =
({ open, t, meta, row, action, onClose, onDone }) => {
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const [links, setLinks] = useState<any[]>([]);
  const reasons = meta?.lc?.reason || {};
  useEffect(() => {
    if (!open) return;
    form.resetFields();
    // 入职日默认：预计入职日已过就取它，否则今天
    form.setFieldsValue({ actual_start_date: row?.expected_start_date && dayjs(row.expected_start_date).isBefore(dayjs()) ? dayjs(row.expected_start_date) : dayjs(),
      left_at: dayjs() });
    if (action === 'replace' && row) {
      // 替补人选：同一职位上、还在流程里的其他人
      recruitProjectPipeline(Number(row.project_id), 'candidates').then((r: any) => {
        setLinks((r?.data || []).filter((l: any) => Number(l.job_id) === Number(row.job_id) && Number(l.candidate_id) !== Number(row.candidate_id)
          && !['rejected', 'withdrawn', 'removed', 'suggested'].includes(l.stage)));
      });
    }
  }, [open, row, action, form]);

  const submit = async () => {
    const v = await form.validateFields();
    const payload: Record<string, any> = { ...v };
    ['actual_start_date', 'left_at', 'expected_start_date'].forEach((k) => { if (v[k]) payload[k] = day(v[k]); });
    setSaving(true);
    try {
      const res = await recruitPlacementAction(Number(row.id), action, payload);
      if (res?.success) { message.success(t('pages.recruit.msg.saved')); onDone(); }
      else if (res?.message_key === 'pages.recruit.err.plReplaceQuota') message.error(t(res.message_key, { n: res.quota }));
      else if (res?.message_key === 'pages.recruit.err.plGuaranteeNotDue') message.error(t(res.message_key, { end: res.end }));
      else showErr(res, t);
    } finally { setSaving(false); }
  };
  const reasonSel = (group: string) => (
    <Form.Item name={group === 'leave' ? 'left_reason_code' : 'reason_code'} label={t('pages.recruit.fu.reason')} rules={[{ required: true, message: t('pages.recruit.err.reasonRequired') }]}>
      <Select options={(reasons[group] || []).map((c: string) => ({ value: c, label: t(`pages.recruit.reason.${group}.${c}`) }))} />
    </Form.Item>
  );

  return (
    <Drawer open={open} onClose={onClose} width={DETAIL_WIDTH.form} destroyOnClose
      title={row ? `${t(`pages.recruit.plAction.${action}`)} · ${row.cand_name || candCode(row.candidate_id)}` : ''}
      extra={<Space><Button onClick={onClose}>{t('pages.recruit.cancel')}</Button><Button type="primary" danger={['leave', 'no_show', 'backed_out'].includes(action)} loading={saving} onClick={submit}>{t('pages.recruit.ok')}</Button></Space>}>
      <Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>{t(`pages.recruit.plActionHint.${action}`, { end: fmtDay(row?.guarantee_end_date), days: row?.guarantee_days })}</Text>
      <Form form={form} layout="vertical">
        {action === 'start' && <Form.Item name="actual_start_date" label={t('pages.recruit.pl.actualStart')} rules={[{ required: true }]}><DatePicker /></Form.Item>}
        {action === 'leave' && <><Form.Item name="left_at" label={t('pages.recruit.pl.leftAt')} rules={[{ required: true }]}><DatePicker /></Form.Item>{reasonSel('leave')}</>}
        {(action === 'no_show' || action === 'backed_out') && reasonSel('candidate')}
        {action === 'replace' && (
          <>
            <Form.Item name="candidate_job_id" label={t('pages.recruit.pl.replaceWith')} rules={[{ required: true, message: t('pages.recruit.err.plReplaceLinkRequired') }]}
              extra={!links.length ? t('pages.recruit.pl.replaceNone') : undefined}>
              <Select showSearch optionFilterProp="label" options={links.map((l: any) => ({ value: Number(l.id),
                label: `${l.cand_code} ${l.cand_name || ''} · ${t(`pages.recruit.stage.${l.stage}`)}` }))} />
            </Form.Item>
            <Form.Item name="expected_start_date" label={t('pages.recruit.pl.expectedStart')}><DatePicker /></Form.Item>
          </>
        )}
        <Form.Item name="notes" label={t('pages.recruit.pl.notes')}><Input.TextArea rows={3} maxLength={2000} /></Form.Item>
      </Form>
    </Drawer>
  );
};

// ====================================================================== 列表
const PlacementsTab: React.FC<{
  rows: any[]; t: T; meta: any; mode: 'project' | 'candidate'; project?: any; onChanged: () => void; onOpenCandidate?: (id: number) => void;
}> = ({ rows, t, meta, mode, project, onChanged, onOpenCandidate }) => {
  const [act, setAct] = useState<{ row: any; action: string } | null>(null);
  const [edit, setEdit] = useState<any>(null);
  const canAdmin = !!meta?.can_admin;
  const acts = meta?.lc?.pl_actions || {};
  const actionsOf = (r: any) => ACTION_ORDER.filter((a) => acts[a] && acts[a].from.includes(r.status) && (!acts[a].admin || canAdmin)
    && !(a === 'replace' && r.guarantee_remedy !== 'replacement'));
  const byId = useMemo(() => Object.fromEntries(rows.map((r) => [Number(r.id), r])), [rows]);

  return (
    <>
      <DetailTable rowKey="id" dataSource={rows} pagination={false} {...NO_HSCROLL}
        rowClassName={() => (onOpenCandidate ? 'recruit-row' : '')}
        onRow={(r: any) => ({ onClick: (e: any) => { if ((e.target as HTMLElement).closest('button,a,.ant-select')) return; onOpenCandidate?.(Number(r.candidate_id)); } })}
        columns={[
          { title: mode === 'project' ? t('pages.recruit.pl.col.who') : t('pages.recruit.pl.col.where'), render: (_: any, r: any) => (
              <div>
                {mode === 'project'
                  ? <><Text strong>{r.cand_name || '-'}</Text> <Text type="secondary" style={{ fontSize: 12 }}>{r.cand_code}</Text></>
                  : <Text strong>{r.project_name}{r.customer_group_name ? ` · ${r.customer_group_name}` : ''}</Text>}
                <div style={{ fontSize: 12, color: '#8c8c8c' }}>{r.job_title}{mode === 'project' && r.owner_user_name ? ` · ${r.owner_user_name}` : ''}</div>
              </div>) },
          { title: t('pages.recruit.col.status'), width: 150, render: (_: any, r: any) => (
              <Space direction="vertical" size={2}>
                <Tag color={PL_STATUS_COLOR[r.status]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.plStatus.${r.status}`)}</Tag>
                {!!r.service_type && <Tag color={SERVICE_COLOR[r.service_type]} style={{ marginInlineEnd: 0 }}>{t(`pages.recruit.svc.${r.service_type}`)}</Tag>}
                {Number(r.replacement_of_placement_id) > 0 && (
                  <Tooltip title={byId[Number(r.replacement_of_placement_id)]?.cand_name}>
                    <Tag icon={<SwapOutlined />} color="purple" style={{ marginInlineEnd: 0 }}>{t('pages.recruit.pl.replacementN', { n: r.replacement_seq })}</Tag>
                  </Tooltip>)}
                {isDraft(r) && <Tag icon={<WarningOutlined />} color="warning" style={{ marginInlineEnd: 0 }}>{t('pages.recruit.pl.draft')}</Tag>}
                {!!r.left_reason_code && <Text type="secondary" style={{ fontSize: 12 }}>
                  {t(`pages.recruit.reason.${['no_show', 'backed_out'].includes(r.status) ? 'candidate' : 'leave'}.${r.left_reason_code}`)}</Text>}
              </Space>) },
          { title: t('pages.recruit.pl.salary'), width: 120, align: 'right' as const, render: (_: any, r: any) => money(r.offer_salary_monthly, r.currency) },
          { title: t('pages.recruit.pl.col.start'), width: 130, render: (_: any, r: any) => (
              <div style={{ fontSize: 12 }}>
                {r.actual_start_date ? <div>{fmtDay(r.actual_start_date)}</div> : <Text type="secondary">{t('pages.recruit.pl.expected')} {fmtDay(r.expected_start_date)}</Text>}
                {!!r.left_at && <div style={{ color: '#cf1322' }}>{t('pages.recruit.pl.leftAt')} {fmtDay(r.left_at)}</div>}
              </div>) },
          { title: t('pages.recruit.pl.col.guarantee'), width: 130, render: (_: any, r: any) => (
              <div style={{ fontSize: 12 }}>
                <div>{r.guarantee_end_date ? fmtDay(r.guarantee_end_date) : t('pages.recruit.terms.days', { n: r.guarantee_days })}</div>
                {r.guarantee_left !== null && r.guarantee_left !== undefined && (
                  <Text type={r.guarantee_left < 0 ? 'warning' : r.guarantee_left <= 7 ? 'danger' : 'secondary'}>
                    {r.guarantee_left < 0 ? t('pages.recruit.pl.guaranteeDue') : t('pages.recruit.pl.daysLeft', { n: r.guarantee_left })}</Text>)}
              </div>) },
          { title: t('pages.recruit.pl.fee'), width: 120, align: 'right' as const, render: (_: any, r: any) => (
              <div>
                <div>{money(r.fee_amount, r.currency)}</div>
                {r.expected_refund_amount !== null && r.expected_refund_amount !== undefined && (
                  <Text type="danger" style={{ fontSize: 12 }}>{t('pages.recruit.pl.refund')} {money(r.expected_refund_amount, r.currency)}</Text>)}
              </div>) },
          { title: '', width: 150, render: (_: any, r: any) => (
              <Space direction="vertical" size={2} align="end" style={{ width: '100%' }}>
                {actionsOf(r).map((a) => (
                  <Button key={a} size="small" type={a === 'start' || a === 'pass_guarantee' ? 'primary' : 'default'} ghost={a === 'start' || a === 'pass_guarantee'}
                    danger={['leave', 'no_show', 'backed_out'].includes(a)} onClick={() => setAct({ row: r, action: a })}>{t(`pages.recruit.plAction.${a}`)}</Button>))}
                <Button size="small" type="link" icon={<EditOutlined />} onClick={() => setEdit(r)}>{t('pages.recruit.edit')}</Button>
              </Space>) },
        ]} />
      <ActionDrawer open={!!act} t={t} meta={meta} row={act?.row} action={act?.action || ''} onClose={() => setAct(null)} onDone={() => { setAct(null); onChanged(); }} />
      <PlacementFormDrawer open={!!edit} t={t} meta={meta} placement={edit} project={project} onClose={() => setEdit(null)} onSaved={() => { setEdit(null); onChanged(); }} />
    </>
  );
};

export default PlacementsTab;
