/** @typedef {import('../tasks/api').Options} Options */

/**
 * What narrows the board; each key is left out when unused.
 * @typedef {object} BoardFilters
 * @property {string} [priority]
 * @property {string} [creativeType]
 * @property {number} [designerId]
 * @property {string} [q] a part of the offer
 */

/**
 * A change to the filters: the new value of each key, undefined (or a blank search) to remove it.
 * @typedef {{[K in keyof BoardFilters]: BoardFilters[K] | undefined}} FilterChange
 */

/** The longest search the API accepts. */
export const MAX_SEARCH_LENGTH = 100;

const DESIGNER_ID = /^[1-9]\d{0,8}$/;

/**
 * The filters in the URL. Anything the API would answer 422 to is ignored: a priority or creative
 * type that GET /api/options does not list, an id that is not a positive whole number, a blank or
 * over-long search. Without the options only designerId and q are read.
 * @param {URLSearchParams} params
 * @param {Options | undefined} options
 * @returns {BoardFilters}
 */
export function parseFilters(params, options) {
  /** @type {BoardFilters} */
  const filters = {};
  const priority = params.get('priority');
  if (priority !== null && options?.priorities.includes(priority)) {
    filters.priority = priority;
  }
  const creativeType = params.get('creativeType');
  if (creativeType !== null && options?.creativeTypes.includes(creativeType)) {
    filters.creativeType = creativeType;
  }
  const designerId = params.get('designerId');
  if (designerId !== null && DESIGNER_ID.test(designerId)) {
    filters.designerId = Number(designerId);
  }
  const q = params.get('q')?.trim() ?? '';
  if (q !== '' && q.length <= MAX_SEARCH_LENGTH) {
    filters.q = q;
  }
  return filters;
}

/**
 * The URL parameters after a filter change; other parameters stay. A key whose value is
 * undefined (or a blank search) is removed.
 * @param {URLSearchParams} current
 * @param {FilterChange} change
 * @returns {URLSearchParams}
 */
export function filtersToParams(current, change) {
  const next = new URLSearchParams(current);
  for (const [key, value] of Object.entries(change)) {
    const text =
      typeof value === 'string' ? value.trim() : value === undefined ? '' : String(value);
    if (text === '') {
      next.delete(key);
    } else {
      next.set(key, text);
    }
  }
  return next;
}

/** @param {BoardFilters} filters */
export function hasFilters(filters) {
  return Object.keys(filters).length > 0;
}

/**
 * GET /api/tasks for the board. The cancelled tasks are always requested, so the toggle can show
 * their number; it is the page that hides them. The filters come first, in a fixed order.
 * @param {BoardFilters} filters
 */
export function boardPath(filters) {
  const params = new URLSearchParams();
  if (filters.priority !== undefined) {
    params.set('priority', filters.priority);
  }
  if (filters.creativeType !== undefined) {
    params.set('creativeType', filters.creativeType);
  }
  if (filters.designerId !== undefined) {
    params.set('designerId', String(filters.designerId));
  }
  if (filters.q !== undefined) {
    params.set('q', filters.q);
  }
  params.set('includeCancelled', '1');
  return `/api/tasks?${params.toString()}`;
}
