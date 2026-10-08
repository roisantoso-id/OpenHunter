/**
 * 招聘页统计卡（手绘风，招聘板块不要很死板）。
 * 每张卡右侧一张手绘 doodle 插图（近黑实心 + 粗糙手绘线 + 金色点缀，同一个扎丸子头的招聘顾问做主角），
 * 图由 scripts/ops/gen_recruit_art.js（GPT Image 2）生成，放 public/recruit/art/<name>.jpg，白底 JPEG——
 * 页面用 mix-blend-mode: multiply 把白底融进卡片 / 选中时的渐变底，不需要透明图。
 * 简历解析 / 人才库 / 人力工作台 / 招聘项目 / 招聘绩效共用，风格统一。
 */
import React from 'react';
import { Card, Typography } from 'antd';

const { Text } = Typography;

/** 可用的插图名（与生成脚本 PROMPTS 的 name 一一对应；没有的名字回退 files） */
const ARTS = new Set([
  'files', 'inbox', 'parsed', 'queue', 'failed', 'review', 'notcv', 'newPerson', 'unclaimed', 'due', 'star', 'warm',
  'people', 'rocket', 'placed', 'month', 'recommend', 'interview', 'followup', 'aicost',
]);
export const artUrl = (name: string) => `/recruit/art/${ARTS.has(name) ? name : 'files'}.jpg`;

export const StatArt: React.FC<{ name: string; size?: number }> = ({ name, size = 64 }) => (
  <img src={artUrl(name)} alt="" aria-hidden loading="lazy" width={size} height={size}
    style={{ flexShrink: 0, objectFit: 'contain', mixBlendMode: 'multiply' }} />
);

interface Props {
  art: string;
  title: React.ReactNode;
  value: React.ReactNode;
  onClick?: () => void;
  active?: boolean;
  danger?: boolean;
  sub?: React.ReactNode;
  minWidth?: number;
  valueColor?: string;
}

const StatCard: React.FC<Props> = ({ art, title, value, onClick, active, danger, sub, minWidth = 140, valueColor }) => (
  <Card size="small" hoverable={!!onClick} onClick={onClick}
    style={{ minWidth, flex: `1 1 ${minWidth}px`, height: '100%', borderColor: active ? '#1a2ad6' : undefined,
      background: active ? 'linear-gradient(135deg, #f5f7ff 0%, #fff 70%)' : undefined }}
    bodyStyle={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, padding: '8px 10px 8px 14px' }}>
    <div style={{ minWidth: 0 }}>
      <Text type="secondary" style={{ fontSize: 12 }}>{title}</Text>
      <div style={{ fontSize: 24, fontWeight: 600, lineHeight: 1.3, color: danger ? '#cf1322' : valueColor }}>{value}</div>
      {!!sub && <Text type="secondary" style={{ fontSize: 11 }}>{sub}</Text>}
    </div>
    <StatArt name={art} />
  </Card>
);

export default StatCard;
