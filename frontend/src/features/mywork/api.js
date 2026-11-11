import { useMemo } from 'react';
import { useBoard } from '../board/api';
import { sortAssigned } from './sortAssigned';

/** What the designer is working on: waiting for them, in their hands, or with the buyer. */
export const ASSIGNED_STATUSES = ['in_progress', 'revisions_required', 'under_review'];

/**
 * Splits the board into what My work shows. Assigned: the user's own tasks that are in progress,
 * need revisions or wait for review (completed and cancelled ones are history), in working order.
 * Available: the `new` pool, in the order the API sent.
 * @param {import('../board/api').BoardTask[]} tasks
 * @param {number} userId
 */
export function selectMyWork(tasks, userId) {
  const assigned = sortAssigned(
    tasks.filter((task) => task.assignee?.id === userId && ASSIGNED_STATUSES.includes(task.status)),
  );
  const available = tasks.filter((task) => task.status === 'new');
  return {
    assigned,
    available,
    counts: {
      in_progress: assigned.filter((task) => task.status === 'in_progress').length,
      revisions_required: assigned.filter((task) => task.status === 'revisions_required').length,
      available: available.length,
    },
  };
}

/**
 * The My work data: the unfiltered board (the same query as the board page, so the cache is shared
 * and every pick-up refreshes both), split for the page. `work` is undefined until the board is there.
 * @param {number} userId
 * @returns {{query: import('@tanstack/react-query').UseQueryResult<import('../board/api').BoardData>, work: ReturnType<typeof selectMyWork> | undefined}}
 */
export function useMyWork(userId) {
  const query = useBoard({});
  const tasks = query.data?.tasks;
  const work = useMemo(
    () => (tasks === undefined ? undefined : selectMyWork(tasks, userId)),
    [tasks, userId],
  );
  return { query, work };
}
