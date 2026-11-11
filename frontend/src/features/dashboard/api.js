import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { queryKeys } from '../../api/queryKeys';

/** @typedef {'7d' | '30d' | 'all'} Period */

/**
 * @typedef {object} ProviderCredits
 * @property {string} provider
 * @property {string} credits decimal string, "520.00"
 */

/**
 * The manager dashboard (GET /api/analytics/dashboard). Totals and averages cover the tasks created
 * in the period and `ai` the rounds submitted in it; `workload` and `overdue` are always current.
 * Credits are decimal strings; durations are whole seconds, null when nothing qualifies.
 * @typedef {object} Dashboard
 * @property {{total: number, active: number, cancelled: number, byStatus: Record<'new' | 'in_progress' | 'under_review' | 'revisions_required' | 'completed' | 'cancelled', number>}} totals
 * @property {{count: number, tasks: import('../board/api').BoardTask[]}} overdue count is every
 *   overdue task, tasks at most 50 of them, earliest deadline first
 * @property {{cycleSeconds: number | null, queueWaitSeconds: number | null, reviewSecondsPerRound: number | null, revisionTurnaroundSeconds: number | null, completedCount: number}} averages
 * @property {{designerId: number, name: string, inProgress: number, revisionsRequired: number, underReview: number, overdue: number}[]} workload
 * @property {{total: string, byProvider: ProviderCredits[], byDesigner: {designerId: number, name: string, total: string, byProvider: ProviderCredits[]}[]}} ai
 */

/**
 * The dashboard of a period. While another period loads, the previous numbers stay (the page
 * marks them as refreshing).
 * @param {Period} [period] all time by default
 * @returns {import('@tanstack/react-query').UseQueryResult<Dashboard>}
 */
export function useDashboard(period = 'all') {
  return useQuery({
    queryKey: queryKeys.dashboard(period),
    queryFn: ({ signal }) =>
      apiFetch(`/api/analytics/dashboard?period=${encodeURIComponent(period)}`, { signal }),
    placeholderData: keepPreviousData,
  });
}
