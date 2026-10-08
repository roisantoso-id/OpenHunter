/**
 * Settings: recruiting configuration (mailboxes & recruiter codes, auto-parse, AI pricing/budget,
 * warm-up reminders, semantic matching, recommendation templates, AI prompts, recruiting permissions).
 * Everything lives in RecruitSettingsPanel; RecruitPermPanel is one of its tabs.
 */
import React from 'react';
import { Card } from 'antd';
import { useIntl } from '@umijs/max';
import RecruitSettingsPanel from '../Recruit/components/RecruitSettingsPanel';

const Settings: React.FC = () => {
  const intl = useIntl();
  return (
    <Card title={intl.formatMessage({ id: 'pages.recruit.cfg.menu' })}>
      <RecruitSettingsPanel />
    </Card>
  );
};

export default Settings;
