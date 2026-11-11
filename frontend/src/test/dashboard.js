/** @typedef {import('../features/dashboard/api').Dashboard} Dashboard */

/** The "now" the tests freeze Date.now at: the fixture's overdue tasks are 2 d and 1 d 5 h late. */
export const NOW_MS = Date.parse('2026-11-11T12:00:00Z');

/** The path the dashboard asks for in a given period. */
export const dashboardPath = (/** @type {string} */ period) =>
  `/api/analytics/dashboard?period=${period}`;

/**
 * The answer of GET /api/analytics/dashboard with every section filled: 16 tasks, 2 overdue,
 * 3 designers and 3 AI providers (Runway, Midjourney, Kling). Override what a test cares about.
 * @param {Partial<Dashboard>} [overrides]
 * @returns {Dashboard}
 */
export function makeDashboard(overrides = {}) {
  return {
    totals: {
      total: 16,
      active: 7,
      cancelled: 2,
      byStatus: {
        new: 3,
        in_progress: 3,
        under_review: 2,
        revisions_required: 2,
        completed: 4,
        cancelled: 2,
      },
    },
    overdue: {
      count: 2,
      tasks: [
        {
          id: 11,
          offer: 'Мега Спин',
          geo: ['DE'],
          creativeType: 'video',
          quantity: 2,
          priority: 'high',
          deadlineAt: '2026-11-09T12:00:00Z',
          status: 'revisions_required',
          createdBy: { id: 1, displayName: 'Alice Kovacs' },
          assignee: { id: 7, displayName: 'Maria T.' },
          roundCount: 1,
          creditsTotal: '0.00',
          isOverdue: true,
          isReturning: false,
          allowedActions: [],
        },
        {
          id: 12,
          offer: 'Лото Клуб',
          geo: ['PL'],
          creativeType: 'carousel',
          quantity: 1,
          priority: 'normal',
          deadlineAt: '2026-11-10T06:30:00Z',
          status: 'new',
          createdBy: { id: 1, displayName: 'Alice Kovacs' },
          assignee: null,
          roundCount: 0,
          creditsTotal: '0.00',
          isOverdue: true,
          isReturning: false,
          allowedActions: [],
        },
      ],
    },
    averages: {
      cycleSeconds: 3 * 86400 + 5 * 3600,
      queueWaitSeconds: 5 * 3600 + 40 * 60,
      reviewSecondsPerRound: 7 * 3600 + 15 * 60,
      revisionTurnaroundSeconds: 86400 + 2 * 3600,
      completedCount: 4,
    },
    workload: [
      {
        designerId: 3,
        name: 'Anna K.',
        inProgress: 1,
        revisionsRequired: 1,
        underReview: 1,
        overdue: 1,
      },
      {
        designerId: 5,
        name: 'Igor S.',
        inProgress: 1,
        revisionsRequired: 0,
        underReview: 1,
        overdue: 0,
      },
      {
        designerId: 7,
        name: 'Maria T.',
        inProgress: 1,
        revisionsRequired: 1,
        underReview: 0,
        overdue: 1,
      },
    ],
    ai: {
      total: '1240.50',
      byProvider: [
        { provider: 'Runway', credits: '520.00' },
        { provider: 'Midjourney', credits: '330.50' },
        { provider: 'Kling', credits: '210.00' },
      ],
      byDesigner: [
        {
          designerId: 3,
          name: 'Anna K.',
          total: '610.00',
          byProvider: [
            { provider: 'Runway', credits: '350.00' },
            { provider: 'Kling', credits: '260.00' },
          ],
        },
        {
          designerId: 7,
          name: 'Maria T.',
          total: '630.50',
          byProvider: [
            { provider: 'Runway', credits: '170.00' },
            { provider: 'Midjourney', credits: '330.50' },
            { provider: 'Kling', credits: '130.00' },
          ],
        },
      ],
    },
    ...overrides,
  };
}

/** A team that has done nothing yet: zeros, null averages, empty lists. */
export function makeEmptyDashboard() {
  return makeDashboard({
    totals: {
      total: 0,
      active: 0,
      cancelled: 0,
      byStatus: {
        new: 0,
        in_progress: 0,
        under_review: 0,
        revisions_required: 0,
        completed: 0,
        cancelled: 0,
      },
    },
    overdue: { count: 0, tasks: [] },
    averages: {
      cycleSeconds: null,
      queueWaitSeconds: null,
      reviewSecondsPerRound: null,
      revisionTurnaroundSeconds: null,
      completedCount: 0,
    },
    workload: [],
    ai: { total: '0.00', byProvider: [], byDesigner: [] },
  });
}
