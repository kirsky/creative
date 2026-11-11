import { Modal } from '@mantine/core';
import { useTranslation } from 'react-i18next';

/**
 * The frame of every action dialog: a modal that traps the focus, closes with Escape, its X button
 * or the dismiss button, and stays put while a request is on its way (so the answer always has a
 * dialog to land in). A click on the backdrop does not close it, so entered text is not lost by a
 * slip. Mount it to open it, unmount it to close it.
 * @param {{
 *   title: string,
 *   pending: boolean,
 *   onClose: () => void,
 *   size?: string,
 *   children: import('react').ReactNode,
 * }} props
 */
export function ActionModal({ title, pending, onClose, size = 'md', children }) {
  const { t } = useTranslation('common');
  return (
    <Modal
      opened
      onClose={() => {
        if (!pending) {
          onClose();
        }
      }}
      title={title}
      size={size}
      closeOnEscape={!pending}
      closeOnClickOutside={false}
      closeButtonProps={{ 'aria-label': t('close'), disabled: pending }}
    >
      {children}
    </Modal>
  );
}
