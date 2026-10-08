/**
 * 人才库每行的操作（「可以收藏这个简历，也可以直接在这里写跟进备注」）：
 *   ⭐ 收藏 / 取消收藏（每人自己的收藏夹，列表可「只看收藏」）
 *   📝 快速跟进：气泡里选渠道、写内容、可顺手改状态，保存即写一条人工跟进（与候选人抽屉里的跟进是同一个接口）
 * ⛔ 气泡内容经 portal 渲染，React 事件仍会冒泡到表格行的 onClick（打开抽屉）——外层 stopPropagation 拦住。
 */
import React, { useState } from 'react';
import { Button, Popover, Select, Input, Space, Tooltip, message } from 'antd';
import { StarFilled, StarOutlined, FormOutlined } from '@ant-design/icons';
import { recruitToggleFavorite, recruitAddFollowup } from '@/services/api';
import { showErr, type T, candCode } from '../common';

const CHANNELS = ['whatsapp', 'phone', 'email', 'meeting', 'other'];
const STATUSES = ['contacting', 'in_process', 'on_hold', 'not_interested', 'unreachable'];

const RowActions: React.FC<{ row: any; t: T; onChanged: () => void }> = ({ row, t, onChanged }) => {
  const [fav, setFav] = useState<boolean>(!!row.favorited);
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState<{ channel: string; content: string; status_after?: string }>({ channel: 'whatsapp', content: '' });
  const [saving, setSaving] = useState(false);

  const toggleFav = async () => {
    const next = !fav;
    setFav(next);   // 先变，失败再回滚，点起来不卡
    const r = await recruitToggleFavorite(Number(row.id), next);
    if (!r?.success) { setFav(!next); showErr(r, t); return; }
    onChanged();   // 重新排序（收藏的置顶）、更新「我的收藏」数量
  };
  const save = async () => {
    if (!note.content.trim() && !note.status_after) return;
    setSaving(true);
    try {
      const r = await recruitAddFollowup({ candidate_id: row.id, channel: note.channel, content: note.content.trim(), status_after: note.status_after || '' });
      if (r?.success) { message.success(t('pages.recruit.msg.saved')); setOpen(false); setNote({ channel: note.channel, content: '' }); onChanged(); }
      else showErr(r, t);
    } finally { setSaving(false); }
  };

  const panel = (
    <div style={{ width: 320 }} onClick={(e) => e.stopPropagation()}>
      <Space.Compact style={{ width: '100%', marginBottom: 8 }}>
        <Select style={{ width: 130 }} value={note.channel} onChange={(v) => setNote({ ...note, channel: v })}
          options={CHANNELS.map((c) => ({ value: c, label: t(`pages.recruit.channel.${c}`) }))} />
        <Select style={{ flex: 1 }} allowClear placeholder={t('pages.recruit.fu.statusAfter')} value={note.status_after}
          onChange={(v) => setNote({ ...note, status_after: v })}
          options={STATUSES.map((s) => ({ value: s, label: t(`pages.recruit.status.${s}`) }))} />
      </Space.Compact>
      <Input.TextArea autoFocus rows={3} maxLength={2000} placeholder={t('pages.recruit.fu.contentPh')} value={note.content}
        onChange={(e) => setNote({ ...note, content: e.target.value })}
        onPressEnter={(e) => { if (e.metaKey || e.ctrlKey) save(); }} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
        <span style={{ fontSize: 12, color: '#8c8c8c' }}>{t('pages.recruit.quick.hint')}</span>
        <Space>
          <Button size="small" onClick={() => setOpen(false)}>{t('pages.recruit.cancel')}</Button>
          <Button size="small" type="primary" loading={saving} disabled={!note.content.trim() && !note.status_after} onClick={save}>{t('pages.recruit.fu.add')}</Button>
        </Space>
      </div>
    </div>
  );

  return (
    <Space size={2} onClick={(e) => e.stopPropagation()}>
      <Tooltip title={t(fav ? 'pages.recruit.quick.unfav' : 'pages.recruit.quick.fav')}>
        <Button type="text" size="small" onClick={toggleFav}
          icon={fav ? <StarFilled style={{ color: '#faad14', fontSize: 16 }} /> : <StarOutlined style={{ color: '#bfbfbf', fontSize: 16 }} />} />
      </Tooltip>
      <Popover open={open} onOpenChange={setOpen} trigger="click" placement="leftTop" content={panel}
        title={t('pages.recruit.quick.title', { name: row.name || candCode(row.id) })}>
        <Tooltip title={open ? '' : t('pages.recruit.quick.note')}>
          <Button type="text" size="small" icon={<FormOutlined style={{ color: '#1a2ad6', fontSize: 16 }} />} />
        </Tooltip>
      </Popover>
    </Space>
  );
};

export default RowActions;
