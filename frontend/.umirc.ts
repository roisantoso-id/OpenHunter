import { defineConfig } from '@umijs/max';

export default defineConfig({
  hash: true,
  favicons: ['/recruit/art/mark-64.svg'],
  esbuildMinifyIIFE: true,
  antd: {
    configProvider: { theme: { components: { Cascader: { dropdownHeight: 360 } } } },
  },
  access: {},
  model: {},
  initialState: {},
  request: {},
  layout: {
    title: 'OpenHunter',
    locale: true,
  },
  locale: {
    default: 'zh-CN',
    antd: true,
    title: true,
    baseNavigator: true,
    baseSeparator: '-',
  },
  proxy: {
    '/api': {
      // Keep `localhost` (not 127.0.0.1): `php -S localhost:8001` may bind only to [::1].
      // `php -S` is single-threaded by default; start the backend with
      // `PHP_CLI_SERVER_WORKERS=4 php -S localhost:8001 -t .` so slow requests don't block others.
      target: 'http://localhost:8001',
      changeOrigin: true,
      secure: false,
      // Disable keep-alive pooling: `php -S` closes each connection after one response,
      // reusing a dead socket yields HPE_CLOSED_CONNECTION -> 502.
      agent: false,
      onError: (err: any, _req: any, res: any) => {
        try {
          if (res && !res.headersSent && typeof res.writeHead === 'function') {
            res.writeHead(502, { 'Content-Type': 'text/plain' });
          }
        } catch {}
        try { if (res && typeof res.end === 'function') res.end('proxy error: ' + (err?.code || err?.message || 'unknown')); } catch {}
      },
    },
  },
  routes: [
    {
      path: '/user',
      layout: false,
      routes: [
        { path: '/user/login', component: './User/Login' },
      ],
    },
    // Public job-application link: candidates apply without logging in (see PUBLIC_PATHS in src/app.tsx)
    { path: '/apply/:token', component: './RecruitApply', layout: false },
    { path: '/', redirect: '/recruit/workbench' },
    /* Sub-menu items set `locale` explicitly; their icons are added in SUBMENU_ICON in src/app.tsx. */
    {
      name: 'recruit',
      icon: 'SolutionOutlined',
      path: '/recruit',
      access: 'canAccessRecruit',
      routes: [
        { path: '/recruit', redirect: '/recruit/workbench' },
        { name: 'workbench', locale: 'menu.recruit.workbench', path: '/recruit/workbench', component: './Recruit/Workbench', access: 'canAccessRecruit' },
        { name: 'talent', locale: 'menu.recruit.talent', path: '/recruit/talent', component: './Recruit/Talent', access: 'canAccessRecruit' },
        { name: 'search', locale: 'menu.recruit.search', path: '/recruit/search', component: './Recruit/Search', access: 'canAccessRecruit' },
        { name: 'resumes', locale: 'menu.recruit.resumes', path: '/recruit/resumes', component: './Recruit/Resumes', access: 'canAccessRecruit' },
        { name: 'projects', locale: 'menu.recruit.projects', path: '/recruit/projects', component: './Recruit/Projects', access: 'canAccessRecruit' },
        // Company library (target companies to source from)
        { name: 'companies', locale: 'menu.recruit.companies', path: '/recruit/companies', component: './Recruit/Companies', access: 'canViewRecruitCompanies' },
        // Relationship network, same viewer list as the company library
        { name: 'network', locale: 'menu.recruit.network', path: '/recruit/network', component: './Recruit/Network', access: 'canViewRecruitCompanies' },
        // Project overview: standalone page, not in the menu
        { path: '/recruit/projects/:id', component: './Recruit/Projects/Overview', access: 'canAccessRecruit', hideInMenu: true },
        { name: 'stats', locale: 'menu.recruit.stats', path: '/recruit/stats', component: './Recruit/Stats', access: 'canViewAllRecruit' },
      ],
    },
    {
      name: 'settings',
      icon: 'SettingOutlined',
      path: '/settings',
      component: './Settings',
      access: 'canAccessSettings',
    },
    { path: '*', redirect: '/recruit/workbench' },
  ],
  npmClient: 'npm',
});
