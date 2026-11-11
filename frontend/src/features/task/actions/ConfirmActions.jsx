import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ConfirmDialog } from './ConfirmDialog';
import { useApprove, useCancel, useRelease } from './api';
import { mapTextError } from './serverErrors';
import { useActionFlow } from './useActionFlow';

/**
 * ConfirmDialog for a request whose text (note or reason) is optional and sent only when filled.
 * @param {{
 *   field: 'note' | 'reason',
 *   mutation: {mutateAsync: (body: any) => Promise<unknown>, isPending: boolean},
 *   onClose: () => void,
 *   success: string,
 *   dialog: Pick<Parameters<typeof ConfirmDialog>[0], 'title' | 'message' | 'confirmLabel' | 'confirmColor' | 'dismissLabel' | 'textLabel'>,
 * }} props
 */
function TextConfirm({ field, mutation, onClose, success, dialog }) {
  const flow = useActionFlow({ onClose, success });
  const [fieldError, setFieldError] = useState(/** @type {string | null} */ (null));
  return (
    <ConfirmDialog
      {...dialog}
      pending={mutation.isPending}
      error={flow.error}
      fieldError={fieldError}
      onClose={onClose}
      onConfirm={(text) => {
        setFieldError(null);
        void flow.run(() => mutation.mutateAsync(text === '' ? {} : { [field]: text }), {
          onInvalid: (error) => {
            const { fields, form } = mapTextError(error, field);
            setFieldError(fields[0]?.message ?? null);
            return form;
          },
        });
      }}
    />
  );
}

/**
 * The buyer accepts the work, with an optional note.
 * @param {{taskId: number, onClose: () => void}} props
 */
export function ApproveDialog({ taskId, onClose }) {
  const { t } = useTranslation('task');
  const approve = useApprove(taskId);
  return (
    <TextConfirm
      field="note"
      mutation={approve}
      onClose={onClose}
      success={t('actions.approveDialog.success')}
      dialog={{
        title: t('actions.approveDialog.title'),
        message: t('actions.approveDialog.message'),
        confirmLabel: t('actions.approveDialog.confirm'),
        textLabel: t('actions.optionalComment'),
      }}
    />
  );
}

/**
 * The buyer cancels the task, with an optional reason.
 * @param {{taskId: number, onClose: () => void}} props
 */
export function CancelDialog({ taskId, onClose }) {
  const { t } = useTranslation('task');
  const cancel = useCancel(taskId);
  return (
    <TextConfirm
      field="reason"
      mutation={cancel}
      onClose={onClose}
      success={t('actions.cancelDialog.success')}
      dialog={{
        title: t('actions.cancelDialog.title'),
        message: t('actions.cancelDialog.message'),
        confirmLabel: t('actions.cancelDialog.confirm'),
        confirmColor: 'red',
        dismissLabel: t('actions.cancelDialog.keep'),
        textLabel: t('actions.optionalReason'),
      }}
    />
  );
}

/**
 * The assigned designer gives the task back to the pool.
 * @param {{taskId: number, onClose: () => void}} props
 */
export function ReleaseDialog({ taskId, onClose }) {
  const { t } = useTranslation('task');
  const release = useRelease(taskId);
  const flow = useActionFlow({ onClose, success: t('actions.releaseDialog.success') });
  return (
    <ConfirmDialog
      title={t('actions.releaseDialog.title')}
      message={t('actions.releaseDialog.message')}
      confirmLabel={t('actions.releaseDialog.confirm')}
      pending={release.isPending}
      error={flow.error}
      onClose={onClose}
      onConfirm={() => void flow.run(() => release.mutateAsync())}
    />
  );
}
