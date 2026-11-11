/** The five columns of the board, left to right. Cancelled is not a column. */
export const BOARD_COLUMNS = /** @type {const} */ ([
  'new',
  'in_progress',
  'under_review',
  'revisions_required',
  'completed',
]);

/** @typedef {typeof BOARD_COLUMNS[number]} BoardColumnId */

/** The only column a card can be dropped on. */
export const IN_PROGRESS_COLUMN = 'in_progress';

/**
 * A card can be dragged only where no further input is needed: a designer picking up a task.
 * Everything else (submit, approve, cancel) goes through the task page.
 * @param {import('./api').BoardTask} task
 */
export function canDrag(task) {
  return task.status !== 'cancelled' && task.allowedActions.includes('pick_up');
}

/**
 * What a drop means.
 * @typedef {{type: 'pickup', taskId: number} | {type: 'none'}} DropAction
 */

/**
 * @param {import('./api').BoardTask | undefined} task the card that was dropped
 * @param {unknown} overColumnId the id of what it was dropped on; null or undefined for nothing
 * @returns {DropAction}
 */
export function resolveDrop(task, overColumnId) {
  if (task !== undefined && canDrag(task) && overColumnId === IN_PROGRESS_COLUMN) {
    return { type: 'pickup', taskId: task.id };
  }
  return { type: 'none' };
}
