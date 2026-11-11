import { mockResponse } from './http';
import { makeOptions } from './renderApp';

/** @typedef {import('../features/board/api').BoardTask} BoardTask */

/**
 * A task card as GET /api/tasks returns it; override what a test cares about.
 * @param {Partial<BoardTask>} [overrides]
 * @returns {BoardTask}
 */
export function makeTask(overrides = {}) {
  return {
    id: 1,
    offer: 'Casino Royal',
    geo: ['DE'],
    creativeType: 'video',
    quantity: 3,
    priority: 'normal',
    deadlineAt: '2099-01-01T12:00:00Z',
    status: 'new',
    createdBy: { id: 1, displayName: 'Alice Kovacs' },
    assignee: null,
    roundCount: 0,
    creditsTotal: '0.00',
    isOverdue: false,
    isReturning: false,
    allowedActions: [],
    ...overrides,
  };
}

/** The request path of the board: the filters, then includeCancelled=1 (always). */
export const BOARD_PATH = '/api/tasks?includeCancelled=1';

/**
 * @param {BoardTask[]} tasks
 * @returns {ReturnType<typeof mockResponse>}
 */
export function boardResponse(tasks) {
  return mockResponse(200, { tasks });
}

/** What the board asks for when it opens, answered with an empty board: for tests of other screens that pass through /board. */
export const EMPTY_BOARD_TABLE = {
  'GET /api/options': mockResponse(200, makeOptions()),
  [`GET ${BOARD_PATH}`]: boardResponse([]),
};
