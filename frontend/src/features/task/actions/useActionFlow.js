import { notifications } from '@mantine/notifications';
import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiRequestError } from '../../../api/ApiRequestError';
import { translateApiError, translateFieldError } from '../../../i18n/errors';
import { IN_FLIGHT } from './api';

/**
 * The answers that mean the task is not what the user saw: the dialog has nothing left to do.
 * The screen refetches the task and shows the real state.
 */
const STALE_CODES = new Set(['invalid_transition', 'already_taken', 'forbidden', 'not_found']);

/**
 * What every action dialog does with the answer of its request.
 *  - success: a toast, then the dialog closes;
 *  - a task that is no longer as the user saw it (409, 403, 404): a translated toast and the
 *    dialog closes, because the refetched task shows what is possible now;
 *  - 422: the caller puts the field problems on its inputs (`onInvalid`; it may hand back the ones
 *    that belong to no input, which become the alert);
 *  - anything else (network, 5xx): an alert inside the dialog, which stays open with its data.
 * @param {{onClose: () => void, success: string}} options `success` is the text of the toast
 * @returns {{
 *   error: string | null,
 *   run: <T>(
 *     request: () => Promise<T | typeof IN_FLIGHT>,
 *     options?: {onInvalid?: (error: ApiRequestError) => import('../../../api/ApiRequestError').FieldError[]},
 *   ) => Promise<void>,
 *   clearError: () => void,
 * }}
 */
export function useActionFlow({ onClose, success }) {
  const { t } = useTranslation(['task', 'errors']);
  const [error, setError] = useState(/** @type {string | null} */ (null));

  const run = useCallback(
    /**
     * @param {() => Promise<unknown>} request
     * @param {{onInvalid?: (error: ApiRequestError) => import('../../../api/ApiRequestError').FieldError[]}} [options]
     */
    async (request, { onInvalid } = {}) => {
      setError(null);
      try {
        if ((await request()) === IN_FLIGHT) {
          return;
        }
      } catch (caught) {
        if (!(caught instanceof ApiRequestError)) {
          setError(t('errors:unknown'));
          return;
        }
        // A 401 has already sent the user to the login page.
        if (caught.code === 'unauthenticated') {
          return;
        }
        if (STALE_CODES.has(caught.code)) {
          notifications.show({ color: 'red', message: translateApiError(t, caught) });
          onClose();
          return;
        }
        if (caught.code === 'validation_failed' && caught.fields.length > 0 && onInvalid) {
          const unplaced = onInvalid(caught);
          if (unplaced.length === 0) {
            return;
          }
          setError([...new Set(unplaced.map((item) => translateFieldError(t, item)))].join(' '));
          return;
        }
        setError(translateApiError(t, caught));
        return;
      }
      notifications.show({ color: 'teal', message: success });
      onClose();
    },
    [onClose, success, t],
  );

  return { error, run, clearError: () => setError(null) };
}
