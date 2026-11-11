import { Alert, Button, SegmentedControl, Skeleton, SimpleGrid, Title } from '@mantine/core';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiRequestError } from '../../api/ApiRequestError';
import { translateApiError } from '../../i18n/errors';
import { useRetry } from '../task/useRetry';
import { AiCharts } from './AiCharts';
import { useDashboard } from './api';
import './Dashboard.scss';
import { OverdueList } from './OverdueList';
import { StatTiles } from './StatTiles';
import { StatusBar } from './StatusBar';
import { TimeBreakdown } from './TimeBreakdown';
import { WorkloadTable } from './WorkloadTable';

/** @typedef {import('./api').Period} Period */

const PERIODS = /** @type {Period[]} */ (['7d', '30d', 'all']);

/** Placeholders of the tiles and the sections while the first answer is on its way. */
function DashboardSkeleton() {
  const { t } = useTranslation('dashboard');
  return (
    <div
      className="dashboard__skeleton"
      data-testid="dashboard-skeleton"
      role="status"
      aria-label={t('loading')}
    >
      <SimpleGrid cols={{ base: 1, xs: 2, md: 3, lg: 5 }} spacing="xs">
        {Array.from({ length: 5 }, (_, index) => (
          <Skeleton key={index} height={92} radius="md" />
        ))}
      </SimpleGrid>
      <div className="dashboard__row">
        <Skeleton height={140} radius="md" />
        <Skeleton height={140} radius="md" />
      </div>
      <div className="dashboard__row">
        <Skeleton height={180} radius="md" />
        <Skeleton height={180} radius="md" />
      </div>
    </div>
  );
}

/**
 * The manager's read-only overview: figures, tasks by status, where the time goes, designer
 * workload, overdue tasks and the AI spend, for all time or the last 7 or 30 days.
 */
export function DashboardPage() {
  const { t } = useTranslation(['dashboard', 'errors', 'common']);
  const [period, setPeriod] = useState(/** @type {Period} */ ('all'));
  const query = useDashboard(period);
  const failure = useRetry(query);
  const data = query.data;

  const error = failure.error;
  // A 401 is being handled (the user is on the way to the login page): no error screen for it.
  const showError =
    data === undefined &&
    error !== null &&
    !(error instanceof ApiRequestError && error.status === 401);

  return (
    <div
      className="dashboard"
      data-testid="dashboard"
      data-refreshing={query.isPlaceholderData ? 'true' : undefined}
      aria-busy={query.isPlaceholderData ? 'true' : undefined}
    >
      <div className="dashboard__header">
        <Title order={1}>{t('dashboard:title')}</Title>
        <SegmentedControl
          aria-label={t('dashboard:period.label')}
          value={period}
          onChange={(value) => setPeriod(/** @type {Period} */ (value))}
          data={PERIODS.map((value) => ({ value, label: t(`dashboard:period.${value}`) }))}
        />
      </div>

      {showError ? (
        <Alert color="red" title={t('dashboard:error.title')}>
          <p className="dashboard__error-text">
            {error instanceof ApiRequestError ? translateApiError(t, error) : t('errors:unknown')}
          </p>
          <Button
            variant="light"
            color="red"
            size="xs"
            loading={failure.retrying}
            onClick={() => void failure.retry()}
          >
            {t('common:retry')}
          </Button>
        </Alert>
      ) : data === undefined ? (
        <DashboardSkeleton />
      ) : (
        <>
          <StatTiles data={data} />
          <div className="dashboard__row">
            <StatusBar totals={data.totals} />
            <TimeBreakdown averages={data.averages} />
          </div>
          <div className="dashboard__row">
            <WorkloadTable workload={data.workload} />
            <OverdueList overdue={data.overdue} />
          </div>
          <AiCharts ai={data.ai} />
        </>
      )}
    </div>
  );
}
