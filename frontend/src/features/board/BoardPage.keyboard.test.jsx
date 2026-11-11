import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../api/client';
import { BOARD_PATH, boardResponse, makeTask } from '../../test/board';
import { callsTo } from '../../test/createTaskForm';
import { makeMe, mockResponse } from '../../test/http';
import { makeOptions, renderApp } from '../../test/renderApp';
import { BOARD_COLUMNS } from './dragDrop';

const COLUMN_WIDTH = 280;
const COLUMN_STEP = 300;

/** jsdom has no layout: put the five columns side by side and each card at the top of its own. */
function layOutBoard() {
  const getRect = /** @this {Element} */ function () {
    const column = this.closest('[data-status].board-column');
    if (this.closest('[data-overlay="true"]') !== null) {
      // The copy that follows the keyboard starts on the card it was lifted from.
      return new DOMRect(8, 40, 260, 100);
    }
    if (column === null) {
      return new DOMRect(0, 0, 0, 0);
    }
    const left =
      BOARD_COLUMNS.indexOf(/** @type {any} */ (column.getAttribute('data-status'))) * COLUMN_STEP;
    return this === column
      ? new DOMRect(left, 0, COLUMN_WIDTH, 600)
      : new DOMRect(left + 8, 40, 260, 100);
  };
  return jest.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(getRect);
}

const pool = makeTask({ id: 1, offer: 'Vulkan Bet', allowedActions: ['pick_up'] });

function renderDesignerBoard() {
  return renderApp('/board', {
    me: makeMe({ id: 3, role: 'designer' }),
    table: {
      'GET /api/options': mockResponse(200, makeOptions()),
      [`GET ${BOARD_PATH}`]: boardResponse([pool]),
      'POST /api/tasks/1/pickup': mockResponse(200, { id: 1, status: 'in_progress' }),
    },
  });
}

beforeEach(() => {
  setCsrfToken(null);
  layOutBoard();
});
afterEach(() => {
  jest.restoreAllMocks();
});

describe('dragging with the keyboard', () => {
  it('picks the task up: Space on the handle, Right to the In progress column, Space to drop', async () => {
    const { fetchMock } = renderDesignerBoard();
    const handle = await screen.findByRole('button', { name: 'Drag Vulkan Bet to In progress' });
    handle.focus();
    await userEvent.keyboard(' ');
    await screen.findByText('Lifted Vulkan Bet.', { selector: '[id^=DndLiveRegion]' });
    await userEvent.keyboard('{ArrowRight}');
    await screen.findByText(/over the In progress column/, { selector: '[id^=DndLiveRegion]' });
    await userEvent.keyboard(' ');
    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks/1/pickup')).toHaveLength(1));
  });

  it('drops nothing when the drag is cancelled with Escape', async () => {
    const { fetchMock } = renderDesignerBoard();
    const handle = await screen.findByRole('button', { name: 'Drag Vulkan Bet to In progress' });
    handle.focus();
    await userEvent.keyboard(' ');
    await userEvent.keyboard('{ArrowRight}');
    await userEvent.keyboard('{Escape}');
    expect(callsTo(fetchMock, 'POST', '/api/tasks/1/pickup')).toHaveLength(0);
  });

  it('drops nothing when the card is dropped without leaving the New column', async () => {
    const { fetchMock } = renderDesignerBoard();
    const handle = await screen.findByRole('button', { name: 'Drag Vulkan Bet to In progress' });
    handle.focus();
    await userEvent.keyboard(' ');
    await userEvent.keyboard(' ');
    expect(callsTo(fetchMock, 'POST', '/api/tasks/1/pickup')).toHaveLength(0);
  });

  it('keeps Enter on the offer link for opening the task', async () => {
    const { router, fetchMock } = renderDesignerBoard();
    await screen.findByRole('link', { name: 'Vulkan Bet' });
    screen.getByRole('link', { name: 'Vulkan Bet' }).focus();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => expect(router.state.location.pathname).toBe('/tasks/1'));
    expect(callsTo(fetchMock, 'POST', '/api/tasks/1/pickup')).toHaveLength(0);
  });
});
