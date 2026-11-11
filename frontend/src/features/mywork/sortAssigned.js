/** @typedef {import('../board/api').BoardTask} BoardTask */

/**
 * Where a task falls: work the buyer sent back first, then the designer's late work in progress,
 * then the rest of the work in progress, then what waits for the buyer. Only a task in progress
 * counts as late work: an overdue task under review is the buyer's to act on.
 */
function group(/** @type {BoardTask} */ task) {
  if (task.status === 'revisions_required') {
    return 0;
  }
  if (task.status === 'under_review') {
    return 3;
  }
  return task.isOverdue ? 1 : 2;
}

/** The deadline as an instant; an unreadable one sorts last. */
function instant(/** @type {string} */ deadlineAt) {
  const time = Date.parse(deadlineAt);
  return Number.isNaN(time) ? Number.POSITIVE_INFINITY : time;
}

/**
 * Compare two instants without Infinity - Infinity being NaN.
 * @param {number} a
 * @param {number} b
 */
function compare(a, b) {
  return a === b ? 0 : a < b ? -1 : 1;
}

/**
 * The designer's own tasks in the order they should be worked on: revisions required, overdue
 * tasks in progress, the other tasks in progress, then tasks under review; each group by deadline
 * (nearest first), ties by id. Returns a new list.
 * @param {BoardTask[]} tasks
 * @returns {BoardTask[]}
 */
export function sortAssigned(tasks) {
  return [...tasks].sort(
    (a, b) =>
      compare(group(a), group(b)) ||
      compare(instant(a.deadlineAt), instant(b.deadlineAt)) ||
      compare(a.id, b.id),
  );
}
