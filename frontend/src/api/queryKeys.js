/** Every TanStack Query key of the app, so a key is spelled in one place only. */
export const queryKeys = {
  me: () => ['me'],
  options: () => ['options'],
  /** The root of every board query, for invalidating all of them. */
  boards: () => ['board'],
  /** @param {unknown} filters */
  board: (filters) => ['board', filters],
  /** @param {number | string} id */
  task: (id) => ['task', id],
  /** @param {number | string} id */
  taskHistory: (id) => ['task', id, 'history'],
  /** The root of every dashboard query, for invalidating all of them. */
  dashboards: () => ['dashboard'],
  /** @param {string} period */
  dashboard: (period) => ['dashboard', period],
  aiProviders: () => ['aiProviders'],
};
