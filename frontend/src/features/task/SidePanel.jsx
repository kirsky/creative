import { Avatar, Paper } from '@mantine/core';
import {
  ArrowsClockwise,
  ArrowUUpLeft,
  CalendarBlank,
  Coins,
  Eye,
  Hourglass,
} from '@phosphor-icons/react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '../../components/Icon';
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
 * A labelled group of facts. The heading names the group for assistive technology.
 * @param {{title: string, children: import('react').ReactNode}} props
 */
function FactGroup({ title, children }) {
  const titleId = useId();
  return (
    <div role="group" aria-labelledby={titleId} className="task-side__group">
      <h3 id={titleId} className="task-side__subtitle">
        {title}
      </h3>
      {children}
    </div>
  );
}

/**
 * One person with a decorative avatar: the name next to it is what assistive technology reads.
 * @param {object} props
 * @param {string} props.label
 * @param {string} props.name
 * @param {boolean} [props.highlight] the gradient avatar of the person who holds the task
 */
function Person({ label, name, highlight = false }) {
  return (
    <div className="task-side__person">
      <Avatar
        className="task-side__avatar"
        size={30}
        name={name}
        aria-hidden="true"
        {...(highlight ? { variant: 'gradient' } : { variant: 'light', color: 'gray' })}
      />
      <dl className="task-side__facts">
        <dt>{label}</dt>
        <dd>{name}</dd>
      </dl>
    </div>
  );
}

/**
 * A small tile: an icon with a label, and a large value under it.
 * @param {{icon: import('../../components/Icon').IconComponent, label: string, value: string}} props
 */
function Tile({ icon, label, value }) {
  return (
    <div className="task-side__tile">
      <dt>
        <Icon icon={icon} size={14} />
        {label}
      </dt>
      <dd>{value}</dd>
    </div>
  );
}

/**
 * The facts of the task in three groups: the people, the schedule and the elapsed-time metrics,
 * with the AI credits as a tile of their own.
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

      <FactGroup title={t('side.groups.people')}>
        <div className="task-side__people">
          <Person label={t('side.createdBy')} name={task.createdBy.displayName} />
          {task.assignee === null ? (
            <div className="task-side__person">
              <dl className="task-side__facts">
                <dt>{t('side.assignedTo')}</dt>
                <dd>{t('side.unassigned')}</dd>
              </dl>
            </div>
          ) : (
            <Person label={t('side.assignedTo')} name={task.assignee.displayName} highlight />
          )}
        </div>
      </FactGroup>

      <FactGroup title={t('side.groups.schedule')}>
        <dl className="task-side__facts">
          <div>
            <dt>{t('side.stage')}</dt>
            <dd>
              <StatusBadge status={task.status} />
            </dd>
          </div>
          <div>
            <dt>{t('side.deadline')}</dt>
            <dd className="task-side__deadline" data-overdue={task.isOverdue ? 'true' : undefined}>
              <Icon icon={CalendarBlank} size={14} />
              {formatDateTime(task.deadlineAt, lng)}
            </dd>
          </div>
        </dl>
      </FactGroup>

      <FactGroup title={t('side.groups.time')}>
        <dl className="task-side__tiles">
          <Tile
            icon={Hourglass}
            label={t('side.queueWait')}
            value={formatDuration(metrics.queueWaitSeconds, t)}
          />
          <Tile
            icon={ArrowsClockwise}
            label={t('side.cycleTime')}
            value={formatDuration(metrics.cycleSeconds, t)}
          />
          <Tile
            icon={Eye}
            label={t('side.reviewTime')}
            value={formatDuration(metrics.reviewSeconds, t)}
          />
          <Tile
            icon={ArrowUUpLeft}
            label={t('side.revisionTurnaround')}
            value={formatDuration(metrics.revisionTurnaroundSeconds, t)}
          />
        </dl>
        <dl className="task-side__credits-list">
          <div className="task-side__credits" data-testid="facts-credits">
            <dt>
              <Icon icon={Coins} size={16} />
              {t('side.aiCredits')}
            </dt>
            <dd>{formatCredits(task.creditsTotal, lng)}</dd>
          </div>
        </dl>
        <p className="task-side__note">{t('side.elapsedNote')}</p>
      </FactGroup>

      <PreviousAssignees task={task} />
    </Paper>
  );
}
