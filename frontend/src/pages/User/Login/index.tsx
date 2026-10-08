import { login } from '@/services/api';
import { LockOutlined, UserOutlined, GlobalOutlined } from '@ant-design/icons';
import { LoginForm, ProFormText } from '@ant-design/pro-components';
import { useIntl, getLocale, setLocale } from '@umijs/max';
import { Dropdown, message } from 'antd';
import React from 'react';

const LANGS: Record<string, string> = { 'zh-CN': '简体中文', 'en-US': 'English', 'id-ID': 'Bahasa Indonesia' };

const Login: React.FC = () => {
  const intl = useIntl();
  const t = (id: string) => intl.formatMessage({ id });

  const handleSubmit = async (values: API.LoginParams) => {
    try {
      const res = await login(values);
      if (res.success && res.token) {
        localStorage.setItem('token', res.token);
        message.success(t('pages.login.success'));
        const redirect = new URL(window.location.href).searchParams.get('redirect');
        // Only same-origin relative paths, never an absolute URL from the query string
        const target = redirect && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/recruit/workbench';
        // Full reload so getInitialState picks up the new token
        window.location.href = target;
        return;
      }
      message.error(res.message || t('pages.login.failure'));
    } catch {
      message.error(t('pages.login.failure'));
    }
  };

  return (
    <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column', background: 'linear-gradient(135deg, #f0f3ff 0%, #ffffff 60%)' }}>
      <div style={{ display: 'flex', justifyContent: 'flex-end', padding: 16 }}>
        <Dropdown menu={{ items: Object.entries(LANGS).map(([key, label]) => ({ key, label })), selectedKeys: [getLocale()],
          onClick: ({ key }) => setLocale(key, false) }}>
          <span style={{ cursor: 'pointer', fontSize: 16 }}><GlobalOutlined /> {LANGS[getLocale()] || ''}</span>
        </Dropdown>
      </div>
      <div style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', paddingBottom: 80 }}>
        <LoginForm
          logo={<img alt="" src="/recruit/art/mark-64.jpg" style={{ borderRadius: '50%' }} />}
          title="OpenHunter"
          subTitle={t('pages.login.subtitle')}
          submitter={{ searchConfig: { submitText: t('pages.login.submit') } }}
          onFinish={async (values) => { await handleSubmit(values as API.LoginParams); }}
        >
          <ProFormText
            name="username"
            fieldProps={{ size: 'large', prefix: <UserOutlined />, autoComplete: 'username' }}
            placeholder={t('pages.login.username.placeholder')}
            rules={[{ required: true, message: t('pages.login.username.required') }]}
          />
          <ProFormText.Password
            name="password"
            fieldProps={{ size: 'large', prefix: <LockOutlined />, autoComplete: 'current-password' }}
            placeholder={t('pages.login.password.placeholder')}
            rules={[{ required: true, message: t('pages.login.password.required') }]}
          />
        </LoginForm>
      </div>
    </div>
  );
};

export default Login;
