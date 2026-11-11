import { MantineProvider } from '@mantine/core';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import i18n from '../../i18n';
import { makeTask } from '../../test/board';
import { TaskCard } from './TaskCard';

/**
 * @param {import('./api').BoardTask} task
 * @param {Partial<Parameters<typeof TaskCard>[0]>} [props]
 */
function renderCard(task, props = {}) {
  return render(
    <MantineProvider>
      <MemoryRouter>
        <TaskCard task={task} {...props} />
      </MemoryRouter>
    </MantineProvider>,
  );
}

const card = () => screen.getByRole('article');

describe('TaskCard content', () => {
  it('shows the offer, the GEO, the type with its quantity and the priority', () => {
    renderCard(makeTask({ offer: 'Casino Royal', geo: ['DE'], priority: 'high' }));
    expect(screen.getByText('Casino Royal')).toBeInTheDocument();
    expect(within(card()).getByText(/Germany/)).toBeInTheDocument();
    expect(within(card()).getByText('Video ×3')).toBeInTheDocument();
    expect(within(card()).getByText('High')).toBeInTheDocument();
  });

  it('names the first two GEOs and counts the rest', () => {
    renderCard(makeTask({ geo: ['DE', 'FR', 'US', 'BR'] }));
    expect(card()).toHaveTextContent('Germany, France +2');
  });

  it('writes a region by its name', () => {
    renderCard(makeTask({ geo: ['EU'] }));
    expect(card()).toHaveTextContent('Europe');
  });

  it.each([
    [1, /1 creative(?!s)/],
    [2, /2 creatives/],
  ])('reads the quantity %i as a plural-aware count for assistive technology', (quantity, text) => {
    renderCard(makeTask({ quantity }));
    expect(card()).toHaveTextContent(text);
  });

  it('says 2 creatives in Russian as «креатива» and 5 as «креативов»', async () => {
    await i18n.changeLanguage('ru');
    const { unmount } = renderCard(makeTask({ quantity: 2, creativeType: 'video' }));
    expect(card()).toHaveTextContent('2 креатива');
    expect(within(card()).getByText('Видео ×2')).toBeInTheDocument();
    unmount();
    renderCard(makeTask({ quantity: 5 }));
    expect(card()).toHaveTextContent('5 креативов');
  });

  it('shows the formatted deadline', () => {
    renderCard(makeTask({ deadlineAt: '2099-07-15T10:30:00Z' }));
    const deadline = within(card()).getByTestId('task-card-deadline');
    expect(deadline).toHaveTextContent('2099');
    expect(deadline).toHaveTextContent('12:30');
    expect(deadline).not.toHaveAttribute('data-overdue');
  });

  it('shows the assignee when there is one', () => {
    renderCard(makeTask({ status: 'in_progress', assignee: { id: 3, displayName: 'Anna K.' } }));
    expect(card()).toHaveTextContent('Anna K.');
  });

  it('shows no assignee for a task nobody has', () => {
    renderCard(makeTask());
    expect(screen.queryByTestId('task-card-assignee')).not.toBeInTheDocument();
  });

  it('shows the round and the credits once work has started', () => {
    renderCard(
      makeTask({
        status: 'under_review',
        assignee: { id: 3, displayName: 'Anna K.' },
        roundCount: 2,
        creditsTotal: '220.00',
      }),
    );
    expect(card()).toHaveTextContent('round 2 · 220.00 cr');
  });

  it('hides the credits when there are none', () => {
    renderCard(
      makeTask({
        status: 'in_progress',
        assignee: { id: 3, displayName: 'Anna K.' },
        roundCount: 1,
        creditsTotal: '0.00',
      }),
    );
    expect(card()).toHaveTextContent('round 1');
    expect(card()).not.toHaveTextContent(/\bcr\b/);
  });

  it('shows no round before work has started', () => {
    renderCard(makeTask({ roundCount: 0 }));
    expect(card()).not.toHaveTextContent(/round/);
  });

  it('keeps the text of the offer as text, never as markup', () => {
    renderCard(makeTask({ offer: '🎰 <script>alert(1)</script> Лото' }));
    expect(screen.getByText('🎰 <script>alert(1)</script> Лото')).toBeInTheDocument();
    expect(card().querySelector('script')).toBeNull();
  });
});

describe('TaskCard states', () => {
  it('marks an overdue deadline and says how late it is', () => {
    renderCard(makeTask({ isOverdue: true, deadlineAt: '2020-01-01T00:00:00Z' }));
    const deadline = within(card()).getByTestId('task-card-deadline');
    expect(deadline).toHaveAttribute('data-overdue', 'true');
    expect(deadline).toHaveTextContent(/overdue/i);
    expect(card()).toHaveAttribute('data-overdue', 'true');
  });

  it('does not mark a deadline that is not overdue', () => {
    renderCard(makeTask({ isOverdue: false }));
    expect(card()).not.toHaveAttribute('data-overdue');
  });

  it('shows the revision-pending marker for a returning task', () => {
    renderCard(makeTask({ isReturning: true }));
    expect(card()).toHaveTextContent('Revision pending (released)');
  });

  it('shows the revision-pending marker with a decorative icon next to the text', () => {
    renderCard(makeTask({ isReturning: true }));
    const note = screen.getByText('Revision pending (released)');
    expect(note.querySelector('svg[aria-hidden="true"]')).not.toBeNull();
    expect(note).toHaveTextContent(/^Revision pending \(released\)$/);
  });

  it('gives the deadline and the assignee decorative icons', () => {
    renderCard(makeTask({ assignee: { id: 5, displayName: 'Anna K.' } }));
    expect(
      screen.getByTestId('task-card-deadline').querySelector('svg[aria-hidden="true"]'),
    ).not.toBeNull();
    expect(
      screen.getByTestId('task-card-assignee').querySelector('svg[aria-hidden="true"]'),
    ).not.toBeNull();
  });

  it('has no such marker otherwise', () => {
    renderCard(makeTask());
    expect(card()).not.toHaveTextContent('Revision pending');
  });

  it('asks for the buyer review when the task is under review and may be approved', () => {
    renderCard(
      makeTask({
        status: 'under_review',
        assignee: { id: 3, displayName: 'Anna K.' },
        allowedActions: ['approve', 'request_revisions', 'cancel'],
      }),
    );
    expect(card()).toHaveTextContent('Needs your review');
    expect(card()).toHaveAttribute('data-needs-review', 'true');
  });

  it('does not ask for a review of a task the user cannot approve', () => {
    renderCard(
      makeTask({
        status: 'under_review',
        assignee: { id: 3, displayName: 'Anna K.' },
        allowedActions: [],
      }),
    );
    expect(card()).not.toHaveTextContent('Needs your review');
  });

  it('shows the status only when asked to, for the cancelled list', () => {
    const { unmount } = renderCard(makeTask({ status: 'cancelled' }));
    expect(card()).not.toHaveTextContent('Cancelled');
    unmount();
    renderCard(makeTask({ status: 'cancelled' }), { showStatus: true });
    expect(card()).toHaveTextContent('Cancelled');
  });
});

describe('TaskCard link', () => {
  it('is a link to the task page, named by the offer', () => {
    renderCard(makeTask({ id: 42, offer: 'Vulkan Bet' }));
    expect(screen.getByRole('link', { name: 'Vulkan Bet' })).toHaveAttribute('href', '/tasks/42');
  });
});

describe('TaskCard pick up', () => {
  it('has a Pick up button only when pick_up is allowed', () => {
    const { unmount } = renderCard(makeTask({ allowedActions: [] }), { onPickUp: jest.fn() });
    expect(screen.queryByRole('button', { name: /pick up/i })).not.toBeInTheDocument();
    unmount();
    renderCard(makeTask({ allowedActions: ['pick_up'] }), { onPickUp: jest.fn() });
    expect(screen.getByRole('button', { name: 'Pick up Casino Royal' })).toBeInTheDocument();
  });

  it('calls onPickUp with the task', async () => {
    const onPickUp = jest.fn();
    const task = makeTask({ allowedActions: ['pick_up'] });
    renderCard(task, { onPickUp });
    await userEvent.click(screen.getByRole('button', { name: /pick up/i }));
    expect(onPickUp).toHaveBeenCalledWith(task);
  });

  it('has no button when it is given no handler', () => {
    renderCard(makeTask({ allowedActions: ['pick_up'] }));
    expect(screen.queryByRole('button', { name: /pick up/i })).not.toBeInTheDocument();
  });

  it('shows a spinner and cannot be clicked while the pick-up is pending', async () => {
    const onPickUp = jest.fn();
    renderCard(makeTask({ allowedActions: ['pick_up'] }), { onPickUp, isPickingUp: true });
    expect(card()).toHaveAttribute('aria-busy', 'true');
    const button = screen.getByRole('button', { name: /pick up/i });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('data-loading', 'true');
    await userEvent.click(button);
    expect(onPickUp).not.toHaveBeenCalled();
  });

  it('can be disabled while another card is being picked up', () => {
    renderCard(makeTask({ allowedActions: ['pick_up'] }), {
      onPickUp: jest.fn(),
      pickUpDisabled: true,
    });
    expect(screen.getByRole('button', { name: /pick up/i })).toBeDisabled();
  });

  it('says it in Russian', async () => {
    await i18n.changeLanguage('ru');
    renderCard(makeTask({ allowedActions: ['pick_up'] }), { onPickUp: jest.fn() });
    expect(screen.getByRole('button', { name: /Взять в работу/ })).toBeInTheDocument();
  });
});
