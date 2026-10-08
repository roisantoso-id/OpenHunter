/**
 * 项目表单 / 职位表单 / 职位区（项目总览页用）（二级抽屉，不用 Modal——§6.3 不在 Drawer 里嵌 Modal）。
 * 客户可从客户列表搜索选择，搜不到时可当场新建。
 */
import React, { useCallback, useEffect, useState } from 'react';
import {
  Drawer, Tag, Space, Button, Typography, Empty, Form, Input, InputNumber, Select, Segmented, Row, Col, message, Tooltip, Checkbox,
} from 'antd';
import { PlusOutlined, EditOutlined, TeamOutlined, DeleteOutlined, CopyOutlined, CloudUploadOutlined, MailOutlined, ThunderboltOutlined, FileTextOutlined } from '@ant-design/icons';
import { useIntl, history, useModel } from '@umijs/max';
import { DETAIL_WIDTH } from '@/components/DetailPanel';
import { recruitSaveProject, recruitSaveJob, recruitSearchClients, recruitSplitJd, createRecruitClient } from '@/services/api';
import { showErr, SERVICE_COLOR } from '../common';
import PipelineButton from './PipelineButton';
import UploadDrawer from './UploadDrawer';
import JobPostingBox, { POST_PLATFORMS } from './JobPostingBox';
import JobApplyLink from './JobApplyLink';

const { Text } = Typography;

// ====================================================================== 项目表单
export const ProjectFormDrawer: React.FC<{ open: boolean; project?: any; meta: any; onClose: () => void; onSaved: (id: number) => void }> =
({ open, project, meta, onClose, onSaved }) => {
  const intl = useIntl();
  const t = (id: string, v?: any) => intl.formatMessage({ id }, v);
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const [custOpts, setCustOpts] = useState<any[]>([]);
  const [custKw, setCustKw] = useState('');
  const [creating, setCreating] = useState(false);
  const kind = Form.useWatch('kind', form);

  useEffect(() => {
    if (!open) return;
    form.resetFields();
    const p = project || {};
    form.setFieldsValue({ name: p.name, kind: p.kind || 'client', status: p.status || 'open', description: p.description,
      manager_user_id: p.manager_user_id ? Number(p.manager_user_id) : undefined,
      customer_id: p.customer_id ? Number(p.customer_id) : undefined,
      service_types: String(p.service_types || '').split(',').filter(Boolean) });
    setCustOpts(p.customer_id ? [{ value: Number(p.customer_id), label: p.customer_group_name }] : []);
    setCustKw('');
  }, [open, project, form]);

  const search = async (kw: string) => {
    setCustKw(kw);
    const r = await recruitSearchClients('customer', kw);
    const list = r?.data || [];
    setCustOpts(list.map((c: any) => ({ value: Number(c.id), label: c.group_name })));
  };

  /** 搜不到的客户当场新建，建完直接选上 */
  const createClient = async () => {
    const name = custKw.trim();
    if (!name) return;
    setCreating(true);
    try {
      const r = await createRecruitClient({ name });
      if (r?.success && r.data?.id) {
        const id = Number(r.data.id);
        setCustOpts([{ value: id, label: name }]);
        form.setFieldValue('customer_id', id);
        setCustKw('');
        message.success(t('pages.recruit.msg.saved'));
      } else showErr(r, t);
    } finally { setCreating(false); }
  };

  const submit = async () => {
    const v = await form.validateFields();
    setSaving(true);
    try {
      const res = await recruitSaveProject({ ...v, id: project?.id || 0 });
      if (res?.success) { message.success(t('pages.recruit.msg.saved')); onSaved(res.data.id); } else showErr(res, t);
    } finally { setSaving(false); }
  };

  return (
    <Drawer open={open} onClose={onClose} width={DETAIL_WIDTH.info} destroyOnClose
      title={project?.id ? t('pages.recruit.proj.edit') : t('pages.recruit.proj.new')}
      extra={<Space><Button onClick={onClose}>{t('pages.recruit.cancel')}</Button><Button type="primary" loading={saving} onClick={submit}>{t('pages.recruit.save')}</Button></Space>}>
      <Form form={form} layout="vertical">
        <Form.Item name="kind" label={t('pages.recruit.proj.kind')}>
          <Segmented options={[{ value: 'client', label: t('pages.recruit.kind.client') }, { value: 'internal', label: t('pages.recruit.kind.internal') }]} />
        </Form.Item>
        <Form.Item name="name" label={t('pages.recruit.proj.name')} rules={[{ required: true, message: t('pages.recruit.err.nameRequired') }]}>
          <Input maxLength={191} placeholder={t('pages.recruit.proj.namePh')} />
        </Form.Item>
        {kind !== 'internal' && (
          <Form.Item name="customer_id" label={t('pages.recruit.proj.customer')}>
            <Select showSearch allowClear filterOption={false} options={custOpts}
              placeholder={t('pages.recruit.proj.customerPh')}
              onSearch={search} onFocus={() => !custOpts.length && search('')}
              notFoundContent={custKw.trim()
                ? <Button type="link" size="small" icon={<PlusOutlined />} loading={creating} onClick={createClient}>
                    {t('pages.recruit.proj.createClient', { name: custKw.trim() })}</Button>
                : undefined}
              dropdownRender={(menu) => (<>{menu}
                {!!custKw.trim() && custOpts.length > 0 && !custOpts.some((o) => String(o.label).toLowerCase() === custKw.trim().toLowerCase()) && (
                  <div style={{ borderTop: '1px solid #f0f0f0', padding: 4 }}>
                    <Button type="link" size="small" icon={<PlusOutlined />} loading={creating} onClick={createClient}>
                      {t('pages.recruit.proj.createClient', { name: custKw.trim() })}</Button>
                  </div>)}
              </>)} />
          </Form.Item>
        )}
        {/* 合作模式（「可能是混合的：EOR、RPO、猎头」）：可多选；每个职位走哪种、费率保证期多少，在项目页「各职位条款」里定 */}
        {kind !== 'internal' && meta?.lc_ready && (
          <Form.Item name="service_types" label={t('pages.recruit.terms.modes')} extra={t('pages.recruit.terms.modesHint')}>
            <Checkbox.Group options={(meta?.lc?.service_type || []).map((s: string) => ({ value: s, label: t(`pages.recruit.svc.${s}`) }))} />
          </Form.Item>
        )}
        <Row gutter={16}>
          <Col span={12}>
            <Form.Item name="manager_user_id" label={t('pages.recruit.proj.manager')}>
              <Select allowClear showSearch optionFilterProp="label" options={(meta?.owners || []).map((o: any) => ({ value: Number(o.id), label: o.name }))} />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="status" label={t('pages.recruit.col.status')}>
              <Select options={['open', 'paused', 'closed'].map((s) => ({ value: s, label: t(`pages.recruit.projStatus.${s}`) }))} />
            </Form.Item>
          </Col>
        </Row>
        <Form.Item name="description" label={t('pages.recruit.proj.desc')}>
          <Input.TextArea rows={4} maxLength={5000} />
        </Form.Item>
      </Form>
    </Drawer>
  );
};

// ====================================================================== 职位表单
export const JobFormDrawer: React.FC<{ open: boolean; projectId: number; job?: any; onClose: () => void; onSaved: () => void }> =
({ open, projectId, job, onClose, onSaved }) => {
  const intl = useIntl();
  const t = (id: string, v?: any) => intl.formatMessage({ id }, v);
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const [reqs, setReqs] = useState<{ text: string; level: string }[]>([]);
  /** 要求怎么来的：null 没动（保存时不传，交给后台按 JD 拆）/ ai 刚点「AI 拆分」拆出来没改过 / manual 人改过（AI 不再覆盖） */
  const [reqSrc, setReqSrc] = useState<'ai' | 'manual' | null>(null);
  const [splitting, setSplitting] = useState(false);
  /** 当场拆出来的原样（HR 之后改了，保存时一起传上去：AI 那份 vs HR 终稿 = 提示词迭代的回归用例） */
  const [aiSplit, setAiSplit] = useState<{ text: string; level: string }[] | null>(null);

  useEffect(() => {
    if (!open) return;
    form.resetFields();
    const j = job || {};
    form.setFieldsValue({ title: j.title, location: j.location, employment_type: j.employment_type || '', headcount: j.headcount || 1,
      salary_text: j.salary_text, status: j.status || 'open', jd_text: j.jd_text });
    setReqs((j.requirements || []).map((r: any) => ({ text: r.text, level: r.level })));
    setReqSrc(null);
    setAiSplit(null);
  }, [open, job, form]);

  const editReq = (i: number, patch: any) => { setReqSrc('manual'); setReqs((p) => p.map((r, k) => (k === i ? { ...r, ...patch } : r))); };

  // 当场拆（与后台同一提示词）：拆完填进下面，核对改好再保存
  const split = async () => {
    const v = form.getFieldsValue();
    if (!String(v.jd_text || '').trim()) { message.warning(t('pages.recruit.job.jdFirst')); return; }
    setSplitting(true);
    try {
      const r = await recruitSplitJd({ id: job?.id || 0, title: v.title, jd_text: v.jd_text });
      if (r?.success) {
        const list = r.data.requirements.map((x: any) => ({ text: x.text, level: x.level }));
        setReqs(list); setAiSplit(list); setReqSrc('ai');
      }
      else showErr(r, t);
    } finally { setSplitting(false); }
  };

  const submit = async () => {
    const v = await form.validateFields();
    setSaving(true);
    try {
      // 只有拆过 / 改过要求才提交 requirements；没动就让 AI 在后台按 JD 拆
      const res = await recruitSaveJob({ ...v, id: job?.id || 0, project_id: projectId,
        ...(reqSrc ? { requirements: reqs, req_source: reqSrc } : {}),
        ...(reqSrc === 'manual' && aiSplit ? { ai_requirements: aiSplit } : {}) });
      if (res?.success) { message.success(t('pages.recruit.msg.saved')); onSaved(); } else showErr(res, t);
    } finally { setSaving(false); }
  };

  return (
    <Drawer open={open} onClose={onClose} width={DETAIL_WIDTH.info} destroyOnClose
      title={job?.id ? t('pages.recruit.job.edit') : t('pages.recruit.job.new')}
      extra={<Space><Button onClick={onClose}>{t('pages.recruit.cancel')}</Button><Button type="primary" loading={saving} onClick={submit}>{t('pages.recruit.save')}</Button></Space>}>
      <Form form={form} layout="vertical">
        <Form.Item name="title" label={t('pages.recruit.job.title')} rules={[{ required: true, message: t('pages.recruit.err.titleRequired') }]}>
          <Input maxLength={191} />
        </Form.Item>
        <Row gutter={16}>
          <Col span={12}><Form.Item name="location" label={t('pages.recruit.job.location')}><Input maxLength={191} /></Form.Item></Col>
          <Col span={12}>
            <Form.Item name="employment_type" label={t('pages.recruit.p.type')}>
              <Select options={['', 'full_time', 'contract', 'internship'].map((e) => ({ value: e, label: e ? t(`pages.recruit.emp.${e}`) : '-' }))} />
            </Form.Item>
          </Col>
          <Col span={12}><Form.Item name="headcount" label={t('pages.recruit.job.headcount')}><InputNumber min={1} style={{ width: '100%' }} /></Form.Item></Col>
          <Col span={12}><Form.Item name="salary_text" label={t('pages.recruit.job.salary')}><Input maxLength={191} /></Form.Item></Col>
          <Col span={12}>
            <Form.Item name="status" label={t('pages.recruit.col.status')}>
              <Select options={['open', 'paused', 'filled', 'closed'].map((s) => ({ value: s, label: t(`pages.recruit.jobStatus.${s}`) }))} />
            </Form.Item>
          </Col>
        </Row>
        <Form.Item name="jd_text" label={t('pages.recruit.job.jd')} extra={t('pages.recruit.job.jdHint')}>
          <Input.TextArea rows={8} maxLength={20000} />
        </Form.Item>
        <Form.Item
          label={<Space>{t('pages.recruit.job.reqs')}
            <Button size="small" type="primary" ghost icon={<ThunderboltOutlined />} loading={splitting} onClick={split}>
              {reqs.length ? t('pages.recruit.job.resplit') : t('pages.recruit.job.split')}
            </Button></Space>}
          extra={t(reqSrc === 'manual' ? 'pages.recruit.job.reqsManual' : reqSrc === 'ai' ? 'pages.recruit.job.reqsSplit' : 'pages.recruit.job.reqsHint')}>
          {reqs.map((r, i) => (
            <Space.Compact key={i} style={{ width: '100%', marginBottom: 6 }}>
              <Select style={{ width: 110 }} value={r.level} onChange={(v) => editReq(i, { level: v })}
                options={[{ value: 'core', label: t('pages.recruit.req.core') }, { value: 'nice', label: t('pages.recruit.req.nice') }]} />
              <Input value={r.text} maxLength={120} onChange={(e) => editReq(i, { text: e.target.value })} />
              <Button icon={<DeleteOutlined />} onClick={() => { setReqSrc('manual'); setReqs((p) => p.filter((_, k) => k !== i)); }} />
            </Space.Compact>
          ))}
          <Button size="small" icon={<PlusOutlined />} disabled={reqs.length >= 10}
            onClick={() => { setReqSrc('manual'); setReqs((p) => [...p, { text: '', level: 'nice' }]); }}>
            {t('pages.recruit.job.addReq')}
          </Button>
        </Form.Item>
      </Form>
    </Drawer>
  );
};

// ====================================================================== 职位区（项目总览页「职位」页签）
/**
 * 投递地址 + 职位行卡片（招聘文案 / 上传简历 / 编辑）。原「项目抽屉」退役（「项目总览做成独立页面」），
 * 整块搬到 /recruit/projects/:id 的「职位」页签；p = recruitGetProject 的返回（带 jobs）。
 */
export const JobsSection: React.FC<{ p: any; meta: any; reload: () => void; onChanged?: () => void; onOpenMailbox?: () => void }> =
({ p, meta, reload, onChanged, onOpenMailbox }) => {
  const intl = useIntl();
  const t = useCallback((id: string, v?: any) => intl.formatMessage({ id }, v), [intl]);
  const [jobs, setJobs] = useState<any[]>(p?.jobs || []);
  useEffect(() => { setJobs(p?.jobs || []); }, [p]);
  const [jobEdit, setJobEdit] = useState<{ open: boolean; job?: any }>({ open: false });
  const [uploadJob, setUploadJob] = useState<number | null>(null);
  // 展开了招聘文案的职位（「放在职位下面，点开就能直接复制」）
  const [postingOpen, setPostingOpen] = useState<Record<number, boolean>>({});
  const { initialState } = useModel('@@initialState');
  const me = Number((initialState as any)?.currentUser?.id || 0);
  const myCode = (meta?.sources || []).find((s: any) => Number(s.user_id) === me)?.code;
  const addresses = (meta?.addresses || []).filter((a: any) => a.enabled);
  const copy = (s: string) => navigator.clipboard.writeText(s).then(() => message.success(t('pages.recruit.copied')));
  const reqTip = (j: any) => (j.requirements || []).length
    ? (j.requirements || []).map((r: any) => `${r.level === 'core' ? '★ ' : '· '}${r.text}`).join('\n')
    : t(`pages.recruit.reqState.${j.req_state}Tip`);
  const patchJob = (id: number, patch: any) => setJobs((x) => x.map((y) => (Number(y.id) === id ? { ...y, ...patch } : y)));

  return (
    <>
      <style>{`.rj-row{border:1px solid #f0f0f0;border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;transition:all .15s}
        .rj-row:hover{border-color:#c9d2ff;background:#fafbff;box-shadow:0 2px 8px rgba(26,42,214,.06)}
        .rj-chip{display:inline-flex;flex-direction:column;align-items:center;min-width:64px}
        .rj-chip b{font-size:18px;line-height:1.2}.rj-chip span{font-size:12px;color:#8c8c8c}`}</style>
      {/* 投递地址：一行一个芯片，点复制 */}
      <div style={{ margin: '0 0 4px' }}>
        <Text strong>{t('pages.recruit.proj.addresses')}</Text>
        <Text type="secondary" style={{ fontSize: 12, marginLeft: 8 }}>{t('pages.recruit.proj.addrHint')}</Text>
      </div>
      {addresses.length === 0 ? (
        <Space size={8} wrap>
          <Text type="secondary">{t('pages.recruit.proj.noMailbox')}</Text>
          {onOpenMailbox && <Button size="small" icon={<MailOutlined />} onClick={onOpenMailbox}>{t('pages.recruit.cfg.menu')}</Button>}
        </Space>
      ) : (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
          {addresses.map((a: any) => (
            <div key={a.address} onClick={() => copy(a.address)} title={t('pages.recruit.copy')}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '6px 10px', border: '1px solid #e8ecff', background: '#f5f7ff',
                borderRadius: 8, cursor: 'pointer' }}>
              <Tag color="blue" style={{ margin: 0 }}>+{a.code}</Tag>
              <Text style={{ fontFamily: 'monospace', fontSize: 12 }}>{a.address}</Text>
              <Text type="secondary" style={{ fontSize: 12 }}>{a.user_name}</Text>
              <CopyOutlined style={{ color: '#1a2ad6' }} />
            </div>))}
        </div>
      )}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', margin: '20px 0 10px' }}>
        <Text strong style={{ fontSize: 15 }}>{t('pages.recruit.jobs')} <Text type="secondary">({jobs.length})</Text></Text>
        <Space>
          <PipelineButton onDone={reload} />
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setJobEdit({ open: true })}>{t('pages.recruit.job.new')}</Button>
        </Space>
      </div>
      {jobs.length === 0 ? <Empty description={t('pages.recruit.job.none')} /> : (
        <Space direction="vertical" size={10} style={{ width: '100%' }}>
          {jobs.map((j: any) => (
            <div key={j.id} className="rj-row">
              <div style={{ flex: '1 1 280px', minWidth: 0 }}>
                <Space size={8} wrap>
                  <Text strong style={{ fontSize: 15 }}>{j.title}</Text>
                  <Tag color={j.status === 'open' ? 'green' : 'default'} style={{ margin: 0 }}>{t(`pages.recruit.jobStatus.${j.status}`)}</Tag>
                  {p.kind !== 'internal' && !!j.eff_service_type && (
                    <Tooltip title={t(j.own_service_type ? 'pages.recruit.terms.own' : 'pages.recruit.terms.inherit')}>
                      <Tag color={SERVICE_COLOR[j.eff_service_type]} style={{ margin: 0, borderStyle: j.own_service_type ? 'solid' : 'dashed' }}>{t(`pages.recruit.svc.${j.eff_service_type}`)}</Tag>
                    </Tooltip>)}
                </Space>
                <div style={{ fontSize: 12, color: '#8c8c8c', margin: '2px 0 6px' }}>
                  {[j.location, j.employment_type ? t(`pages.recruit.emp.${j.employment_type}`) : '', t('pages.recruit.job.hc', { n: j.headcount })].filter(Boolean).join(' · ')}
                </div>
                <Tooltip title={<div style={{ whiteSpace: 'pre-wrap' }}>{reqTip(j)}</div>}>
                  <Tag color={{ ai: 'green', manual: 'blue', pending: 'default', failed: 'red' }[j.req_state as string]} style={{ cursor: 'help' }}>
                    {t(`pages.recruit.reqState.${j.req_state}`)}{j.requirements?.length ? ` · ${j.requirements.length}` : ''}
                  </Tag>
                </Tooltip>
                {Object.keys(j.posted || {}).length > 0 && (
                  <Tag color="blue" style={{ marginInlineStart: 4 }}>
                    {t('pages.recruit.posting.postedTo')} {POST_PLATFORMS.filter((pf) => j.posted[pf]).map((pf) => t(`pages.recruit.platform.${pf}`)).join(' · ')}
                  </Tag>
                )}
              </div>
              <Space size={4}>
                <div className="rj-chip"><b style={{ color: Number(j.strong_count) > 0 ? '#d48806' : undefined }}>{j.strong_count}</b><span>{t('pages.recruit.job.strong')}</span></div>
                <div className="rj-chip"><b style={{ color: Number(j.active_count) > 0 ? '#722ed1' : undefined }}>{j.active_count}</b><span>{t('pages.recruit.job.active')}</span></div>
                <div className="rj-chip"><b style={{ color: Number(j.hired_count) > 0 ? '#389e0d' : undefined }}>{j.hired_count}/{j.headcount}</b><span>{t('pages.recruit.stage.hired')}</span></div>
              </Space>
              <Space size={4} style={{ marginLeft: 'auto' }}>
                <Button icon={<TeamOutlined />} onClick={() => history.push(`/recruit/talent?project_id=${p.id}&job_id=${j.id}`)}>{t('pages.recruit.job.candidates')}</Button>
                <Button icon={<FileTextOutlined />} type={postingOpen[j.id] ? 'primary' : 'default'} ghost={!!postingOpen[j.id]}
                  onClick={() => setPostingOpen((o) => ({ ...o, [j.id]: !o[j.id] }))}>
                  {t('pages.recruit.posting.title')}{Object.keys(j.posting || {}).length ? ` · ${Object.keys(j.posting).length}` : ''}
                </Button>
                <JobApplyLink t={t} job={j} />
                <Tooltip title={t('pages.recruit.upload.toJob')}><Button icon={<CloudUploadOutlined />} onClick={() => setUploadJob(Number(j.id))} /></Tooltip>
                <Tooltip title={t('pages.recruit.edit')}><Button icon={<EditOutlined />} onClick={() => setJobEdit({ open: true, job: j })} /></Tooltip>
              </Space>
              {!!postingOpen[j.id] && (
                <div style={{ flexBasis: '100%', borderTop: '1px dashed #e8ecff', paddingTop: 12 }}>
                  <JobPostingBox t={t} job={j} addresses={addresses}
                    defaultApply={(addresses.find((a: any) => a.code === myCode) || addresses[0])?.address}
                    onSaved={(posting) => patchJob(Number(j.id), { posting })}
                    onPosted={(posted) => patchJob(Number(j.id), { posted })} />
                </div>
              )}
            </div>
          ))}
        </Space>
      )}
      <UploadDrawer open={uploadJob !== null} meta={meta} defaultJobId={uploadJob || undefined} onClose={() => setUploadJob(null)} onUploaded={reload} />
      <JobFormDrawer open={jobEdit.open} projectId={Number(p.id)} job={jobEdit.job} onClose={() => setJobEdit({ open: false })}
        onSaved={() => { setJobEdit({ open: false }); reload(); onChanged?.(); }} />
    </>
  );
};
