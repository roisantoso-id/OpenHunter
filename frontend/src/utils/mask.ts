/**
 * 列表里的联系方式打码（CLAUDE.md §6.3：列表会被截图/投屏）。全值挂在 title 上供悬停查看，
 * 详情抽屉里才显示完整号码。
 */

/** +6281234567890 → +62 812****7890；0812-3456-7890 → 0812****7890 */
export function maskPhone(v?: string | null): string {
  const s = String(v || '').trim();
  if (!s) return '';
  const digits = s.replace(/[^\d+]/g, '');
  if (digits.length < 8) return s;
  const cc = digits.startsWith('+') ? (digits.match(/^\+(62|86|\d{1,3})/)?.[0] ?? '') : '';
  const rest = digits.slice(cc.length);
  return `${cc ? `${cc} ` : ''}${rest.slice(0, 3)}****${rest.slice(-4)}`;
}

/** budi.santoso@gmail.com → bu***@gmail.com */
export function maskEmail(v?: string | null): string {
  const s = String(v || '').trim();
  const at = s.indexOf('@');
  if (at < 1) return s;
  return `${s.slice(0, Math.min(2, at))}***${s.slice(at)}`;
}
