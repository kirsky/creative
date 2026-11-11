import { Paper } from '@mantine/core';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { formatCredits, formatDateTime, formatDuration } from '../../i18n/format';
import { StatusBadge } from './TaskBadges';

/** @typedef {import('../../i18n/detect').Language} Language */

/**
 * The people who held the task before, or no longer: every assignment that has ended. The one a
 * task in progress is held by right now is no earlier assignee, unless that designer released the
 * task before and took it again. A completed or cancelled task keeps its last assignee here.
 * @param {{task: import('./api').TaskDetail}} props
 */
function PreviousAssignees({ task }) {
  const { t, i18n } = useTranslation('task');
  const lng = /** @type {Language} */ (i18n.language);
  const headingId = useId();
  const isFinished = task.status === 'completed' || task.status === 'cancelled';
  const ended = task.assignments.filter(
    (assignment) =>
      assignment.endedAt !== null &&
      (isFinished ||
        assignment.designer.id !== task.assignee?.id ||
        assignment.endedHow === 'released'),
  );
  if (ended.length === 0) {
    return null;
  }
  return (
    <div className="task-side__assignments">
      <h3 id={headingId} className="task-side__subtitle">
        {t('side.previousAssignees')}
      </h3>
      <ul aria-labelledby={headingId} className="task-side__assignment-list">
        {ended.map((assignment) => (
          <li key={`${assignment.designer.id}-${assignment.assignedAt}`}>
            {assignment.endedHow === null
              ? assignment.designer.displayName
              : t('side.assignment', {
                  name: assignment.designer.displayName,
                  how: t(`side.endedHow.${assignment.endedHow}`, {
                    defaultValue: assignment.endedHow,
                  }),
                })}
            <span className="task-side__period">
              {t('side.assignmentPeriod', {
                from: formatDateTime(assignment.assignedAt, lng),
                to: formatDateTime(assignment.endedAt, lng),
              })}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * The facts of the task: stage, people, deadline, the elapsed-time metrics and the AI credits.
 * @param {{task: import('./api').TaskDetail}} props
 */
export function SidePanel({ task }) {
  const { t, i18n } = useTranslation('task');
  const lng = /** @type {Language} */ (i18n.language);
  const headingId = useId();
  const { metrics } = task;
  return (
    <Paper component="section" withBorder p="md" className="task-side" aria-labelledby={headingId}>
      <h2 id={headingId} className="task-section__title">
        {t('side.title')}
      </h2>
      <dl className="task-side__facts">
        <dt>{t('side.stage')}</dt>
        <dd>
          <StatusBadge status={task.status} />
        </dd>

        <dt>{t('side.createdBy')}</dt>
        <dd>{task.createdBy.displayName}</dd>

        <dt>{t('side.assignedTo')}</dt>
        <dd>{task.assignee === null ? t('side.unassigned') : task.assignee.displayName}</dd>

        <dt>{t('side.deadline')}</dt>
        <dd data-overdue={task.isOverdue ? 'true' : undefined}>
          {formatDateTime(task.deadlineAt, lng)}
        </dd>

        <dt>{t('side.queueWait')}</dt>
        <dd>{formatDuration(metrics.queueWaitSeconds, t)}</dd>

        <dt>{t('side.cycleTime')}</dt>
        <dd>{formatDuration(metrics.cycleSeconds, t)}</dd>

        <dt>{t('side.reviewTime')}</dt>
        <dd>{formatDuration(metrics.reviewSeconds, t)}</dd>

        <dt>{t('side.revisionTurnaround')}</dt>
        <dd>{formatDuration(metrics.revisionTurnaroundSeconds, t)}</dd>

        <dt>{t('side.aiCredits')}</dt>
        <dd>{formatCredits(task.creditsTotal, lng)}</dd>
      </dl>
      <p className="task-side__note">{t('side.elapsedNote')}</p>
      <PreviousAssignees task={task} />
    </Paper>
  );
}
