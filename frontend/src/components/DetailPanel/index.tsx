import React from 'react';
import { Descriptions, Table } from 'antd';
import type { TableProps } from 'antd';

// 详情弹窗/抽屉的统一规格。抽这里的原因：全站 49 块 Descriptions 里只有 5 块设了 labelStyle，
// 同一个弹窗上下两块标签列宽度对不齐；Modal 宽度散着 19 种值。
// 新写详情区一律用这里的 DetailBlock / DetailTable / DETAIL_WIDTH，别再手写配置。

/**
 * 弹窗宽度三档。判据是内容形态，不是"感觉多宽合适"：
 *   form     纯表单（无 Descriptions、字段 ≤8）
 *   info     信息详情（Descriptions column=2，无明细表格）
 *   withTable 内含明细 Table
 * 720 是 column={2} 的下限——580 时标签列只剩两字宽，四字标签会竖着断行。
 */
export const DETAIL_WIDTH = { form: 520, info: 720, withTable: 960 } as const;

/** 标签列宽。现存 88/96/100 三种，取 96：够放四字标签，印尼语长标签靠 nowrap + 内容换行兜住 */
const LABEL_WIDTH = 96;

/**
 * 统一配置的 Descriptions 包装。
 * 子元素仍用原生 <Descriptions.Item>——这样页面改造只换开合标签，不动内部条目。
 */
export const DetailBlock: React.FC<{
  column?: 1 | 2 | 3;
  labelWidth?: number;
  title?: React.ReactNode;
  style?: React.CSSProperties;
  children: React.ReactNode;
}> = ({ column = 2, labelWidth = LABEL_WIDTH, title, style, children }) => (
  // 注意：子元素若写 {someString && <Item/>}，字符串为空时会留下 '' child，
  // AntD toArray 保留字符串并当成幽灵 item（span=1），把后续 span=2 的项挤进同一行、
  // 左侧空出两格。条件必须用 !! 转布尔（真实踩过：审批详情「说明」「收据凭证」错位）
  <div className="oh-detail-block" style={{ marginBottom: 12, ...style }}>
    <Descriptions
      size="small"
      bordered
      column={column}
      title={title}
      labelStyle={{ width: labelWidth, whiteSpace: 'nowrap' }}
      contentStyle={{ wordBreak: 'break-word' }}
    >
      {children}
    </Descriptions>
  </div>
);

/**
 * 详情弹窗里的明细表格。
 * scroll x=max-content 是必需的——长客户名会把弹窗撑破，横向滚动必须发生在表格内部。
 */
export const DetailTable: React.FC<TableProps<any>> = ({ pagination, scroll, ...rest }) => (
  <Table
    size="small"
    style={{ marginTop: 6 }}
    scroll={{ x: 'max-content', ...(scroll || {}) }}
    pagination={
      pagination === false
        ? false
        : { pageSize: 10, size: 'small', showSizeChanger: true,
            pageSizeOptions: ['10', '20', '50', '100'], ...(pagination || {}) }
    }
    {...rest}
  />
);

export default DetailBlock;
