import { act, screen } from '@testing-library/react';
import { setCsrfToken } from '../../api/client';
import { BOARD_PATH, boardResponse, makeTask } from '../../test/board';
import { callsTo } from '../../test/createTaskForm';
import { makeMe, mockResponse } from '../../test/http';
import { makeOptions, renderApp } from '../../test/renderApp';

/** The props the board hands to dnd-kit, so a test can end a drag without a real pointer. */
const mockDnd = { props: /** @type {Record<string, any>} */ ({}) };

jest.mock('@dnd-kit/core', () => {
  const actual = jest.requireActual('@dnd-kit/core');
  const { createElement } = jest.requireActual('react');
  return {
    ...actual,
    DndContext: (/** @type {Record<string, any>} */ props) => {
      mockDnd.props = props;
      return createElement(actual.DndContext, props);
    },
  };
});

const pool = makeTask({ id: 1, offer: 'Vulkan Bet', allowedActions: ['pick_up'] });
const mine = makeTask({
  id: 3,
  offer: 'Mine',
  status: 'in_progress',
  assignee: { id: 3, displayName: 'Anna K.' },
  allowedActions: ['submit', 'release'],
});

function renderDesignerBoard() {
  return renderApp('/board', {
    me: makeMe({ id: 3, role: 'designer' }),
    table: {
      'GET /api/options': mockResponse(200, makeOptions()),
      [`GET ${BOARD_PATH}`]: boardResponse([pool, mine]),
      'POST /api/tasks/1/pickup': mockResponse(200, { id: 1, status: 'in_progress' }),
      'POST /api/tasks/3/pickup': mockResponse(200, { id: 3, status: 'in_progress' }),
    },
  });
}

/** @param {unknown} activeId @param {unknown} overId */
async function endDrag(activeId, overId) {
  await act(async () => {
    mockDnd.props.onDragEnd({
      active: { id: activeId },
      over: overId === null ? null : { id: overId },
    });
  });
}

beforeEach(() => {
  setCsrfToken(null);
});

describe('ending a drag', () => {
  it('picks the task up when it is dropped on the in_progress column', async () => {
    const { fetchMock } = renderDesignerBoard();
    await screen.findByText('Vulkan Bet');
    await endDrag(1, 'in_progress');
    expect(callsTo(fetchMock, 'POST', '/api/tasks/1/pickup')).toHaveLength(1);
  });

  it.each(['new', 'under_review', 'revisions_required', 'completed', 'cancelled'])(
    'cancels the drop on %s',
    async (column) => {
      const { fetchMock } = renderDesignerBoard();
      await screen.findByText('Vulkan Bet');
      await endDrag(1, column);
      expect(callsTo(fetchMock, 'POST', '/api/tasks/1/pickup')).toHaveLength(0);
    },
  );

  it('does nothing for a drop over nothing', async () => {
    const { fetchMock } = renderDesignerBoard();
    await screen.findByText('Vulkan Bet');
    await endDrag(1, null);
    expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(0);
  });

  it('does nothing for a task that may not be picked up', async () => {
    const { fetchMock } = renderDesignerBoard();
    await screen.findByText('Vulkan Bet');
    await endDrag(3, 'in_progress');
    expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(0);
  });

  it('does nothing for a task that is no longer on the board', async () => {
    const { fetchMock } = renderDesignerBoard();
    await screen.findByText('Vulkan Bet');
    await endDrag(99, 'in_progress');
    expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(0);
  });
});

describe('the drag-and-drop set-up', () => {
  it('uses a pointer and a keyboard sensor and explains the keyboard drag in the active language', async () => {
    renderDesignerBoard();
    await screen.findByText('Vulkan Bet');
    expect(mockDnd.props.sensors).toHaveLength(2);
    expect(mockDnd.props.accessibility.screenReaderInstructions.draggable).toMatch(/Space/);
  });
});
