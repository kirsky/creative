import { notifications } from '@mantine/notifications';
import { useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiRequestError } from '../../api/ApiRequestError';
import { translateApiError } from '../../i18n/errors';
import { usePickUp } from './api';

/**
 * Picking a task up as the screens do it: one pick-up at a time, and a failure (somebody else
 * was faster, the task changed, no access) becomes a translated toast. The board refetches in
 * every case (usePickUp), so the cards show the server state. The board and My Work share it.
 * @returns {{pickUp: (task: {id: number}) => Promise<void>, pendingId: number | null}}
 *   pendingId is the task being picked up, null when none is
 */
export function usePickUpAction() {
  const { t } = useTranslation('errors');
  const { mutateAsync, isPending, variables } = usePickUp();

  const pickUp = useCallback(
    async (/** @type {{id: number}} */ task) => {
      if (isPending) {
        return;
      }
      try {
        await mutateAsync(task.id);
      } catch (error) {
        // A 401 has already sent the user to the login page.
        if (error instanceof ApiRequestError && error.code === 'unauthenticated') {
          return;
        }
        notifications.show({
          color: 'red',
          message: error instanceof ApiRequestError ? translateApiError(t, error) : t('unknown'),
        });
      }
    },
    [isPending, mutateAsync, t],
  );

  return { pickUp, pendingId: isPending ? (variables ?? null) : null };
}
