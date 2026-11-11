import { makeTask } from '../../test/board';
import { sortAssigned } from './sortAssigned';

/** @param {import('../board/api').BoardTask[]} tasks */
const ids = (tasks) => tasks.map((task) => task.id);

const mine = { id: 3, displayName: 'Anna K.' };

/** @param {Partial<import('../board/api').BoardTask>} overrides */
const task = (overrides) => makeTask({ status: 'in_progress', assignee: mine, ...overrides });

describe('sortAssigned', () => {
  it('puts revisions_required first, then overdue, then the rest', () => {
    const sorted = sortAssigned([
      task({ id: 1, deadlineAt: '2099-01-01T00:00:00Z' }),
      task({ id: 2, isOverdue: true, deadlineAt: '2020-01-01T00:00:00Z' }),
      task({ id: 3, status: 'revisions_required', deadlineAt: '2099-06-01T00:00:00Z' }),
    ]);
    expect(ids(sorted)).toEqual([3, 2, 1]);
  });

  it('orders each group by deadline, nearest first', () => {
    const sorted = sortAssigned([
      task({ id: 1, status: 'revisions_required', deadlineAt: '2099-03-01T00:00:00Z' }),
      task({ id: 2, status: 'revisions_required', deadlineAt: '2099-02-01T00:00:00Z' }),
      task({ id: 3, isOverdue: true, deadlineAt: '2020-02-01T00:00:00Z' }),
      task({ id: 4, isOverdue: true, deadlineAt: '2020-01-01T00:00:00Z' }),
      task({ id: 5, deadlineAt: '2099-05-01T00:00:00Z' }),
      task({ id: 6, deadlineAt: '2099-04-01T00:00:00Z' }),
    ]);
    expect(ids(sorted)).toEqual([2, 1, 4, 3, 6, 5]);
  });

  it('puts an overdue revisions_required task with the revisions group', () => {
    const sorted = sortAssigned([
      task({ id: 1, isOverdue: true, deadlineAt: '2020-01-01T00:00:00Z' }),
      task({
        id: 2,
        status: 'revisions_required',
        isOverdue: true,
        deadlineAt: '2020-06-01T00:00:00Z',
      }),
    ]);
    expect(ids(sorted)).toEqual([2, 1]);
  });

  it('breaks a tie on the deadline by id', () => {
    const sorted = sortAssigned([
      task({ id: 9, deadlineAt: '2099-01-01T00:00:00Z' }),
      task({ id: 4, deadlineAt: '2099-01-01T00:00:00Z' }),
      task({ id: 7, deadlineAt: '2099-01-01T00:00:00Z' }),
    ]);
    expect(ids(sorted)).toEqual([4, 7, 9]);
  });

  it('compares deadlines as instants, not as text', () => {
    const sorted = sortAssigned([
      task({ id: 1, deadlineAt: '2099-01-01T12:00:00+02:00' }),
      task({ id: 2, deadlineAt: '2099-01-01T11:00:00Z' }),
    ]);
    expect(ids(sorted)).toEqual([1, 2]);
  });

  it('puts under_review after in_progress within the rest', () => {
    const sorted = sortAssigned([
      task({ id: 1, status: 'under_review', deadlineAt: '2099-01-01T00:00:00Z' }),
      task({ id: 2, deadlineAt: '2099-09-01T00:00:00Z' }),
      task({ id: 3, status: 'under_review', deadlineAt: '2099-02-01T00:00:00Z' }),
      task({ id: 4, deadlineAt: '2099-08-01T00:00:00Z' }),
    ]);
    expect(ids(sorted)).toEqual([4, 2, 1, 3]);
  });

  it('does not change its input and gives the same order for any input order', () => {
    const input = [
      task({ id: 1, deadlineAt: '2099-01-01T00:00:00Z' }),
      task({ id: 2, status: 'revisions_required' }),
      task({ id: 3, isOverdue: true, deadlineAt: '2020-01-01T00:00:00Z' }),
      task({ id: 4, status: 'under_review' }),
    ];
    const copy = [...input];
    const forward = ids(sortAssigned(input));
    expect(input).toEqual(copy);
    expect(ids(sortAssigned([...input].reverse()))).toEqual(forward);
  });

  it('returns an empty list for no tasks', () => {
    expect(sortAssigned([])).toEqual([]);
  });
});
