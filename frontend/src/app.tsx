import { history, getLocale, setLocale, getIntl } from '@umijs/max';
import type { RequestConfig, RunTimeLayoutConfig } from '@umijs/max';
import { message, Dropdown, Avatar } from 'antd';
import { GlobalOutlined, LogoutOutlined, UserOutlined } from '@ant-design/icons';
import React from 'react';
import { API_BASE_URL } from '@/services/config';

/** Icons for the recruiting sub-menu (public/recruit/art/nav-*.svg) */
const RecruitNavIcon: React.FC<{ name: string }> = ({ name }) => (
  <img src={`/recruit/art/nav-${name}.svg`} alt="" className="anticon"
    style={{ width: 16, height: 16, objectFit: 'contain', mixBlendMode: 'multiply', verticalAlign: '-3px' }} />
);

/* ProLayout does not render icons for second-level menu items; they are added in menuItemRender.
   Second-level items ONLY use this table — `icon:` in .umirc.ts has no effect on them. */
const SUBMENU_ICON: Record<string, React.ReactNode> = {
  '/recruit/workbench': <RecruitNavIcon name="workbench" />,
  '/recruit/talent': <RecruitNavIcon name="talent" />,
  '/recruit/search': <RecruitNavIcon name="search" />,
  '/recruit/resumes': <RecruitNavIcon name="resumes" />,
  '/recruit/projects': <RecruitNavIcon name="projects" />,
  '/recruit/companies': <RecruitNavIcon name="companies" />,
  '/recruit/network': <RecruitNavIcon name="network" />,
  '/recruit/stats': <RecruitNavIcon name="stats" />,
};

async function fetchUserInfo(): Promise<API.CurrentUser | undefined> {
  try {
    const token = localStorage.getItem('token');
    if (!token) return undefined;
    const res = await fetch(`${API_BASE_URL}?action=currentUser`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const data = await res.json();
    if (data.success) return data.data;
    return undefined;
  } catch {
    return undefined;
  }
}

/**
 * Pages that work without login (the token in the URL is the credential).
 * getInitialState and onPageChange both use this one list.
 */
const PUBLIC_PATHS = ['/apply/'];
const isPublicPath = (pathname: string) =>
  pathname === '/user/login' || PUBLIC_PATHS.some((p) => pathname.startsWith(p));

const loginUrl = () => {
  const { location } = history;
  const target = location.pathname + (location.search || '');
  return target && target !== '/' ? `/user/login?redirect=${encodeURIComponent(target)}` : '/user/login';
};

export async function getInitialState(): Promise<{ currentUser?: API.CurrentUser }> {
  if (!isPublicPath(history.location.pathname)) {
    const currentUser = await fetchUserInfo();
    if (!currentUser) {
      history.push(loginUrl());
      return {};
    }
    return { currentUser };
  }
  return {};
}

const tr = (id: string) => {
  try { return getIntl().formatMessage({ id }); } catch { return id; }
};

const SelectLang: React.FC = () => {
  const currentLocale = getLocale();
  const localeMap: Record<string, { label: string; icon: string }> = {
    'zh-CN': { label: '简体中文', icon: '🇨🇳' },
    'en-US': { label: 'English', icon: '🇺🇸' },
    'id-ID': { label: 'Bahasa Indonesia', icon: '🇮🇩' },
  };
  const items = Object.keys(localeMap).map((key) => ({
    key,
    label: <span>{localeMap[key].icon} {localeMap[key].label}</span>,
  }));
  return (
    <Dropdown menu={{ items, selectedKeys: [currentLocale], onClick: ({ key }) => setLocale(key, false) }} placement="bottomRight">
      <span style={{ cursor: 'pointer', padding: '0 12px', fontSize: 16 }}><GlobalOutlined /></span>
    </Dropdown>
  );
};

const UserDropdown: React.FC<{ currentUser?: API.CurrentUser; onLogout: () => void }> = ({ currentUser, onLogout }) => (
  <Dropdown
    trigger={['click']}
    placement="bottomRight"
    menu={{ items: [{ key: 'logout', icon: <LogoutOutlined />, label: tr('menu.account.logout'), danger: true, onClick: onLogout }] }}
  >
    <span style={{ cursor: 'pointer', padding: '0 12px', display: 'flex', alignItems: 'center', gap: 8 }}>
      <Avatar size="small" style={{ backgroundColor: '#1a2ad6' }} icon={<UserOutlined />} />
      <span style={{ fontSize: 14 }}>{currentUser?.name || currentUser?.username || 'User'}</span>
    </span>
  </Dropdown>
);

export const layout: RunTimeLayoutConfig = ({ initialState, setInitialState }) => ({
  title: 'OpenHunter',
  logo: '/recruit/art/mark-64.svg',
  layout: 'mix',
  fixedHeader: true,
  splitMenus: false,
  menu: { locale: true },
  menuItemRender: (item: any, defaultDom: React.ReactNode) => {
    const subIcon = item.path ? SUBMENU_ICON[item.path] : undefined;
    if (subIcon) {
      return <a onClick={() => history.push(item.path || '/')} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>{subIcon}{defaultDom}</a>;
    }
    return <a onClick={() => history.push(item.path || '/')}>{defaultDom}</a>;
  },
  /* Top-level recruiting menu uses the hand-drawn badge; SolutionOutlined in .umirc.ts stays as fallback */
  menuDataRender: (data: any[]) => data.map((it: any) => (it?.path === '/recruit'
    ? { ...it, icon: <img src="/recruit/art/mark-64.svg" alt="" style={{ width: 18, height: 18, borderRadius: '50%', verticalAlign: '-4px' }} /> }
    : it)),
  token: {
    header: { colorBgHeader: '#fff', heightLayoutHeader: 48 },
    sider: { colorMenuBackground: '#fff' },
  },
  actionsRender: () => [
    <SelectLang key="lang" />,
    <UserDropdown
      key="user"
      currentUser={initialState?.currentUser}
      onLogout={() => {
        localStorage.removeItem('token');
        setInitialState({ currentUser: undefined });
        history.push('/user/login');
      }}
    />,
  ],
  avatarProps: false as any,
  onPageChange: () => {
    if (!initialState?.currentUser && !isPublicPath(history.location.pathname)) {
      history.push(loginUrl());
    }
  },
});

// Avoid duplicate messages / redirects when several requests get 401 at once
let sessionExpiredHandled = false;
function handleSessionExpired() {
  if (sessionExpiredHandled) return;
  sessionExpiredHandled = true;
  localStorage.removeItem('token');
  message.error(tr('pages.login.expired'));
  if (!window.location.pathname.startsWith('/user/login')) history.push(loginUrl());
  setTimeout(() => { sessionExpiredHandled = false; }, 3000);
}

/**
 * Surface every API failure to the user (no silent failures). Business errors come back as
 * HTTP 200 + { success:false, message_key | errorMessage }. Same message shown at most once per 2s.
 * Recruit pages translate message_key themselves via showErr(); here we only show plain errorMessage/message.
 */
const recentApiErrors = new Map<string, number>();
function notifyApiFailure(data: any, url?: string, httpStatus?: number, rawError?: any) {
  if (rawError?.code === 'ERR_CANCELED' || rawError?.name === 'CanceledError') return;
  if (typeof url === 'string' && url.includes('action=login')) return;
  if (data?.message_key && !data?.errorMessage) return;
  let msg = data?.errorMessage || data?.message || (httpStatus ? `HTTP ${httpStatus}` : '') || rawError?.message || tr('pages.common.requestFailed');
  if (rawError?.code === 'ECONNABORTED' || /timeout of \d+ms exceeded/i.test(String(msg))) {
    // eslint-disable-next-line no-console
    console.error('[API timeout]', url, msg);
    msg = tr('pages.common.timeout');
  }
  const key = String(msg);
  const now = Date.now();
  if (now - (recentApiErrors.get(key) || 0) < 2000) return;
  recentApiErrors.set(key, now);
  if (recentApiErrors.size > 50) recentApiErrors.clear();
  message.error({ content: key, key, duration: 4 });
}

export const request: RequestConfig = {
  baseURL: API_BASE_URL,
  // Must stay well below the PHP-FPM request_terminate_timeout; long jobs belong in async workers.
  timeout: 60000,
  requestInterceptors: [
    (config: any) => {
      const token = localStorage.getItem('token');
      if (token) config.headers = { ...config.headers, Authorization: `Bearer ${token}` };
      return config;
    },
  ],
  responseInterceptors: [
    (response: any) => {
      const status = response?.status;
      const data = response?.data;
      if (status === 401 || data?.code === 401 || data?.errorCode === 401) {
        handleSessionExpired();
        return response;
      }
      if (data && typeof data === 'object' && data.success === false) notifyApiFailure(data, response?.config?.url);
      return response;
    },
  ],
  errorConfig: {
    errorHandler(error: any) {
      const status = error?.response?.status;
      const data = error?.response?.data;
      if (status === 401 || data?.code === 401 || data?.errorCode === 401) {
        handleSessionExpired();
        throw error;
      }
      notifyApiFailure(data, error?.config?.url, status, error);
      throw error;
    },
  },
};
