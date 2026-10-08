/**
 * OpenHunter 招聘 · 页面共用（分数标签、状态/阶段配色、错误提示、简历 blob、日期）。
 * 候选人抽屉在人才库与项目两处打开，用的是同一个组件（§6.7.3 精神：同一业务一份详情）。
 */
import React from 'react';
import { Tag, message, Typography, Progress, Tooltip, Space } from 'antd';
import dayjs from 'dayjs';
import { idrShort, fmtMoney } from '@/utils/money';

export type T = (id: string, vals?: Record<string, any>) => string;

/**
 * 候选人编号 OH-CD-000050-37：6 位序列号 = id，2 位校验码按 ISO 7064 MOD 97-10（IBAN 同款），
 * 错一位 / 相邻两位颠倒一定校验不过。⛔ 与后端 includes/recruit_code.php 同一算法，改一处必须改另一处。
 */
export const candCheck = (id: number) => 98 - ((id * 100) % 97);
export const candCode = (id: number | string | null | undefined) => {
  const n = Number(id);
  return n > 0 ? `OH-CD-${String(n).padStart(6, '0')}-${String(candCheck(n)).padStart(2, '0')}` : '';
};
/** 解析输入的编号：OH-CD-000050-37 / oh cd 50 37 / OHCD00005037 / OH-CD-50 / #50。不是编号 → null；校验码不对 → valid=false */
export const parseCandCode = (s: string | null | undefined): { id: number; valid: boolean } | null => {
  const v = String(s || '').trim();
  let m = v.match(/^#(\d{1,9})$/);
  if (m) return { id: Number(m[1]), valid: true };
  m = v.match(/^OH[\s\-_]*CD[\s\-_]*(\d{1,9})(?:[\s\-_]+(\d{1,2}))?$/i);
  if (!m) return null;
  let num = m[1]; let chk: string | undefined = m[2];
  if (chk === undefined && num.length === 8) { chk = num.slice(6); num = num.slice(0, 6); }
  const id = Number(num);
  if (!(id > 0)) return null;
  return { id, valid: chk === undefined || Number(chk) === candCheck(id) };
};

/**
 * 表格内容全部换行显示、不横向拖（「要能看到所有，不要横着拉，这是我们的设计准则」，CLAUDE.md §6.3）。
 * DetailTable 默认 scroll.x = max-content，长文本会被撑成一行只能横向滚 —— 抽屉 / 弹窗里的表一律展开这个。
 */
export const NO_HSCROLL = { scroll: { x: undefined }, tableLayout: 'fixed' as const };

/**
 * 「AI 评过、不合适」：AI 自动挂上、没人动过（阶段仍是 suggested、没人工分）、AI 分 < 3。
 * 职位的候选人列表与候选人的匹配页签都默认不显示（「不匹配的就不显示」）。
 * ⛔ 与后端 includes/handlers/recruit.php RECRUIT_LOW_HIDE_SQL 同口径，改一处必须改另一处。
 */
export const LOW_LINE = 3;
export const isLowMatch = (m: any) => m?.origin === 'ai' && m?.stage === 'suggested'
  && (m?.human_score === null || m?.human_score === undefined) && m?.ai_score !== null && m?.ai_score !== undefined
  && Number(m.ai_score) < LOW_LINE;

/** 面试线：沿用 docs/候选人看板.html 的口径（≥4 建议面试） */
export const INTERVIEW_LINE = 4;

/** 取有效分：人工分优先，没有才用 AI 分 */
export const effScore = (m: { human_score?: any; ai_score?: any }) => {
  const v = m?.human_score ?? m?.ai_score;
  return v === null || v === undefined || v === '' ? null : Number(v);
};

export const ScoreTag: React.FC<{ score: number | null; t: T; human?: boolean }> = ({ score, t, human }) => {
  if (score === null) return <Tag>{t('pages.recruit.score.none')}</Tag>;
  const color = score >= INTERVIEW_LINE ? 'green' : score >= 3 ? 'gold' : 'default';
  return (
    <Tag color={color} style={{ marginInlineEnd: 0 }}>
      {score.toFixed(1)}
      {score >= INTERVIEW_LINE ? ` · ${t('pages.recruit.score.interview')}` : ''}
      {human ? ` · ${t('pages.recruit.score.human')}` : ''}
    </Tag>
  );
};

export const STATUS_COLOR: Record<string, string> = {
  new: 'blue', contacting: 'cyan', in_process: 'processing', on_hold: 'default', placed: 'green',
  not_interested: 'default', unreachable: 'orange', blacklisted: 'red',
};
export const STAGE_COLOR: Record<string, string> = {
  suggested: 'default', shortlisted: 'blue', submitted: 'cyan', interviewing: 'purple', offered: 'gold',
  hired: 'green', rejected: 'red', withdrawn: 'default', removed: 'default',
};

/** 招聘全生命周期配色（取值见后端 recruit_lifecycle.php，meta.lc 下发） */
export const PL_STATUS_COLOR: Record<string, string> = {
  offer_accepted: 'gold', started: 'processing', guarantee_passed: 'green', ended: 'default', left_in_guarantee: 'red',
  replaced: 'purple', refunded: 'magenta', closed: 'default', no_show: 'volcano', backed_out: 'volcano',
};
export const SERVICE_COLOR: Record<string, string> = {
  contingency: 'blue', retained: 'geekblue', rpo_per_hire: 'cyan', rpo_monthly: 'purple', eor: 'magenta', outsourcing: 'orange',
};
export const CONTRACT_COLOR: Record<string, string> = { draft: 'default', sent: 'processing', signed: 'green', expired: 'orange', terminated: 'red' };
export const MONTHLY_SERVICES = ['rpo_monthly', 'eor', 'outsourcing'];

/**
 * 入职费用：按月薪% = 月薪 × 基数月数 × 费率%；固定 = flat_fee；月费模式 = null。
 * ⛔ 与后端 recruit_lifecycle.php recruitPlacementFee() 同公式，改一处必须改另一处。
 */
export function calcPlacementFee(p: any, salaryMonthly?: number | null): number | null {
  if (!p) return null;
  if (p.fee_type === 'flat_per_hire') return p.flat_fee === null || p.flat_fee === undefined || p.flat_fee === '' ? null : Math.round(Number(p.flat_fee) * 100) / 100;
  if (p.fee_type === 'monthly') return null;
  if (salaryMonthly === null || salaryMonthly === undefined || p.fee_percent === null || p.fee_percent === undefined || p.fee_percent === '') return null;
  return Math.round(Number(salaryMonthly) * Math.max(1, Number(p.fee_basis_months || 12)) * Number(p.fee_percent)) / 100;
}

/** 金额：IDR 速记（jt），其它币种千分位；空显示 - */
export const money = (v: any, ccy = 'IDR') => {
  if (v === null || v === undefined || v === '') return '-';
  return ccy === 'IDR' ? `Rp ${idrShort(Number(v))}` : fmtMoney(Number(v), ccy);
};

/** 一句话费率：「年薪 20%（12 个月）」「每人 Rp 5jt」「每人每月 Rp 8jt」 */
export function feeText(p: any, t: T): string {
  if (!p || p.kind === 'internal') return '-';
  if (p.fee_type === 'flat_per_hire') return t('pages.recruit.terms.feeFlatText', { fee: money(p.flat_fee, p.currency) });
  if (p.fee_type === 'monthly') return t('pages.recruit.terms.feeMonthlyText', { fee: money(p.monthly_fee, p.currency) });
  if (p.fee_percent === null || p.fee_percent === undefined || p.fee_percent === '') return t('pages.recruit.terms.feeUnset');
  return t('pages.recruit.terms.feePctText', { pct: Number(p.fee_percent), months: Number(p.fee_basis_months || 12) });
}
/** 保证期一句话：「90 天 · 免费补人 1 次」 */
export function guaranteeText(p: any, t: T): string {
  if (!p) return '-';
  const d = Number(p.guarantee_days || 0);
  if (!d) return t('pages.recruit.terms.noGuarantee');
  return `${t('pages.recruit.terms.days', { n: d })} · ${p.guarantee_remedy === 'replacement'
    ? t('pages.recruit.terms.replaceN', { n: Number(p.replacement_count || 0) }) : t(`pages.recruit.remedy.${p.guarantee_remedy}`)}`;
}

/**
 * 行业配色 + 图标（「要有颜色、有图标，不要像呆板的数据」）。
 * 图标在 public/recruit/art/ind-<行业码>.svg（frontend/scripts/gen-art.mjs 生成，颜色与这里同色）。
 */
export const INDUSTRY_COLOR: Record<string, string> = {
  it_software: '#2F6BFF', datacenter_infra: '#00A3BF', telecom: '#7B61FF', manufacturing: '#FF7A45', mining_energy: '#FAAD14',
  construction_engineering: '#F5A623', banking_finance: '#13A10E', retail_fmcg: '#EB2F96', logistics: '#1890FF', hospitality_fnb: '#FA541C',
  healthcare: '#F5222D', education: '#722ED1', government: '#597EF7', legal: '#B8860B', hr_recruitment: '#1A2AD6', media_marketing: '#C41D7F',
  agriculture_plantation: '#52C41A', automotive: '#FA8C16', real_estate: '#13C2C2', other: '#8C8C8C',
};
export const industryColor = (code?: string) => INDUSTRY_COLOR[code || ''] || '#BFBFBF';
export const IndustryArt: React.FC<{ code?: string; size?: number; style?: React.CSSProperties }> = ({ code, size = 40, style }) => (
  <img src={`/recruit/art/ind-${INDUSTRY_COLOR[code || ''] ? code : 'other'}.svg`} alt="" width={size} height={size}
    style={{ mixBlendMode: 'multiply', objectFit: 'contain', flexShrink: 0, ...style }} onError={(e) => { (e.target as HTMLImageElement).style.visibility = 'hidden'; }} />
);
/** 热度 0–3 级（企业库卡片的火苗）：看这家公司下有多少人、在职多少、正在我们流程里多少 */
export const heatLevel = (heat: number) => (heat >= 20 ? 3 : heat >= 8 ? 2 : heat >= 3 ? 1 : 0);

/** 企业库（recruit_company.php）：梯队 / 本地海外配色、赛道名按语言取、企业标签 */
export const TIER_COLOR: Record<string, string> = { top: 'gold', mid: 'blue', other: 'default' };
export const REGION_COLOR: Record<string, string> = { local: 'green', overseas: 'purple' };
export const segName = (seg: any, locale: string) => (!seg ? '' : (locale === 'id-ID' ? seg.id || seg.name_id : locale === 'en-US' ? seg.en || seg.name_en : seg.zh || seg.name_zh)
  || seg.zh || seg.name_zh || seg.en || seg.name_en || '');
/** 「美妆 · 第1 · 头部 · 海外」：候选人经历 / 人才库行上的企业标签（bench 由后端 recruitCandidateBench 给） */
export const BenchTag: React.FC<{ bench: any; t: T; locale: string; showName?: boolean }> = ({ bench, t, locale, showName }) => {
  if (!bench) return null;
  const parts = [showName ? bench.name : '', segName(bench.segment, locale), bench.rank ? t('pages.recruit.co.rankN', { n: bench.rank }) : '',
    bench.tier ? t(`pages.recruit.tier.${bench.tier}`) : '', bench.region ? t(`pages.recruit.region.${bench.region}`) : ''].filter(Boolean);
  if (!parts.length) return null;
  return <Tag color={bench.rank ? 'gold' : bench.tier === 'top' ? 'orange' : 'default'} style={{ fontSize: 11, marginInlineEnd: 0 }}>{parts.join(' · ')}</Tag>;
};

/** 后端业务错误带 message_key；没有才用 errorMessage，再没有用默认文案 */
export function showErr(res: any, t: T, fallbackKey = 'pages.recruit.msg.failed') {
  message.error(res?.message_key ? t(res.message_key) : res?.errorMessage || t(fallbackKey));
}

export const fmtDay = (v?: string | null) => (v ? String(v).slice(0, 10) : '-');
export const fmtMin = (v?: string | null) => (v ? String(v).slice(0, 16) : '-');

/** 年龄（出生日期只有年份也能算）；算不出返回 null */
export const ageOf = (birth?: string | null): number | null => {
  const y = Number(String(birth || '').slice(0, 4));
  if (!y || y < 1940) return null;
  return new Date().getFullYear() - y;
};

/** 「3 天前」「今天」这类相对时间（只到天，跟进节奏按天看就够了） */
export function agoText(v: string | null | undefined, t: T): string {
  if (!v) return '';
  const d = dayjs().startOf('day').diff(dayjs(v).startOf('day'), 'day');
  return d <= 0 ? t('pages.recruit.ago.today') : d === 1 ? t('pages.recruit.ago.yesterday') : t('pages.recruit.ago.days', { n: d });
}

/**
 * 跟进情况（「要能看清是人工跟进还是 AI 在跟」）：
 *   human：有人工跟进记录 → 谁、几天前、渠道、说了什么；没记录但人动过职位 → 提示已处理未记录
 *   ai   ：只有 AI 推荐的匹配，还没人碰 → 醒目标「待人工跟进」
 *   none ：没匹配也没人跟
 */
/**
 * 语言情况（「语言情况一定要标明：简历原文印尼语、没写英语就标出来」）：
 * 中文 / 英语 / 印尼语 三个固定位，简历写了就显示程度，没写显示「未提及」（灰）；其它语言（闽南话、粤语…）跟在后面；末尾标简历原文语言。
 * 数据来自 languages_json（含归一 code / level，见 recruit_parse.php）。compact 给列表用：只显示三个固定位 + 其它语言名。
 */
export const LANG_FIXED = ['zh', 'en', 'id'] as const;
export const LangTags: React.FC<{ langs: any; resumeLang?: string; t: T; compact?: boolean }> = ({ langs, resumeLang, t, compact }) => {
  const list: any[] = Array.isArray(langs) ? langs : (typeof langs === 'string' && langs ? (() => { try { return JSON.parse(langs); } catch { return []; } })() : []);
  const byCode = (c: string) => list.find((l) => l.code === c);
  const others = list.filter((l) => !LANG_FIXED.includes(l.code));
  const fs = compact ? 11 : 12;
  return (
    <Space size={[4, 4]} wrap>
      {LANG_FIXED.map((c) => {
        const l = byCode(c);
        const lvl = l?.level ? t(`pages.recruit.langLevel.${l.level}`) : l ? t('pages.recruit.langLevel.unknown') : '';
        return l
          ? <Tag key={c} color={c === 'zh' ? 'red' : c === 'en' ? 'blue' : 'green'} style={{ fontSize: fs, marginInlineEnd: 0 }} title={l.level_raw || ''}>{t(`pages.recruit.lang.${c}`)} · {lvl}</Tag>
          : <Tag key={c} style={{ fontSize: fs, marginInlineEnd: 0, opacity: 0.55 }}>{t(`pages.recruit.lang.${c}`)} · {t('pages.recruit.p.notStated')}</Tag>;
      })}
      {others.map((l, i) => (
        <Tag key={`o${i}`} color="purple" style={{ fontSize: fs, marginInlineEnd: 0 }} title={l.language}>
          {l.code && l.code !== 'other' ? t(`pages.recruit.lang.${l.code}`) : l.language}{l.level ? ` · ${t(`pages.recruit.langLevel.${l.level}`)}` : ''}
        </Tag>))}
      {!compact && !!resumeLang && <Typography.Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.p.resumeLang', { lang: t(`pages.recruit.lang.${resumeLang}`) })}</Typography.Text>}
    </Space>
  );
};

/** 宗教 / 婚姻 / 民族（只显示简历明写的；没写显示「未提及」）。compact：只显示有值的 */
export const PersonalTags: React.FC<{ row: any; t: T; compact?: boolean }> = ({ row, t, compact }) => {
  const items: [string, string, string][] = [
    ['religion', row.religion || row.personal?.religion || '', row.religion_code || ''],
    ['marital', row.marital_status || row.personal?.marital_status || '', row.marital_code || ''],
    ['ethnicity', row.ethnicity || row.personal?.ethnicity || '', ''],
  ];
  return (
    <Space size={[4, 4]} wrap>
      {items.map(([k, raw, code]) => {
        if (compact && !raw) return null;
        const label = code && code !== 'other' ? t(`pages.recruit.${k === 'marital' ? 'marital' : 'religion'}.${code}`) : raw;
        return raw
          ? <Tag key={k} color="gold" style={{ fontSize: compact ? 11 : 12, marginInlineEnd: 0 }} title={raw}>{t(`pages.recruit.p.${k}`)}: {label}</Tag>
          : <Tag key={k} style={{ fontSize: 12, marginInlineEnd: 0, opacity: 0.55 }}>{t(`pages.recruit.p.${k}`)}: {t('pages.recruit.p.notStated')}</Tag>;
      })}
    </Space>
  );
};

export const FollowCell: React.FC<{ row: any; t: T }> = ({ row, t }) => {
  const n = row.last_note;
  if (row.follow_mode === 'human') {
    return (
      <div>
        <Tag color="green" style={{ marginInlineEnd: 4 }}>{t('pages.recruit.follow.human')}</Tag>
        {n ? (
          <>
            <Typography.Text style={{ fontSize: 12 }}>{n.created_by_name} · {agoText(n.created_at, t)}</Typography.Text>
            {n.channel && <Typography.Text type="secondary" style={{ fontSize: 12 }}> · {t(`pages.recruit.channel.${n.channel}`)}</Typography.Text>}
            {!!n.content && (
              <div title={n.content} style={{ fontSize: 12, color: '#555', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 210 }}>
                {n.content}
              </div>
            )}
            {Number(n.n) > 1 && <Typography.Text type="secondary" style={{ fontSize: 11 }}>{t('pages.recruit.follow.count', { n: n.n })}</Typography.Text>}
          </>
        ) : <div><Typography.Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.follow.touched')}</Typography.Text></div>}
      </div>
    );
  }
  if (row.follow_mode === 'ai') {
    return (
      <div>
        <Tag color="blue" style={{ marginInlineEnd: 4 }}>{t('pages.recruit.follow.ai')}</Tag>
        <div><Typography.Text type="warning" style={{ fontSize: 12 }}>{t('pages.recruit.follow.aiPending')}</Typography.Text></div>
      </div>
    );
  }
  return <Tag>{t('pages.recruit.follow.none')}</Tag>;
};

/** 语义匹配的 4 个维度（与 includes/recruit_embed.php RECRUIT_FACETS 同序） */
export const FACETS = ['skills', 'experience', 'industry', 'headline'] as const;

/**
 * 各维度语义相似度条。向量相似度不是分数：只说明「可能相关」，最终以大模型逐条对照要求为准，
 * 所以只显示小条、不显示成绩单式大数字。
 */
export const FacetBars: React.FC<{ facets?: Record<string, number> | null; t: T }> = ({ facets, t }) => {
  if (!facets || !Object.keys(facets).length) return null;
  return (
    <Tooltip title={t('pages.recruit.facet.hint')}>
      <div style={{ lineHeight: '14px' }}>
        {FACETS.filter((f) => facets[f] !== undefined).map((f) => {
          const v = Math.max(0, Math.min(1, Number(facets[f])));
          return (
            <div key={f} style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 11, color: '#666' }}>
              <span style={{ width: 44, whiteSpace: 'nowrap' }}>{t(`pages.recruit.facet.${f}`)}</span>
              <Progress percent={Math.round(v * 100)} size="small" showInfo={false} style={{ width: 60, margin: 0 }}
                strokeColor={v >= 0.7 ? '#389e0d' : v >= 0.5 ? '#d48806' : '#bfbfbf'} />
              <span>{v.toFixed(2)}</span>
            </div>
          );
        })}
      </div>
    </Tooltip>
  );
};

