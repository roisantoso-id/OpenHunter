/**
 * 候选人抽屉「关联」页签（「他投 HR 岗，但经历里有医疗器械，可以推荐给别的客户；
 * 即使不招他，也能通过他去找其他合适的人」）。三块：
 *   ① 适合的其他在招职位：向量比对全部在招职位，不限他投的岗；一键加入后 AI 逐条打分
 *   ② 像他这样的人：可只按某一维度找（只按「行业」= 同行业的人）
 *   ③ 前同事 / 校友：按公司、学校名称（不靠向量，向量没通也能用），标出同期
 * 后端：recruitRelatedJobs / recruitSimilarCandidates(facet) / recruitRelatedPeople（includes/recruit_related.php）。
 */
import React, { useEffect, useState } from 'react';
import { Button, Empty, Segmented, Space, Spin, Tag, Tooltip, Typography, message } from 'antd';
import { PlusOutlined, SearchOutlined } from '@ant-design/icons';
import { history } from '@umijs/max';
import { DetailTable } from '@/components/DetailPanel';
import { recruitAddMatch, recruitRelatedJobs, recruitRelatedPeople, recruitSimilarCandidates } from '@/services/api';
import { FacetBars, ScoreTag, STAGE_COLOR, STATUS_COLOR, showErr, candCode, NO_HSCROLL } from '../common';

const { Text, Title } = Typography;
type T = (id: string, v?: Record<string, any>) => string;
const FACET_OPTS = ['all', 'skills', 'experience', 'industry', 'headline'];

const Section: React.FC<{ title: string; hint: string; extra?: React.ReactNode; children: React.ReactNode }> = ({ title, hint, extra, children }) => (
  <div style={{ marginBottom: 22 }}>
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
      <Title level={5} style={{ margin: 0 }}>{title}</Title>
      {extra}
    </div>
    <Text type="secondary" style={{ fontSize: 12, display: 'block', margin: '2px 0 8px' }}>{hint}</Text>
    {children}
  </div>
);

const personCell = (r: any, t: T) => (
  <><div><Text strong>{r.name || t('pages.recruit.noName')}</Text> <Text type="secondary" style={{ fontSize: 12 }}>{candCode(r.id)}</Text></div>
    <Text type="secondary" style={{ fontSize: 12 }}>{r.latest_title}{r.latest_company ? ` @ ${r.latest_company}` : ''}</Text></>
);
const statusCell = (r: any, t: T) => (
  <><Tag color={STATUS_COLOR[r.status]}>{t(`pages.recruit.status.${r.status}`)}</Tag>
    <div><Text type="secondary" style={{ fontSize: 12 }}>{r.owner_user_name || t('pages.recruit.unclaimed')}</Text></div></>
);
const spin = <Spin style={{ display: 'block', margin: '16px auto' }} />;

const RelatedTab: React.FC<{ cid: number; t: T; onOpenCandidate: (id: number) => void; onLinked: () => void }> = ({ cid, t, onOpenCandidate, onLinked }) => {
  const [jobs, setJobs] = useState<{ rows: any[]; hasVector: boolean } | null>(null);
  const [facet, setFacet] = useState('all');
  const [similar, setSimilar] = useState<{ rows: any[]; hasVector: boolean } | null>(null);
  const [people, setPeople] = useState<any[] | null>(null);
  const [adding, setAdding] = useState(0);

  const loadJobs = () => recruitRelatedJobs(cid).then((r: any) => (r?.success ? setJobs({ rows: r.data || [], hasVector: !!r.has_vector }) : showErr(r, t)));
  useEffect(() => {
    setJobs(null); setPeople(null);
    loadJobs();
    recruitRelatedPeople(cid).then((r: any) => (r?.success ? setPeople(r.data || []) : showErr(r, t)));
  }, [cid]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    setSimilar(null);
    recruitSimilarCandidates(cid, facet === 'all' ? undefined : facet)
      .then((r: any) => (r?.success ? setSimilar({ rows: r.data || [], hasVector: !!r.has_vector }) : showErr(r, t)));
  }, [cid, facet]); // eslint-disable-line react-hooks/exhaustive-deps

  const addToJob = async (jobId: number) => {
    setAdding(jobId);
    try {
      const r = await recruitAddMatch(cid, jobId);
      if (!r?.success) { showErr(r, t); return; }
      message.success(t('pages.recruit.related.added'));
      await loadJobs();
      onLinked();
    } finally { setAdding(0); }
  };
  const noVector = <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('pages.recruit.similar.noVector')} />;
  const rowOpen = (r: any) => ({ onClick: () => onOpenCandidate(Number(r.id)) });

  return (
    <>
      <Section title={t('pages.recruit.related.jobs')} hint={t('pages.recruit.related.jobsHint')}>
        {!jobs ? spin : !jobs.hasVector ? noVector : (
          <DetailTable {...NO_HSCROLL} rowKey="id" dataSource={jobs.rows} locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('pages.recruit.related.noJobs')} /> }}
            columns={[
              { title: t('pages.recruit.related.col.job'), render: (_: any, r: any) => (
                  <><div><Text strong>{r.title}</Text>{r.location ? <Text type="secondary" style={{ fontSize: 12 }}> · {r.location}</Text> : null}</div>
                    <Text type="secondary" style={{ fontSize: 12 }}>{r.project_name}{r.group_name ? ` · ${r.group_name}` : ''}</Text></>) },
              { title: t('pages.recruit.related.col.relevance'), width: 180, render: (_: any, r: any) => <FacetBars facets={r.facets} t={t} /> },
              { title: '', width: 150, align: 'right' as const, render: (_: any, r: any) => (r.linked_stage ? (
                  <Space direction="vertical" size={2} align="end">
                    <Tag color={STAGE_COLOR[r.linked_stage]}>{t(`pages.recruit.stage.${r.linked_stage}`)}</Tag>
                    <ScoreTag score={r.linked_score === null ? null : Number(r.linked_score)} t={t} />
                  </Space>
                ) : (
                  <Button size="small" icon={<PlusOutlined />} loading={adding === Number(r.id)} onClick={() => addToJob(Number(r.id))}>
                    {t('pages.recruit.related.addToJob')}
                  </Button>
                )) },
            ]} />
        )}
      </Section>

      <Section title={t('pages.recruit.related.similar')} hint={t('pages.recruit.similar.hint')}
        extra={<Space size={8} wrap>
          <Segmented size="small" value={facet} onChange={(v) => setFacet(String(v))}
            options={FACET_OPTS.map((f) => ({ value: f, label: f === 'all' ? t('pages.recruit.related.facetAll') : t(`pages.recruit.facet.${f}`) }))} />
          {/* 抽屉里只列前 10；要看更多、加筛选、存成人才池就去人才检索页 */}
          <Button size="small" type="link" icon={<SearchOutlined />}
            onClick={() => history.push(`/recruit/search?seed=${cid}${facet !== 'all' ? `&facet=${facet}` : ''}`)}>{t('pages.recruit.finder.openInSearch')}</Button>
        </Space>}>
        {!similar ? spin : !similar.hasVector ? noVector : (
          <DetailTable {...NO_HSCROLL} rowKey="id" dataSource={similar.rows} rowClassName={() => 'recruit-row'} onRow={rowOpen}
            locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
            columns={[
              { title: t('pages.recruit.col.candidate'), render: (_: any, r: any) => personCell(r, t) },
              { title: t('pages.recruit.col.edu'), width: 110, render: (_: any, r: any) => (
                  <>{r.highest_edu ? t(`pages.recruit.edu.${r.highest_edu}`) : '-'}
                    {r.years_exp !== null && <div><Text type="secondary" style={{ fontSize: 12 }}>{t('pages.recruit.years', { n: r.years_exp })}</Text></div>}</>) },
              { title: t('pages.recruit.similar.col'), width: 170, render: (_: any, r: any) => <FacetBars facets={r.facets} t={t} /> },
              { title: t('pages.recruit.col.status'), width: 120, render: (_: any, r: any) => statusCell(r, t) },
            ]} />
        )}
      </Section>

      <Section title={t('pages.recruit.related.people')} hint={t('pages.recruit.related.peopleHint')}>
        {!people ? spin : (
          <DetailTable {...NO_HSCROLL} rowKey="id" dataSource={people} rowClassName={() => 'recruit-row'} onRow={rowOpen}
            locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('pages.recruit.related.noPeople')} /> }}
            columns={[
              { title: t('pages.recruit.col.candidate'), render: (_: any, r: any) => personCell(r, t) },
              { title: t('pages.recruit.related.col.relation'), render: (_: any, r: any) => (
                  <Space size={[4, 4]} wrap>
                    {(r.relations || []).map((x: any, i: number) => (
                      <Tooltip key={i} title={[x.title, x.period].filter(Boolean).join(' · ') || undefined}>
                        <Tag color={x.overlap ? (x.kind === 'company' ? 'blue' : 'purple') : undefined}>
                          {t(`pages.recruit.related.${x.kind === 'company' ? 'sameCompany' : 'sameSchool'}${x.overlap ? 'Overlap' : ''}`)} · {x.org}
                        </Tag>
                      </Tooltip>))}
                  </Space>) },
              { title: t('pages.recruit.col.status'), width: 120, render: (_: any, r: any) => statusCell(r, t) },
            ]} />
        )}
      </Section>
    </>
  );
};

export default RelatedTab;
