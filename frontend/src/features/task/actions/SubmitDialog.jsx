import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button, Group, Stack, Text, Textarea } from '@mantine/core';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { FileUploader } from '../../tasks/FileUploader';
import { failedFileCount, isUploading, uploadedFileIds } from '../../tasks/files';
import { translateIssue } from '../../tasks/schema';
import { ActionModal } from './ActionModal';
import { AiUsageEditor } from './AiUsageEditor';
import { useSubmitRound } from './api';
import { LIMITS, submitFormSchema, toApiLines } from './schemas';
import { mapSubmitError } from './serverErrors';
import { useActionFlow } from './useActionFlow';

/**
 * @typedef {object} SubmitFormValues
 * @property {string} note
 * @property {{provider: string, credits: string}[]} aiUsage
 */

/**
 * The designer hands in a round: at least one uploaded file (up to 25; each file uploads as soon
 * as it is picked), an optional note, and the AI usage (no lines means no AI was used).
 * @param {{taskId: number, onClose: () => void}} props
 */
export function SubmitDialog({ taskId, onClose }) {
  const { t } = useTranslation(['task', 'common', 'errors']);
  const submit = useSubmitRound(taskId);
  const flow = useActionFlow({ onClose, success: t('task:actions.submitDialog.success') });
  const [files, setFiles] = useState(/** @type {import('../../tasks/files').UploadItem[]} */ ([]));
  const [filesError, setFilesError] = useState(/** @type {string | null} */ (null));
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm({
    resolver: /** @type {any} */ (zodResolver(submitFormSchema)),
    defaultValues: /** @type {SubmitFormValues} */ ({ note: '', aiUsage: [] }),
  });

  const uploading = isUploading(files);
  const failedFiles = failedFileCount(files);

  /** @param {SubmitFormValues} values */
  function onSubmit(values) {
    const fileIds = uploadedFileIds(files);
    if (fileIds.length === 0) {
      setFilesError('required');
      return undefined;
    }
    setFilesError(null);
    return flow.run(
      () =>
        submit.mutateAsync({
          note: values.note.trim(),
          fileIds,
          aiUsage: toApiLines(values.aiUsage),
        }),
      {
        onInvalid: (error) => {
          const { fields, form } = mapSubmitError(error);
          fields.forEach(({ name, message }, index) => {
            if (name === 'files') {
              setFilesError(message);
            } else {
              setError(
                /** @type {any} */ (name),
                { type: 'server', message },
                { shouldFocus: index === 0 },
              );
            }
          });
          return form;
        },
      },
    );
  }

  /**
   * Nothing is sent while a file is still on its way (its id is not known yet).
   * @param {import('react').SyntheticEvent<HTMLFormElement>} event
   */
  function handleFormSubmit(event) {
    if (uploading) {
      event.preventDefault();
      return;
    }
    void handleSubmit(onSubmit)(event);
  }

  const noteMessage = errors.note?.message;
  return (
    <ActionModal
      title={t('task:actions.submitDialog.title')}
      pending={submit.isPending}
      onClose={onClose}
      size="lg"
    >
      <form onSubmit={handleFormSubmit} noValidate>
        <Stack gap="lg">
          {flow.error !== null && (
            <Alert color="red" variant="light" role="alert">
              {flow.error}
            </Alert>
          )}
          <Stack gap={4}>
            <FileUploader
              value={files}
              onChange={(update) => {
                setFilesError(null);
                setFiles(update);
              }}
              maxFiles={LIMITS.files}
            />
            {filesError === null ? (
              <Text size="xs" c="dimmed">
                {t('task:actions.submitDialog.filesHint')}
              </Text>
            ) : (
              <Text size="sm" c="red">
                {translateIssue(t, filesError)}
              </Text>
            )}
            {failedFiles > 0 && (
              <Text size="sm" c="dimmed">
                {t('task:create.references.failedNotice', { count: failedFiles })}
              </Text>
            )}
          </Stack>
          <Textarea
            label={t('task:actions.submitDialog.note')}
            autosize
            minRows={3}
            maxRows={8}
            error={noteMessage === undefined ? undefined : translateIssue(t, noteMessage)}
            {...register('note')}
          />
          <AiUsageEditor control={control} disabled={submit.isPending} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={submit.isPending}>
              {t('common:cancel')}
            </Button>
            <Button
              type="submit"
              variant="gradient"
              disabled={uploading || submit.isPending}
              loading={uploading || submit.isPending}
            >
              {t('task:actions.submitDialog.submit')}
            </Button>
          </Group>
        </Stack>
      </form>
    </ActionModal>
  );
}
