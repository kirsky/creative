/** @typedef {import('../board/api').BoardTask} BoardTask */

/** Where a task falls: needs the designer first, then late work, then everything else. */
function group(/** @type {BoardTask} */ task) {
  if (task.status === 'revisions_required') {
    return 0;
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
 * The designer's own tasks in the order they should be worked on: revisions required first, then
 * overdue ones, then the rest; each group by deadline (nearest first), ties by id. Among the rest, a
 * task under review (waiting for the buyer) comes after those still in progress. Returns a new list.
 * @param {BoardTask[]} tasks
 * @returns {BoardTask[]}
 */
export function sortAssigned(tasks) {
  return [...tasks].sort((a, b) => {
    const byGroup = compare(group(a), group(b));
    if (byGroup !== 0) {
      return byGroup;
    }
    if (group(a) === 2) {
      const byReview = compare(
        a.status === 'under_review' ? 1 : 0,
        b.status === 'under_review' ? 1 : 0,
      );
      if (byReview !== 0) {
        return byReview;
      }
    }
    return compare(instant(a.deadlineAt), instant(b.deadlineAt)) || compare(a.id, b.id);
  });
}
