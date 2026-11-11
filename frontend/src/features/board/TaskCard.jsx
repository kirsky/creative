import { Badge, Button, VisuallyHidden } from '@mantine/core';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import {
  formatCountry,
  formatCredits,
  formatDateTime,
  formatDeadline,
  formatDuration,
} from '../../i18n/format';

/** @typedef {import('../../i18n/detect').Language} Language */

/** Mantine colours of the priority badges: urgent and high stand out, the rest stays quiet. */
const PRIORITY_COLORS = /** @type {Record<string, string>} */ ({
  low: 'gray',
  normal: 'gray',
  high: 'yellow',
  urgent: 'red',
});

/** How many countries a card names before it says "+N". */
const GEO_SHOWN = 2;

/** A positive credit amount as the API writes it ("0.00" is none). */
function hasCredits(/** @type {string} */ credits) {
  return /[1-9]/.test(credits);
}

/**
 * How long ago the deadline passed, in seconds (0 for a deadline still ahead).
 * @param {string} deadlineAt
 */
function secondsLate(deadlineAt) {
  return Math.max(0, (Date.now() - Date.parse(deadlineAt)) / 1000);
}

/**
 * One task on the board, in a column or in the cancelled list. The offer is a link to the task
 * page and the whole card is its click target. Dragging is not the card's business: the board
 * passes a drag `handle` (and the wrapper that carries the pointer listeners) for the cards that
 * may be picked up; the Pick up button is the same action without dragging.
 * @param {object} props
 * @param {import('./api').BoardTask} props.task
 * @param {(task: import('./api').BoardTask) => void} [props.onPickUp] shows the Pick up button
 *   when the task allows pick_up
 * @param {boolean} [props.isPickingUp] this card's pick-up is in flight: spinner on the button
 * @param {boolean} [props.pickUpDisabled] another pick-up is in flight
 * @param {boolean} [props.showStatus] show the status as a badge (the cancelled list)
 * @param {import('react').ReactNode} [props.handle] the drag handle, shown at the card's top right
 * @param {boolean} [props.isOverlay] this is the copy that follows the pointer while dragging
 */
export function TaskCard({
  task,
  onPickUp,
  isPickingUp = false,
  pickUpDisabled = false,
  showStatus = false,
  handle,
  isOverlay = false,
}) {
  const { t, i18n } = useTranslation(['board', 'task', 'common']);
  const lng = /** @type {Language} */ (i18n.language);

  const geo = task.geo.slice(0, GEO_SHOWN).map((code) => formatCountry(code, lng, t));
  const moreGeo = task.geo.length - GEO_SHOWN;
  const creativeType = t(`task:creativeTypes.${task.creativeType}`, {
    defaultValue: task.creativeType,
  });
  const needsReview = task.status === 'under_review' && task.allowedActions.includes('approve');
  const canPickUp = onPickUp !== undefined && task.allowedActions.includes('pick_up');

  return (
    <article
      className="task-card"
      data-status={task.status}
      data-overdue={task.isOverdue ? 'true' : undefined}
      data-needs-review={needsReview ? 'true' : undefined}
      data-overlay={isOverlay ? 'true' : undefined}
      aria-busy={isPickingUp ? 'true' : undefined}
    >
      <div className="task-card__top">
        <h3 className="task-card__heading">
          <Link className="task-card__link" to={`/tasks/${task.id}`} draggable={false}>
            {task.offer}
          </Link>{' '}
          <span className="task-card__geo">
            {geo.join(', ')}
            {moreGeo > 0 ? ` +${moreGeo}` : ''}
          </span>
        </h3>
        {handle}
      </div>

      <p className="task-card__type">
        <span aria-hidden="true">
          {t('board:card.typeQuantity', { type: creativeType, quantity: task.quantity })}
        </span>
        <VisuallyHidden>
          {t('board:card.typeCreatives', {
            type: creativeType,
            creatives: t('common:creatives', { count: task.quantity }),
          })}
        </VisuallyHidden>
      </p>

      <div className="task-card__meta">
        <Badge size="sm" variant="light" color={PRIORITY_COLORS[task.priority] ?? 'gray'}>
          {t(`task:priorities.${task.priority}`, { defaultValue: task.priority })}
        </Badge>
        {showStatus && (
          <Badge size="sm" variant="outline" color="gray" data-testid="task-card-status">
            {t(`board:card.status.${task.status}`)}
          </Badge>
        )}
        {task.status !== 'completed' && (
          <span
            className="task-card__deadline"
            data-testid="task-card-deadline"
            data-overdue={task.isOverdue ? 'true' : undefined}
            title={formatDateTime(task.deadlineAt, lng)}
          >
            {task.isOverdue
              ? t('board:card.overdue', {
                  duration: formatDuration(secondsLate(task.deadlineAt), t),
                })
              : t('board:card.due', { date: formatDeadline(task.deadlineAt, lng) })}
          </span>
        )}
      </div>

      {(task.assignee !== null || task.roundCount > 0) && (
        <p className="task-card__work">
          {task.assignee !== null && (
            <span className="task-card__assignee" data-testid="task-card-assignee">
              {task.assignee.displayName}
            </span>
          )}
          {task.roundCount > 0 && (
            <span className="task-card__round">
              {t('board:card.round', { round: task.roundCount })}
              {hasCredits(task.creditsTotal) &&
                ` · ${t('board:card.credits', { credits: formatCredits(task.creditsTotal, lng) })}`}
            </span>
          )}
        </p>
      )}

      {task.isReturning && (
        <p className="task-card__note task-card__note--returning">{t('board:card.returning')}</p>
      )}
      {needsReview && (
        <p className="task-card__note task-card__note--review">{t('board:card.needsReview')}</p>
      )}

      {canPickUp && (
        <Button
          className="task-card__action"
          size="compact-sm"
          variant="light"
          loading={isPickingUp}
          disabled={pickUpDisabled}
          aria-label={t('board:card.pickUpOffer', { offer: task.offer })}
          onClick={() => onPickUp(task)}
        >
          {t('board:card.pickUp')}
        </Button>
      )}
    </article>
  );
}
