import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button, Group, Stack, Textarea } from '@mantine/core';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { translateIssue } from '../../tasks/schema';
import { ActionModal } from './ActionModal';
import { useRequestRevisions } from './api';
import { revisionsFormSchema } from './schemas';
import { mapTextError } from './serverErrors';
import { useActionFlow } from './useActionFlow';

/**
 * The buyer sends the work back: the reason is required (up to 2000 characters).
 * @param {{taskId: number, onClose: () => void}} props
 */
export function RevisionsDialog({ taskId, onClose }) {
  const { t } = useTranslation(['task', 'common', 'errors']);
  const revisions = useRequestRevisions(taskId);
  const flow = useActionFlow({ onClose, success: t('task:actions.revisionsDialog.success') });
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm({
    resolver: /** @type {any} */ (zodResolver(revisionsFormSchema)),
    defaultValues: { reason: '' },
  });

  /** @param {{reason: string}} values */
  function onSubmit(values) {
    return flow.run(() => revisions.mutateAsync({ reason: values.reason.trim() }), {
      onInvalid: (error) => {
        const { fields, form } = mapTextError(error, 'reason');
        for (const { message } of fields) {
          setError('reason', { type: 'server', message }, { shouldFocus: true });
        }
        return form;
      },
    });
  }

  const message = errors.reason?.message;
  return (
    <ActionModal
      title={t('task:actions.revisionsDialog.title')}
      pending={revisions.isPending}
      onClose={onClose}
    >
      <form onSubmit={(event) => void handleSubmit(onSubmit)(event)} noValidate>
        <Stack gap="md">
          {flow.error !== null && (
            <Alert color="red" variant="light" role="alert">
              {flow.error}
            </Alert>
          )}
          <Textarea
            data-autofocus
            required
            withAsterisk
            label={t('task:actions.revisionsDialog.reason')}
            autosize
            minRows={4}
            maxRows={10}
            error={message === undefined ? undefined : translateIssue(t, message)}
            {...register('reason')}
          />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={revisions.isPending}>
              {t('common:cancel')}
            </Button>
            <Button
              type="submit"
              variant="gradient"
              loading={revisions.isPending}
              disabled={revisions.isPending}
            >
              {t('task:actions.revisionsDialog.submit')}
            </Button>
          </Group>
        </Stack>
      </form>
    </ActionModal>
  );
}
