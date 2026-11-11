import { makeTask } from '../../test/board';
import { BOARD_COLUMNS, canDrag, IN_PROGRESS_COLUMN, resolveDrop } from './dragDrop';

const poolTask = makeTask({ id: 5, allowedActions: ['pick_up'] });
const heldTask = makeTask({ id: 6, status: 'in_progress', allowedActions: ['submit', 'release'] });

describe('canDrag', () => {
  it('is true only for a task the user may pick up', () => {
    expect(canDrag(poolTask)).toBe(true);
    expect(canDrag(heldTask)).toBe(false);
    expect(canDrag(makeTask({ allowedActions: [] }))).toBe(false);
  });
});

describe('resolveDrop', () => {
  it('picks the task up when it is dropped on the in_progress column', () => {
    expect(resolveDrop(poolTask, 'in_progress')).toEqual({ type: 'pickup', taskId: 5 });
    expect(IN_PROGRESS_COLUMN).toBe('in_progress');
  });

  it.each(BOARD_COLUMNS.filter((column) => column !== 'in_progress'))(
    'does nothing when the task is dropped on %s',
    (column) => {
      expect(resolveDrop(poolTask, column)).toEqual({ type: 'none' });
    },
  );

  it('does nothing when the drop is over nothing', () => {
    expect(resolveDrop(poolTask, null)).toEqual({ type: 'none' });
    expect(resolveDrop(poolTask, undefined)).toEqual({ type: 'none' });
  });

  it('does nothing for a drop on something that is not a column', () => {
    expect(resolveDrop(poolTask, 'cancelled')).toEqual({ type: 'none' });
    expect(resolveDrop(poolTask, 42)).toEqual({ type: 'none' });
  });

  it('does nothing for a task without pick_up, even over in_progress', () => {
    expect(resolveDrop(heldTask, 'in_progress')).toEqual({ type: 'none' });
  });

  it('does nothing when there is no task', () => {
    expect(resolveDrop(undefined, 'in_progress')).toEqual({ type: 'none' });
  });
});

describe('BOARD_COLUMNS', () => {
  it('has the five columns in order and no cancelled column', () => {
    expect(BOARD_COLUMNS).toEqual([
      'new',
      'in_progress',
      'under_review',
      'revisions_required',
      'completed',
    ]);
  });
});
