// 印尼盾（IDR）速记显示工具
// 印尼数字习惯：rb = ribu(千, 1e3)，jt = juta(百万, 1e6)，miliar = 十亿(1e9)
//   100 万印尼盾 = 1jt，10 亿印尼盾 = 1miliar
// 仅用于 IDR；人民币(¥)沿用千分位整数，jt/M 是印尼单位不适用。

/** 去掉小数尾零：1.00 → "1"，2.29 → "2.29" */
const trim2 = (x: number) => parseFloat(x.toFixed(2)).toString();

/** 把 IDR 金额转成速记字符串（不带 Rp 前缀）：2,291,193,990 → "2.29miliar"，30,790,410 → "30.79jt" */
export function idrShort(value: number): string {
  const n = Number(value || 0);
  const sign = n < 0 ? '-' : '';
  const a = Math.abs(n);
  if (a >= 1e9) return `${sign}${trim2(a / 1e9)}miliar`;
  if (a >= 1e6) return `${sign}${trim2(a / 1e6)}jt`;
  if (a >= 1e3) return `${sign}${trim2(a / 1e3)}rb`;
  return `${sign}${Math.round(a).toLocaleString()}`;
}

/** 带 Rp 前缀的速记：→ "Rp 2.29M" */
export const fmtRupiahShort = (value: number) => `Rp ${idrShort(value)}`;

/**
 * 完整金额（非速记），带币种符号。列表里要对账的金额用这个，速记会丢精度。
 * IDR 走印尼千分位（点号）且不留小数：283333.34 → "Rp 283.333"
 * 其它币种沿用本地千分位：CNY 1234.5 → "¥ 1,234.5"
 */
export function fmtMoney(value: number, currency = 'IDR'): string {
  const n = Number(value || 0);
  if (currency === 'IDR' || currency === 'Rp') {
    return `Rp ${Math.round(n).toLocaleString('id-ID')}`;
  }
  const sym = currency === 'CNY' ? '¥' : currency === 'USD' ? '$' : `${currency} `;
  return `${sym}${n.toLocaleString()}`;
}
