/**
 * 招聘页空状态（「招聘板块不要很死板」）：AntD 默认空盒子换成图标 doodle 插画，
 * 与统计卡同一套图（public/recruit/art，生成脚本 frontend/scripts/gen-art.mjs）。白底 JPEG 用 multiply 融进背景。
 *   search 找人（检索没结果） / nofit 库里没有合适的人 / inbox 还没收到 / 还没记录 / list 通用
 * size="small" 给抽屉、卡片里用（图高 90），默认页面级（图高 150）。
 */
import React from 'react';
import { Empty } from 'antd';

export type DoodleKind = 'search' | 'nofit' | 'inbox' | 'list';

const DoodleEmpty: React.FC<{ kind?: DoodleKind; size?: 'small' | 'default'; description?: React.ReactNode; children?: React.ReactNode }> = ({
  kind = 'list', size = 'default', description, children,
}) => (
  <Empty
    image={<img src={`/recruit/art/empty-${kind}.svg`} alt="" aria-hidden loading="lazy" style={{ height: '100%', mixBlendMode: 'multiply' }} />}
    imageStyle={{ height: size === 'small' ? 90 : 150, marginBottom: 8 }}
    description={description}
  >
    {children}
  </Empty>
);

export default DoodleEmpty;
