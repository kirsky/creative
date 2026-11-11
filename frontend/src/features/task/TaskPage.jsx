import { Alert, Anchor, Button, Skeleton, Title, VisuallyHidden } from '@mantine/core';
import { ArrowLeft, CalendarBlank } from '@phosphor-icons/react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { ApiRequestError } from '../../api/ApiRequestError';
import { Icon } from '../../components/Icon';
import { translateApiError } from '../../i18n/errors';
import { formatCountry, formatDeadline, formatDateTime, formatDuration } from '../../i18n/format';
import { useAuth } from '../auth/useAuth';
import { ActionBar } from './actions/ActionBar';
import { parseTaskId, useTask } from './api';
import { BriefPanel } from './BriefPanel';
import { HistoryPanel } from './HistoryPanel';
import { DeliverablesPanel, RoundsPanel } from './RoundsPanel';
import { SidePanel } from './SidePanel';
import './Task.scss';
import { PriorityBadge, StatusBadge } from './TaskBadges';
import { useRetry } from './useRetry';

/** @typedef {import('../../i18n/detect').Language} Language */

/**
 * The link back to the list the user came from: My work for a designer, the board for the rest.
 */
function BackLink() {
  const { t } = useTranslation('task');
  const { user } = useAuth();
  const isDesigner = user?.role === 'designer';
  return (
    <Anchor component={Link} to={isDesigner ? '/my-work' : '/board'} className="task-page__back">
      <Icon icon={ArrowLeft} size={16} />
      {isDesigner ? t('page.backToMyWork') : t('page.backToBoard')}
    </Anchor>
  );
}

/** The state of a task that does not exist or may not be viewed (and of an impossible id). */
function TaskNotFound() {
  const { t } = useTranslation('task');
  return (
    <div className="task-page task-page--message">
      <Title order={1}>{t('page.notFound.title')}</Title>
      <p>{t('page.notFound.text')}</p>
      <BackLink />
    </div>
  );
}

/** Placeholders in the shape of the page while the task loads. */
function TaskSkeleton() {
  const { t } = useTranslation('common');
  return (
    <div className="task-page" data-testid="task-skeleton" role="status">
      <VisuallyHidden>{t('loading')}</VisuallyHidden>
      <Skeleton height={32} width="60%" />
      <div className="task-page__columns">
        <div className="task-page__main">
          <Skeleton height={120} />
          <Skeleton height={80} />
          <Skeleton height={160} />
        </div>
        <Skeleton height={320} />
      </div>
    </div>
  );
}

/**
 * How long ago the deadline passed, in seconds (0 for a deadline still ahead).
 * @param {string} deadlineAt
 */
function secondsLate(deadlineAt) {
  return Math.max(0, (Date.now() - Date.parse(deadlineAt)) / 1000);
}

/**
 * The deadline of the header: when it is due, or how long ago it passed.
 * @param {{task: import('./api').TaskDetail}} props
 */
function Deadline({ task }) {
  const { t, i18n } = useTranslation(['task', 'common']);
  const lng = /** @type {Language} */ (i18n.language);
  return (
    <span
      className="task-page__deadline"
      data-testid="task-deadline"
      data-overdue={task.isOverdue ? 'true' : undefined}
      title={formatDateTime(task.deadlineAt, lng)}
    >
      <Icon icon={CalendarBlank} size={14} />
      {task.isOverdue
        ? t('task:page.overdue', { duration: formatDuration(secondsLate(task.deadlineAt), t) })
        : t('task:page.due', { date: formatDeadline(task.deadlineAt, lng) })}
    </span>
  );
}

/**
 * The task page, layout A: the work (brief, deliverables, rounds, actions) in the main column and
 * the facts in the side panel; the activity history is collapsed at the bottom. Read-only here:
 * the action buttons are the ActionBar's.
 * @param {{task: import('./api').TaskDetail}} props
 */
function TaskView({ task }) {
  const { t, i18n } = useTranslation(['task', 'common']);
  const lng = /** @type {Language} */ (i18n.language);
  const geo = task.geo.map((code) => formatCountry(code, lng, t)).join(', ');
  const creativeType = t(`task:creativeTypes.${task.creativeType}`, {
    defaultValue: task.creativeType,
  });
  return (
    <div className="task-page">
      <BackLink />
      <div className="task-page__header" data-testid="task-header">
        <Title order={1} className="task-page__title">
          {t('task:page.title', {
            offer: task.offer,
            geo,
            type: creativeType,
            quantity: task.quantity,
          })}
        </Title>
        <div className="task-page__badges">
          <StatusBadge status={task.status} />
          <PriorityBadge priority={task.priority} />
          {task.status !== 'completed' && <Deadline task={task} />}
        </div>
      </div>
      <div className="task-page__columns">
        <div className="task-page__main">
          <BriefPanel task={task} />
          <DeliverablesPanel rounds={task.rounds} />
          <RoundsPanel rounds={task.rounds} />
          <ActionBar task={task} />
        </div>
        <SidePanel task={task} />
      </div>
      <HistoryPanel taskId={task.id} />
    </div>
  );
}

/** The Task screen at /tasks/:id, for every signed-in role. */
export function TaskPage() {
  const { t } = useTranslation(['task', 'errors', 'common']);
  const { id } = useParams();
  const taskId = parseTaskId(id);
  const task = useTask(taskId);
  const failure = useRetry(task);

  if (taskId === null) {
    return <TaskNotFound />;
  }
  if (task.data !== undefined) {
    // Keyed by task: state of the page (open dialogs, their forms, the history) never carries over
    // to another task, even one whose data is cached and so shows at once.
    return <TaskView key={task.data.id} task={task.data} />;
  }
  if (failure.error !== null) {
    const { error } = failure;
    if (error instanceof ApiRequestError) {
      if (error.status === 404) {
        return <TaskNotFound />;
      }
      // A 401 is being handled (the user is on the way to the login page): no error screen for it.
      if (error.status === 401) {
        return <TaskSkeleton />;
      }
    }
    return (
      <div className="task-page task-page--message">
        <BackLink />
        <Alert color="red" title={t('task:page.error.title')}>
          <p className="task-page__error-text">
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
      </div>
    );
  }
  return <TaskSkeleton />;
}
