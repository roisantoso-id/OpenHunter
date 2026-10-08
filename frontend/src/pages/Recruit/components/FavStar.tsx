/**
 * 收藏星（「看了简历要能收藏，在我的收藏里再看到，不然每次看了没意义」）。
 * 与人才库每行的 ⭐ 是同一个收藏夹（recruitToggleFavorite，每人自己的），收藏后在「人才库 · 我的收藏」里看。
 * 先变后请求，失败回滚；点击不冒泡（放在可点的卡片 / 行里也不会误开抽屉）。
 */
import React, { useEffect, useState } from 'react';
import { Button, Tooltip, message } from 'antd';
import { StarFilled, StarOutlined } from '@ant-design/icons';
import { history } from '@umijs/max';
import { recruitToggleFavorite } from '@/services/api';
import { showErr, type T } from '../common';

const FavStar: React.FC<{ id: number; on?: boolean; t: T; withText?: boolean; onChange?: (on: boolean) => void }> = ({ id, on, t, withText, onChange }) => {
  const [fav, setFav] = useState(!!on);
  useEffect(() => setFav(!!on), [id, on]);
  const toggle = async (e: React.MouseEvent) => {
    e.stopPropagation();
    const next = !fav;
    setFav(next);
    const r = await recruitToggleFavorite(id, next);
    if (!r?.success) { setFav(!next); showErr(r, t); return; }
    if (next) {
      message.success({ content: <span>{t('pages.recruit.fav.added')} <a onClick={() => history.push('/recruit/talent?fav=1')}>{t('pages.recruit.fav.open')}</a></span> });
    }
    onChange?.(next);
  };
  const icon = fav ? <StarFilled style={{ color: '#faad14' }} /> : <StarOutlined />;
  return withText ? (
    <Button size="small" icon={icon} onClick={toggle}>{t(fav ? 'pages.recruit.fav.on' : 'pages.recruit.fav.off')}</Button>
  ) : (
    <Tooltip title={t(fav ? 'pages.recruit.fav.on' : 'pages.recruit.fav.off')}><Button size="small" type="text" icon={icon} onClick={toggle} /></Tooltip>
  );
};

export default FavStar;
