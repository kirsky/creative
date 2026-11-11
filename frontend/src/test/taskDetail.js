import { makeTask } from './board';
import { mockResponse } from './http';

/** @typedef {import('../features/task/api').TaskDetail} TaskDetail */
/** @typedef {import('../features/task/api').Round} Round */
/** @typedef {import('../features/task/api').TaskEvent} TaskEvent */

export const ANNA = { id: 3, displayName: 'Anna K.' };
export const DMITRY = { id: 1, displayName: 'Dmitry P.' };
export const IGOR = { id: 7, displayName: 'Igor S.' };

/**
 * One round as GET /api/tasks/{id} lists it; override what a test cares about.
 * @param {Partial<Round>} [overrides]
 * @returns {Round}
 */
export function makeRound(overrides = {}) {
  return {
    number: 1,
    submittedAt: '2026-11-10T09:00:00.000Z',
    submittedBy: ANNA,
    note: null,
    files: [{ id: 21, name: 'v1_hook.mp4', size: 2_500_000, mime: 'video/mp4' }],
    decision: null,
    decisionReason: null,
    decidedAt: null,
    aiUsage: [],
    ...overrides,
  };
}

/**
 * A task as GET /api/tasks/{id} returns it: the card plus the brief, assignments, rounds and metrics.
 * @param {Partial<TaskDetail>} [overrides]
 * @returns {TaskDetail}
 */
export function makeTaskDetail(overrides = {}) {
  return {
    ...makeTask({
      id: 42,
      offer: 'Casino Royal',
      geo: ['DE'],
      creativeType: 'video',
      quantity: 3,
      status: 'new',
      createdBy: DMITRY,
    }),
    description: 'Short hook.\nLogo in the corner.',
    references: [],
    assignments: [],
    rounds: [],
    metrics: {
      queueWaitSeconds: 11_400,
      cycleSeconds: null,
      reviewSeconds: 0,
      revisionTurnaroundSeconds: 0,
    },
    ...overrides,
  };
}

let nextEventId = 1;

/**
 * An event of GET /api/tasks/{id}/history.
 * @param {string} type
 * @param {Record<string, unknown>} [payload]
 * @param {Partial<TaskEvent>} [overrides]
 * @returns {TaskEvent}
 */
export function makeEvent(type, payload = {}, overrides = {}) {
  return {
    id: nextEventId++,
    type,
    actor: ANNA,
    at: '2026-11-10T09:00:00.000Z',
    payload,
    ...overrides,
  };
}

/** One event of each of the nine types, with a representative payload. */
export const EVENT_FIXTURES = /** @type {TaskEvent[]} */ ([
  makeEvent('task_created'),
  makeEvent('task_picked_up', { assignmentId: 5 }),
  makeEvent('task_released', { assignmentId: 5 }),
  makeEvent('round_submitted', { round: 2, fileCount: 3 }),
  makeEvent('revisions_requested', { round: 1, reason: 'Shorter hook' }),
  makeEvent('task_approved', { round: 2, note: 'Great' }),
  makeEvent('task_cancelled', { reason: 'Offer paused' }),
  makeEvent('brief_edited', { changes: [{ field: 'quantity', old: 3, new: 5 }] }),
  makeEvent('ai_usage_changed', {
    round: 2,
    lines: [
      { provider: 'Runway', credits: '120.00' },
      { provider: 'Midjourney', credits: '40.00' },
    ],
  }),
]).map((event, index) => ({ ...event, id: index + 1 }));

/** The answers of GET /api/tasks/{id} for the ids that tests of other screens navigate to. */
export const TASK_PAGE_TABLE = Object.fromEntries(
  [5, 9, 42].map((id) => [`GET /api/tasks/${id}`, mockResponse(200, makeTaskDetail({ id }))]),
);

/** The heading of the task page made by makeTaskDetail. */
export const TASK_PAGE_HEADING = { level: 1, name: /Casino Royal/ };
