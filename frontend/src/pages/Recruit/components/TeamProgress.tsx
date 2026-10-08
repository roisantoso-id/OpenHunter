/**
 * 团队跟进进度（「管理员看到所有人的，我需要管理这个团队的跟进进度」）。
 * 只有能看全部候选人的人（recruit_all / recruit_admin）拿得到数据，其他人不显示这块。
 * 每位招聘专员一行；数字可点 → onDrill(同一组筛选参数)，人才库列表按后端 recruitCandidateFilterSql 同口径筛出这些人（§6.7.1）。
 */
import React, { useCallback, useEffect, useState } from 'react';
import { Card, Table, Typography, Tooltip, Tag } from 'antd';
import { QuestionCircleOutlined } from '@ant-design/icons';
import { recruitTeamProgress } from '@/services/api';
import { agoText, fmtDay, NO_HSCROLL, type T } from '../common';

const { Text } = Typography;

interface Props {
  t: T;
  /** 点数字：套上这组筛选（会替换当前筛选） */
  onDrill: (filter: Record<string, any>) => void;
  /** 变化时重新拉（列表里写了跟进 / 改了状态后刷新） */
  reloadKey?: number;
}

const TeamProgress: React.FC<Props> = ({ t, onDrill, reloadKey }) => {
  const [data, setData] = useState<{ noted_from: string; rows: any[] } | null>(null);
  const load = useCallback(() => {
    recruitTeamProgress().then((r: any) => setData(r?.success ? r.data : null));
  }, []);
  useEffect(() => { load(); }, [load, reloadKey]);
  if (!data) return null;

  const owner = (r: any) => (r.user_id > 0 ? String(r.user_id) : 'unclaimed');
  // 数字：0 灰色不可点；warn=true 的非 0 标橙（该有人处理）
  const num = (n: number, filter: Record<string, any>, warn = false) => (n > 0
    ? <a onClick={(e) => { e.stopPropagation(); onDrill(filter); }} style={{ fontWeight: 600, color: warn ? '#d46b08' : undefined }}>{n}</a>
    : <Text type="secondary">0</Text>);

  const columns = [
    { title: t('pages.recruit.team.user'), width: 130, render: (_: any, r: any) => (r.user_id > 0 ? r.user_name : <Tag color="orange">{t('pages.recruit.unclaimed')}</Tag>) },
    { title: t('pages.recruit.team.owned'), width: 70, align: 'right' as const, render: (_: any, r: any) => num(r.owned, { owner_id: owner(r) }) },
    { title: t('pages.recruit.team.human'), width: 80, align: 'right' as const, render: (_: any, r: any) => num(r.human, { owner_id: owner(r), follow: 'human' }) },
    { title: t('pages.recruit.team.ai'), width: 90, align: 'right' as const, render: (_: any, r: any) => num(r.ai, { owner_id: owner(r), follow: 'ai' }, true) },
    { title: t('pages.recruit.team.none'), width: 80, align: 'right' as const, render: (_: any, r: any) => num(r.none, { owner_id: owner(r), follow: 'none' }) },
    { title: t('pages.recruit.team.due'), width: 90, align: 'right' as const, render: (_: any, r: any) => num(r.due, { owner_id: owner(r), due: true }, true) },
    { title: t('pages.recruit.team.strong'), width: 100, align: 'right' as const, render: (_: any, r: any) => num(r.strong_new, { owner_id: owner(r), status: 'new', min_score: 4 }, true) },
    { title: <Tooltip title={t('pages.recruit.team.noted7Hint')}>{t('pages.recruit.team.noted7')} <QuestionCircleOutlined style={{ color: '#999' }} /></Tooltip>,
      width: 110, align: 'right' as const,
      render: (_: any, r: any) => (r.user_id > 0 ? num(r.noted_7d, { noted_by: r.user_id, noted_name: r.user_name, noted_from: data.noted_from }) : <Text type="secondary">-</Text>) },
    { title: t('pages.recruit.team.last'), width: 120, render: (_: any, r: any) => (r.user_id > 0
        ? (r.last_note_at
          ? <><div>{fmtDay(r.last_note_at)}</div><Text type="secondary" style={{ fontSize: 12 }}>{agoText(r.last_note_at, t)}</Text></>
          : <Text type="warning">{t('pages.recruit.team.never')}</Text>)
        : <Text type="secondary">-</Text>) },
  ];

  return (
    <Card size="small" style={{ marginBottom: 14 }}
      title={t('pages.recruit.team.title')}
      extra={<Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.team.hint')}</Text>}>
      <Table rowKey={(r: any) => owner(r)} size="small" pagination={false} dataSource={data.rows} columns={columns as any} {...NO_HSCROLL} />
    </Card>
  );
};

export default TeamProgress;
