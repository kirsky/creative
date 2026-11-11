import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router';
import { apiFetch } from '../../api/client';
import { queryKeys } from '../../api/queryKeys';

/**
 * GET /api/options: codes only, in the order to show them.
 * @typedef {object} Options
 * @property {string[]} creativeTypes
 * @property {string[]} priorities
 * @property {string[]} regions WW, EU, LATAM, MENA, CIS
 * @property {string[]} countries ISO 3166-1 alpha-2
 */

/**
 * A file as POST /api/files stores it.
 * @typedef {object} StoredFile
 * @property {number} id
 * @property {string} name
 * @property {string} mime
 * @property {number} size
 */

/**
 * The body of POST /api/tasks.
 * @typedef {object} NewTask
 * @property {string} offer
 * @property {string[]} geo
 * @property {string} creativeType
 * @property {number} quantity
 * @property {string} description
 * @property {string} priority
 * @property {string} deadline ISO 8601, UTC
 * @property {({type: 'url', url: string} | {type: 'file', fileId: number})[]} references
 */

/**
 * The options do not change while the app is open, so they are loaded once. A failure is not
 * retried automatically: the page offers a retry button.
 * @returns {import('@tanstack/react-query').UseQueryResult<Options>}
 */
export function useOptions() {
  return useQuery({
    queryKey: queryKeys.options(),
    queryFn: ({ signal }) => apiFetch('/api/options', { signal }),
    staleTime: Infinity,
    retry: false,
  });
}

/**
 * Creates a task. On success every board query is refreshed (the new task belongs on the board)
 * and the browser goes to the task page.
 * @returns {import('@tanstack/react-query').UseMutationResult<{id: number}, Error, NewTask>}
 */
export function useCreateTask() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  return useMutation({
    mutationFn: (/** @type {NewTask} */ task) =>
      apiFetch('/api/tasks', { method: 'POST', body: task }),
    onSuccess: (task) => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.boards() });
      navigate(`/tasks/${task.id}`);
    },
  });
}

/**
 * Uploads one file (one request per file, in the multipart field "file").
 * @param {File} file
 * @param {{signal?: AbortSignal}} [options]
 * @returns {Promise<StoredFile>}
 */
export function uploadFile(file, { signal } = {}) {
  const formData = new FormData();
  formData.append('file', file);
  return apiFetch('/api/files', { method: 'POST', formData, signal });
}
