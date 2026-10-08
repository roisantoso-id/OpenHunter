// 生成 public/recruit/art/*.svg（OpenHunter 自带的图标插画，原创、无版权负担）。
//   node scripts/gen-art.mjs
// 想换风格：改下面的 GLYPH（24×24 描边图标）、COLOR 或 tile()，重跑即可。页面里用的文件名不变。
import { mkdirSync, writeFileSync, readdirSync, unlinkSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const OUT = join(dirname(fileURLToPath(import.meta.url)), '../public/recruit/art');
mkdirSync(OUT, { recursive: true });
for (const f of readdirSync(OUT)) if (/\.(jpg|svg)$/.test(f)) unlinkSync(join(OUT, f));

const BRAND = '#1F6FEB';
const G = {
  briefcase: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>',
  building: '<rect x="5" y="3" width="14" height="18" rx="1"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M10 21v-4h4v4"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  users: '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2.5.6 4 2.2 4 5.2"/>',
  userplus: '<circle cx="10" cy="8" r="4"/><path d="M2 21c0-4 3.5-6 8-6s8 2 8 6M19 7v6M16 10h6"/>',
  file: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M16 16l5 5"/>',
  chart: '<path d="M4 20h16M7 20v-7M12 20V6M17 20v-10"/>',
  bell: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>',
  star: '<path d="M12 3l2.8 6 6.2.7-4.6 4.3 1.4 6.5-5.8-3.2-5.8 3.2 1.4-6.5L3 9.7 9.2 9z"/>',
  check: '<circle cx="12" cy="12" r="9"/><path d="M7.5 12.5l3 3 6-7"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  calendar: '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 10h16M8 3v4M16 3v4"/><circle cx="12" cy="15" r="1.5"/>',
  coin: '<circle cx="12" cy="12" r="9"/><path d="M12 6v12M9 9.5c0-1 1.3-1.5 3-1.5s3 .7 3 2-1.3 1.8-3 2-3 .8-3 2 1.3 2 3 2 3-.6 3-1.5"/>',
  network: '<circle cx="12" cy="5" r="2.5"/><circle cx="5" cy="18" r="2.5"/><circle cx="19" cy="18" r="2.5"/><path d="M11 7.2L6 15.8M13 7.2l5 8.6M7.5 18h9"/>',
  rocket: '<path d="M12 3c3 3 4 7 3 12H9c-1-5 0-9 3-12z"/><circle cx="12" cy="9" r="1.6"/><path d="M9 15l-3 4M15 15l3 4M10 20h4"/>',
  flame: '<path d="M12 3c1 4 5 5 5 10a5 5 0 0 1-10 0c0-2 1-3 2-4 0 2 1 2 2 2 0-3-1-5 1-8z"/>',
  ban: '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
  inbox: '<path d="M3 13h5l1 3h6l1-3h5M5 13l2-8h10l2 8M3 13v6h18v-6"/>',
  review: '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9zM9 14l2 2 4-4"/>',
  hourglass: '<path d="M7 3h10M7 21h10M7 3c0 5 5 6 5 9s-5 4-5 9M17 3c0 5-5 6-5 9s5 4 5 9"/>',
  alert: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18v.5"/>',
  chat: '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
  refresh: '<path d="M20 11a8 8 0 1 0-2 6M20 4v7h-7"/>',
  award: '<circle cx="12" cy="9" r="6"/><path d="M8.5 14L7 21l5-3 5 3-1.5-7"/>',
  send: '<path d="M21 3L3 11l7 3 3 7z"/><path d="M10 14L21 3"/>',
  home: '<path d="M3 11l9-8 9 8M5 10v11h14V10M10 21v-6h4v6"/>',
  ghost: '<circle cx="12" cy="8" r="4" stroke-dasharray="2.5 2.5"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6" stroke-dasharray="2.5 2.5"/>',
  // 行业
  code: '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M13 6l-2 12"/>',
  server: '<rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01M11 7h6M11 17h6"/>',
  wifi: '<path d="M3 9a14 14 0 0 1 18 0M6 12.5a9.5 9.5 0 0 1 12 0M9 16a5 5 0 0 1 6 0M12 19.5h.01"/>',
  factory: '<path d="M3 21V10l6 4v-4l6 4V6h6v15z"/><path d="M7 18h2M12 18h2M17 18h2"/>',
  bolt: '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
  hardhat: '<path d="M4 16a8 8 0 0 1 16 0zM2 16h20M11 8V5h2v3"/>',
  bank: '<path d="M3 10l9-6 9 6zM5 10v8M10 10v8M14 10v8M19 10v8M3 21h18"/>',
  bag: '<path d="M5 8h14l-1 13H6z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
  truck: '<rect x="2" y="7" width="12" height="9"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
  cutlery: '<path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M17 3c-2 2-2 8 0 9v9"/>',
  cross: '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M7 12h10"/>',
  cap: '<path d="M2 9l10-5 10 5-10 5zM6 11v5c3 2 9 2 12 0v-5"/>',
  flag: '<path d="M5 21V4M5 4h12l-2 4 2 4H5"/>',
  scale: '<path d="M12 4v16M6 20h12M5 8h14M5 8l-3 7a3 3 0 0 0 6 0zM19 8l-3 7a3 3 0 0 0 6 0z"/>',
  megaphone: '<path d="M3 10v4h4l8 5V5L7 10z"/><path d="M19 9a4 4 0 0 1 0 6"/>',
  sprout: '<path d="M12 21V11M12 11C12 7 9 5 5 5c0 4 3 6 7 6M12 13c0-3 2-5 6-5 0 3-2 5-6 5"/>',
  car: '<path d="M3 15v-3l2-5h14l2 5v3zM3 15v3h3v-3M18 15v3h3v-3"/><path d="M6 12h12"/>',
  dots: '<circle cx="6" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="18" cy="12" r="1.6"/>',
};

const svg = (w, h, body) => `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">${body}</svg>\n`;
const glyph = (name, color, size, x, y, sw = 1.6) =>
  `<g transform="translate(${x} ${y}) scale(${size / 24})" fill="none" stroke="${color}" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round">${G[name]}</g>`;
const tile = (name, color, n = 96) =>
  svg(n, n, `<rect x="4" y="4" width="${n - 8}" height="${n - 8}" rx="${n * 0.24}" fill="${color}" fill-opacity="0.12"/>` +
    `<rect x="4" y="4" width="${n - 8}" height="${n - 8}" rx="${n * 0.24}" fill="none" stroke="${color}" stroke-opacity="0.35" stroke-width="1.5"/>` +
    glyph(name, color, n * 0.5, n * 0.25, n * 0.25, 1.7));
const put = (name, content) => writeFileSync(join(OUT, name + '.svg'), content);

// 统计卡 / 通用
const STAT = {
  aicost: ['coin', '#D48806'], due: ['bell', '#D4380D'], failed: ['alert', '#CF1322'], files: ['file', BRAND], followup: ['refresh', '#08979C'],
  inbox: ['inbox', BRAND], interview: ['chat', '#531DAB'], month: ['calendar', '#389E0D'], newPerson: ['userplus', '#08979C'], notcv: ['ban', '#8C8C8C'],
  parsed: ['check', '#389E0D'], people: ['users', BRAND], placed: ['award', '#D48806'], queue: ['hourglass', '#8C8C8C'], recommend: ['send', '#531DAB'],
  review: ['review', '#D46B08'], rocket: ['rocket', BRAND], star: ['star', '#D48806'], unclaimed: ['ghost', '#8C8C8C'], warm: ['flame', '#D4380D'],
};
for (const [n, [g, c]] of Object.entries(STAT)) put(n, tile(g, c));

// 菜单图标
const NAV = { workbench: 'home', projects: 'briefcase', talent: 'users', resumes: 'file', search: 'search', companies: 'building', network: 'network', stats: 'chart' };
for (const [n, g] of Object.entries(NAV)) put('nav-' + n, svg(24, 24, glyph(g, 'currentColor', 24, 0, 0, 1.8).replace(/currentColor/g, BRAND)));

// 行业（颜色与 pages/Recruit/common.tsx 的 INDUSTRY_COLOR 保持一致）
const IND = {
  it_software: ['code', '#2F6BFF'], datacenter_infra: ['server', '#00A3BF'], telecom: ['wifi', '#7B61FF'], manufacturing: ['factory', '#FF7A45'],
  mining_energy: ['bolt', '#FAAD14'], construction_engineering: ['hardhat', '#F5A623'], banking_finance: ['bank', '#13A10E'], retail_fmcg: ['bag', '#EB2F96'],
  logistics: ['truck', '#1890FF'], hospitality_fnb: ['cutlery', '#FA541C'], healthcare: ['cross', '#F5222D'], education: ['cap', '#722ED1'],
  government: ['flag', '#597EF7'], legal: ['scale', '#B8860B'], hr_recruitment: ['userplus', '#1A2AD6'], media_marketing: ['megaphone', '#C41D7F'],
  agriculture_plantation: ['sprout', '#52C41A'], automotive: ['car', '#FA8C16'], real_estate: ['home', '#13C2C2'], other: ['dots', '#8C8C8C'],
};
for (const [n, [g, c]] of Object.entries(IND)) put('ind-' + n, tile(g, c));

// 空状态
const EMPTY = { search: 'search', nofit: 'ban', inbox: 'inbox', list: 'file' };
for (const [n, g] of Object.entries(EMPTY)) {
  put('empty-' + n, svg(200, 150, `<ellipse cx="100" cy="132" rx="62" ry="9" fill="#000" fill-opacity="0.05"/>` +
    `<circle cx="100" cy="68" r="52" fill="${BRAND}" fill-opacity="0.07"/><circle cx="100" cy="68" r="52" fill="none" stroke="${BRAND}" stroke-opacity="0.25" stroke-dasharray="4 5"/>` +
    glyph(g, '#8C8C8C', 56, 72, 40, 1.4)));
}

// 标志：靶心 + 对勾式准星（「猎」）
const MARK = (n) => svg(n, n, `<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3B8BFF"/><stop offset="1" stop-color="#1F4FD8"/></linearGradient></defs>` +
  `<circle cx="32" cy="32" r="30" fill="url(#g)" transform="scale(${n / 64})"/>` +
  `<g transform="scale(${n / 64})" fill="none" stroke="#fff" stroke-width="3.2" stroke-linecap="round"><circle cx="32" cy="32" r="14"/><path d="M32 8v10M32 46v10M8 32h10M46 32h10"/></g>` +
  `<circle cx="${n / 2}" cy="${n / 2}" r="${n * 0.07}" fill="#fff"/>`);
put('mark', MARK(128)); put('mark-64', MARK(64));

// 首页主图：一张招聘看板
put('hero', svg(640, 430, `<ellipse cx="330" cy="395" rx="230" ry="16" fill="#000" fill-opacity="0.05"/>` +
  `<rect x="150" y="60" width="340" height="300" rx="22" fill="${BRAND}" fill-opacity="0.07" stroke="${BRAND}" stroke-opacity="0.3" stroke-width="2"/>` +
  [0, 1, 2].flatMap((r) => [0, 1, 2].map((c) => {
    const x = 180 + c * 100, y = 90 + r * 90, hot = r === 0 && c === 1;
    return `<rect x="${x}" y="${y}" width="84" height="72" rx="10" fill="${hot ? '#FFF7E0' : '#fff'}" stroke="${hot ? '#D48806' : BRAND}" stroke-opacity="${hot ? 1 : 0.35}" stroke-width="1.6"/>` +
      `<circle cx="${x + 42}" cy="${y + 26}" r="10" fill="${hot ? '#D48806' : BRAND}" fill-opacity="0.8"/>` +
      `<path d="M${x + 14} ${y + 52}h56M${x + 24} ${y + 61}h36" stroke="${hot ? '#D48806' : BRAND}" stroke-opacity="0.4" stroke-width="3" stroke-linecap="round"/>`;
  })).join('') +
  `<circle cx="470" cy="300" r="46" fill="#fff" fill-opacity="0.9" stroke="${BRAND}" stroke-width="7"/><path d="M503 333l42 42" stroke="${BRAND}" stroke-width="12" stroke-linecap="round"/>` +
  glyph('user', BRAND, 44, 448, 278, 1.8)));
console.log('wrote', readdirSync(OUT).length, 'files to', OUT);
