import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderInProviders, paste } from '../../../test/actions';
import { ConfirmDialog } from './ConfirmDialog';

/** @param {Partial<Parameters<typeof ConfirmDialog>[0]>} [props] */
function renderConfirm(props = {}) {
  const onConfirm = jest.fn();
  const onClose = jest.fn();
  renderInProviders(
    <ConfirmDialog
      title="Cancel this task?"
      message="The task will be cancelled."
      confirmLabel="Cancel task"
      confirmColor="red"
      pending={false}
      onConfirm={onConfirm}
      onClose={onClose}
      {...props}
    />,
  );
  return { onConfirm, onClose };
}

const confirmButton = () => screen.getByRole('button', { name: 'Cancel task' });

describe('ConfirmDialog', () => {
  it('is a dialog with a title and a message', async () => {
    renderConfirm();
    const dialog = await screen.findByRole('dialog', { name: 'Cancel this task?' });
    expect(within(dialog).getByText('The task will be cancelled.')).toBeInTheDocument();
    expect(within(dialog).queryByRole('textbox')).not.toBeInTheDocument();
  });

  it('confirms without a text when it has no text field', async () => {
    const { onConfirm } = renderConfirm();
    await userEvent.click(await screen.findByRole('button', { name: 'Cancel task' }));
    expect(onConfirm).toHaveBeenCalledWith('');
  });

  it('hands over the trimmed text of the optional field', async () => {
    const { onConfirm } = renderConfirm({ textLabel: 'Reason (optional)' });
    await userEvent.type(await screen.findByLabelText('Reason (optional)'), '  Offer paused  ');
    await userEvent.click(confirmButton());
    expect(onConfirm).toHaveBeenCalledWith('Offer paused');
  });

  it('refuses a text of more than 2000 characters', async () => {
    const { onConfirm } = renderConfirm({ textLabel: 'Reason (optional)' });
    await paste(await screen.findByLabelText('Reason (optional)'), 'x'.repeat(2001));
    await userEvent.click(confirmButton());
    expect(onConfirm).not.toHaveBeenCalled();
    expect(screen.getByText('Must be at most 2000 characters')).toBeInTheDocument();
    expect(screen.getByLabelText('Reason (optional)')).toBeInvalid();
  });

  it('shows the error of the server under the text until it is edited', async () => {
    renderConfirm({ textLabel: 'Reason (optional)', fieldError: 'too_long:{"max":2000}' });
    const field = await screen.findByLabelText('Reason (optional)');
    expect(screen.getByText('Must be at most 2000 characters')).toBeInTheDocument();
    await userEvent.type(field, 'x');
    expect(screen.queryByText('Must be at most 2000 characters')).not.toBeInTheDocument();
  });

  it('shows a failure as an alert inside the dialog', async () => {
    renderConfirm({ error: 'Something went wrong on our side.' });
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByRole('alert')).toHaveTextContent(
      'Something went wrong on our side.',
    );
  });

  it('closes with Escape and with its dismiss button', async () => {
    const { onClose } = renderConfirm({ dismissLabel: 'Keep the task' });
    await screen.findByRole('dialog');
    await userEvent.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalledTimes(1);
    await userEvent.click(screen.getByRole('button', { name: 'Keep the task' }));
    expect(onClose).toHaveBeenCalledTimes(2);
  });

  it('does not close while pending and shows the confirm button as loading', async () => {
    const { onClose } = renderConfirm({ pending: true });
    await screen.findByRole('dialog');
    expect(confirmButton()).toBeDisabled();
    expect(confirmButton()).toHaveAttribute('data-loading', 'true');
    await userEvent.keyboard('{Escape}');
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
  });

  it('puts the focus inside and keeps it there while tabbing', async () => {
    renderConfirm({ textLabel: 'Reason (optional)' });
    const dialog = await screen.findByRole('dialog');
    await screen.findByLabelText('Reason (optional)');
    expect(dialog).toContainElement(/** @type {HTMLElement} */ (document.activeElement));
    for (let press = 0; press < 6; press += 1) {
      await userEvent.tab();
      expect(dialog).toContainElement(/** @type {HTMLElement} */ (document.activeElement));
    }
    await userEvent.tab({ shift: true });
    expect(dialog).toContainElement(/** @type {HTMLElement} */ (document.activeElement));
  });
});
