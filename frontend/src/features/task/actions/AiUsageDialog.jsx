import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button, Group, Stack } from '@mantine/core';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { ActionModal } from './ActionModal';
import { AiUsageEditor } from './AiUsageEditor';
import { useReplaceAiUsage } from './api';
import { aiUsageFormSchema, toApiLines } from './schemas';
import { mapAiUsageError } from './serverErrors';
import { useActionFlow } from './useActionFlow';

/**
 * The designer corrects the AI usage of a round the buyer has not decided yet. Saving replaces
 * the whole set (an unchanged set is fine; no lines clears it).
 * @param {{
 *   taskId: number,
 *   round: number,
 *   lines: import('../api').AiUsageLine[],
 *   onClose: () => void,
 * }} props
 */
export function AiUsageDialog({ taskId, round, lines, onClose }) {
  const { t } = useTranslation(['task', 'common', 'errors']);
  const replace = useReplaceAiUsage(taskId);
  const flow = useActionFlow({ onClose, success: t('task:actions.aiUsage.success') });
  const { control, handleSubmit, setError } = useForm({
    resolver: /** @type {any} */ (zodResolver(aiUsageFormSchema)),
    defaultValues: {
      aiUsage: lines.map(({ provider, credits }) => ({ provider, credits })),
    },
  });

  /** @param {import('./schemas').AiUsageFormValues} values */
  function onSubmit(values) {
    return flow.run(() => replace.mutateAsync({ round, lines: toApiLines(values.aiUsage) }), {
      onInvalid: (error) => {
        const { fields, form } = mapAiUsageError(error);
        fields.forEach(({ name, message }, index) => {
          setError(
            /** @type {any} */ (name),
            { type: 'server', message },
            { shouldFocus: index === 0 },
          );
        });
        return form;
      },
    });
  }

  return (
    <ActionModal
      title={t('task:actions.aiUsage.dialogTitle')}
      pending={replace.isPending}
      onClose={onClose}
      size="lg"
    >
      <form onSubmit={(event) => void handleSubmit(onSubmit)(event)} noValidate>
        <Stack gap="md">
          {flow.error !== null && (
            <Alert color="red" variant="light" role="alert">
              {flow.error}
            </Alert>
          )}
          <AiUsageEditor control={control} disabled={replace.isPending} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={replace.isPending}>
              {t('common:cancel')}
            </Button>
            <Button type="submit" loading={replace.isPending} disabled={replace.isPending}>
              {t('task:actions.aiUsage.save')}
            </Button>
          </Group>
        </Stack>
      </form>
    </ActionModal>
  );
}
