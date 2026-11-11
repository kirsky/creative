import { Alert, Button, Group, Stack, Text, Textarea } from '@mantine/core';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { translateIssue } from '../../tasks/schema';
import { ActionModal } from './ActionModal';
import { textProblem } from './schemas';

/**
 * A question with a confirm button, and optionally a free text (a note or a reason of at most 2000
 * characters). It only asks: the parent sends the request, shows `pending`, and hands back what
 * went wrong as `error` (an alert) or `fieldError` (an issue message for the text field).
 * @param {{
 *   title: string,
 *   message: string,
 *   confirmLabel: string,
 *   confirmColor?: string,
 *   dismissLabel?: string,
 *   textLabel?: string,
 *   pending: boolean,
 *   error?: string | null,
 *   fieldError?: string | null,
 *   onConfirm: (text: string) => void,
 *   onClose: () => void,
 * }} props `onConfirm` gets the trimmed text ('' when there is none)
 */
export function ConfirmDialog({
  title,
  message,
  confirmLabel,
  confirmColor,
  dismissLabel,
  textLabel,
  pending,
  error = null,
  fieldError = null,
  onConfirm,
  onClose,
}) {
  const { t } = useTranslation(['common', 'errors']);
  const [text, setText] = useState('');
  const [edited, setEdited] = useState(false);
  const [problem, setProblem] = useState(/** @type {string | null} */ (null));

  /** @param {import('react').SyntheticEvent<HTMLFormElement>} event */
  function handleSubmit(event) {
    event.preventDefault();
    if (pending) {
      return;
    }
    const found = textProblem(text);
    setProblem(found);
    // An error of the server belongs to the text sent now, so it shows until the text is edited.
    setEdited(false);
    if (found === null) {
      onConfirm(text.trim());
    }
  }

  const shown = problem ?? (edited ? null : fieldError);

  return (
    <ActionModal title={title} pending={pending} onClose={onClose}>
      <form onSubmit={handleSubmit} noValidate>
        <Stack gap="md">
          {error !== null && (
            <Alert color="red" variant="light" role="alert">
              {error}
            </Alert>
          )}
          <Text>{message}</Text>
          {textLabel !== undefined && (
            <Textarea
              data-autofocus
              label={textLabel}
              autosize
              minRows={3}
              maxRows={8}
              value={text}
              onChange={(event) => {
                setText(event.currentTarget.value);
                setEdited(true);
                setProblem(null);
              }}
              error={shown === null ? undefined : translateIssue(t, shown)}
            />
          )}
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              {dismissLabel ?? t('common:cancel')}
            </Button>
            <Button type="submit" color={confirmColor} loading={pending} disabled={pending}>
              {confirmLabel}
            </Button>
          </Group>
        </Stack>
      </form>
    </ActionModal>
  );
}
