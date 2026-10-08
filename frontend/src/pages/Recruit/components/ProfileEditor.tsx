/**
 * 候选人档案整段修正（「简历要可以编辑」——两种都要：这里是改事实，推荐材料里另有措辞编辑）。
 * 只提交改过的段 → 后端存 locked_fields.sections，覆盖 AI 解析；年限/最高学历/最近职位随之重算，并触发重新向量化与匹配。
 * 内联表单（§6.3 抽屉里不嵌 Modal）；长表单两列（§6.5）。
 */
import React, { useMemo, useState } from 'react';
import { Form, Input, Select, Button, Space, Row, Col, Divider, Checkbox, message } from 'antd';
import { PlusOutlined, MinusCircleOutlined } from '@ant-design/icons';
import { recruitUpdateCandidate } from '@/services/api';
import { showErr, type T } from '../common';

const EDU = ['sd', 'smp', 'sma', 'd1', 'd2', 'd3', 'd4', 's1', 's2', 's3', 'other'];
const EMP = ['full_time', 'contract', 'internship', 'freelance'];
const SECTIONS = ['headline', 'experience', 'education', 'skills', 'skills_en', 'certificates', 'languages', 'industries',
  'projects', 'awards', 'organizations', 'expected_salary', 'notice_period_text', 'extra'] as const;

interface Props { candidateId: number; profile: any; industries: string[]; t: T; onSaved: () => void; onCancel: () => void }

const ProfileEditor: React.FC<Props> = ({ candidateId, profile, industries, t, onSaved, onCancel }) => {
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const initial = useMemo(() => {
    const p = profile || {};
    const o: Record<string, any> = {};
    SECTIONS.forEach((k) => { o[k] = p[k] ?? (['headline', 'notice_period_text', 'extra'].includes(k) ? '' : k === 'expected_salary' ? { text: '', currency: '' } : []); });
    return o;
  }, [profile]);

  const save = async () => {
    const v = form.getFieldsValue(true);
    const changed: Record<string, any> = {};
    SECTIONS.forEach((k) => { if (JSON.stringify(v[k] ?? null) !== JSON.stringify(initial[k] ?? null)) changed[k] = v[k]; });
    if (!Object.keys(changed).length) { onCancel(); return; }
    setSaving(true);
    try {
      const res = await recruitUpdateCandidate({ id: candidateId, sections: changed });
      if (res?.success) { message.success(t('pages.recruit.msg.saved')); onSaved(); } else showErr(res, t);
    } finally { setSaving(false); }
  };

  const del = (remove: (i: number) => void, i: number) => <MinusCircleOutlined style={{ color: '#cf1322', marginTop: 8 }} onClick={() => remove(i)} />;
  const period = (name: number) => (
    <Space.Compact style={{ width: '100%' }}>
      <Form.Item name={[name, 'start']} noStyle><Input placeholder={t('pages.recruit.pe.start')} /></Form.Item>
      <Form.Item name={[name, 'end']} noStyle><Input placeholder={t('pages.recruit.pe.end')} /></Form.Item>
    </Space.Compact>
  );

  return (
    <Form form={form} layout="vertical" initialValues={initial} size="small" style={{ background: '#fafafa', padding: 12, borderRadius: 6 }}>
      <Form.Item name="headline" label={t('pages.recruit.pe.headline')}><Input maxLength={191} /></Form.Item>

      <Divider orientation="left" plain>{t('pages.recruit.p.experience')}</Divider>
      <Form.List name="experience">{(fields, { add, remove }) => (
        <>
          {fields.map(({ key, name }) => (
            <div key={key} style={{ borderBottom: '1px dashed #e8e8e8', marginBottom: 8 }}>
              <Row gutter={8}>
                <Col span={7}><Form.Item name={[name, 'title']} label={t('pages.recruit.pe.title')}><Input /></Form.Item></Col>
                <Col span={7}><Form.Item name={[name, 'company']} label={t('pages.recruit.pe.company')}><Input /></Form.Item></Col>
                <Col span={6}><Form.Item label={t('pages.recruit.p.period')}>{period(name)}</Form.Item></Col>
                <Col span={3}><Form.Item name={[name, 'employment_type']} label={t('pages.recruit.p.type')}>
                  <Select allowClear options={EMP.map((e) => ({ value: e, label: t(`pages.recruit.emp.${e}`) }))} /></Form.Item></Col>
                <Col span={1}>{del(remove, name)}</Col>
              </Row>
              <Row gutter={8}>
                <Col span={18}><Form.Item name={[name, 'description']} label={t('pages.recruit.p.desc')}><Input.TextArea rows={2} maxLength={300} /></Form.Item></Col>
                <Col span={6}><Form.Item name={[name, 'is_current']} valuePropName="checked" label=" "><Checkbox>{t('pages.recruit.p.present')}</Checkbox></Form.Item></Col>
              </Row>
            </div>
          ))}
          <Button type="dashed" block icon={<PlusOutlined />} onClick={() => add({})}>{t('pages.recruit.pe.addExp')}</Button>
        </>
      )}</Form.List>

      <Divider orientation="left" plain>{t('pages.recruit.p.education')}</Divider>
      <Form.List name="education">{(fields, { add, remove }) => (
        <>
          {fields.map(({ key, name }) => (
            <Row key={key} gutter={8}>
              <Col span={4}><Form.Item name={[name, 'level']} label={t('pages.recruit.p.level')}>
                <Select allowClear options={EDU.map((e) => ({ value: e, label: t(`pages.recruit.edu.${e}`) }))} /></Form.Item></Col>
              <Col span={7}><Form.Item name={[name, 'school']} label={t('pages.recruit.p.school')}><Input /></Form.Item></Col>
              <Col span={6}><Form.Item name={[name, 'major']} label={t('pages.recruit.pe.major')}><Input /></Form.Item></Col>
              <Col span={6}><Form.Item label={t('pages.recruit.p.period')}>{period(name)}</Form.Item></Col>
              <Col span={1}>{del(remove, name)}</Col>
            </Row>
          ))}
          <Button type="dashed" block icon={<PlusOutlined />} onClick={() => add({})}>{t('pages.recruit.pe.addEdu')}</Button>
        </>
      )}</Form.List>

      <Divider orientation="left" plain>{t('pages.recruit.pe.skillsEtc')}</Divider>
      <Row gutter={16}>
        <Col span={12}><Form.Item name="skills" label={t('pages.recruit.p.skills')}><Select mode="tags" tokenSeparators={[',', '，']} /></Form.Item></Col>
        <Col span={12}><Form.Item name="skills_en" label={t('pages.recruit.p.skillsEn')}><Select mode="tags" tokenSeparators={[',', '，']} /></Form.Item></Col>
        <Col span={12}><Form.Item name="industries" label={t('pages.recruit.p.industries')}>
          <Select mode="multiple" options={industries.map((x) => ({ value: x, label: t(`pages.recruit.industry.${x}`) }))} /></Form.Item></Col>
        <Col span={6}><Form.Item name={['expected_salary', 'text']} label={t('pages.recruit.p.salary')}><Input /></Form.Item></Col>
        <Col span={6}><Form.Item name="notice_period_text" label={t('pages.recruit.p.notice')}><Input /></Form.Item></Col>
      </Row>
      <Row gutter={16}>
        <Col span={12}>
          <Form.List name="certificates">{(fields, { add, remove }) => (
            <Form.Item label={t('pages.recruit.p.certs')}>
              {fields.map(({ key, name }) => (
                <Space.Compact key={key} style={{ width: '100%', marginBottom: 4 }}>
                  <Form.Item name={[name, 'name']} noStyle><Input placeholder={t('pages.recruit.pe.name')} /></Form.Item>
                  <Form.Item name={[name, 'year']} noStyle><Input style={{ width: 80 }} placeholder={t('pages.recruit.pe.year')} /></Form.Item>
                  <Button icon={<MinusCircleOutlined />} onClick={() => remove(name)} />
                </Space.Compact>
              ))}
              <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add({})}>{t('pages.recruit.pe.add')}</Button>
            </Form.Item>
          )}</Form.List>
        </Col>
        <Col span={12}>
          <Form.List name="languages">{(fields, { add, remove }) => (
            <Form.Item label={t('pages.recruit.p.languages')}>
              {fields.map(({ key, name }) => (
                <Space.Compact key={key} style={{ width: '100%', marginBottom: 4 }}>
                  <Form.Item name={[name, 'language']} noStyle><Input placeholder={t('pages.recruit.pe.language')} /></Form.Item>
                  <Form.Item name={[name, 'level_raw']} noStyle><Input style={{ width: 120 }} placeholder={t('pages.recruit.pe.level')} /></Form.Item>
                  <Button icon={<MinusCircleOutlined />} onClick={() => remove(name)} />
                </Space.Compact>
              ))}
              <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add({})}>{t('pages.recruit.pe.add')}</Button>
            </Form.Item>
          )}</Form.List>
        </Col>
      </Row>

      <Form.List name="projects">{(fields, { add, remove }) => (
        <Form.Item label={t('pages.recruit.p.projects')}>
          {fields.map(({ key, name }) => (
            <Row key={key} gutter={8}>
              <Col span={6}><Form.Item name={[name, 'name']}><Input placeholder={t('pages.recruit.pe.name')} /></Form.Item></Col>
              <Col span={4}><Form.Item name={[name, 'role']}><Input placeholder={t('pages.recruit.pe.role')} /></Form.Item></Col>
              <Col span={13}><Form.Item name={[name, 'description']}><Input placeholder={t('pages.recruit.p.desc')} maxLength={300} /></Form.Item></Col>
              <Col span={1}>{del(remove, name)}</Col>
            </Row>
          ))}
          <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add({})}>{t('pages.recruit.pe.add')}</Button>
        </Form.Item>
      )}</Form.List>
      <Form.Item name="extra" label={t('pages.recruit.p.extra')}><Input.TextArea rows={2} maxLength={1500} /></Form.Item>

      <Space>
        <Button type="primary" loading={saving} onClick={save}>{t('pages.recruit.save')}</Button>
        <Button onClick={onCancel}>{t('pages.recruit.cancel')}</Button>
        <span style={{ fontSize: 12, color: '#888' }}>{t('pages.recruit.pe.hint')}</span>
      </Space>
    </Form>
  );
};

export default ProfileEditor;
