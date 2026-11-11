import { useCallback, useState } from 'react';

/**
 * Retry for a query that failed. TanStack Query clears the error of a query without data the moment
 * it asks again, so an error panel would vanish (and with it its Retry button) until the answer
 * arrives. This keeps the error that is being retried in view, so the button can show that it is
 * working instead of the panel flickering into a skeleton.
 * @param {Pick<import('@tanstack/react-query').UseQueryResult<unknown>, 'isError' | 'error' | 'refetch'>} query
 * @returns {{error: unknown, retrying: boolean, retry: () => Promise<void>}}
 *   error is null while the query has not failed; retrying is true from the click until the answer
 */
export function useRetry(query) {
  const [retrying, setRetrying] = useState(/** @type {{error: unknown} | null} */ (null));
  const { isError, error, refetch } = query;

  const retry = useCallback(async () => {
    setRetrying({ error });
    try {
      await refetch();
    } finally {
      setRetrying(null);
    }
  }, [error, refetch]);

  return {
    error: isError ? error : (retrying?.error ?? null),
    retrying: retrying !== null,
    retry,
  };
}
