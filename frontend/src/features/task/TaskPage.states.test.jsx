import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../api/client';
import { errorResponse, makeMe, mockResponse } from '../../test/http';
import { renderApp } from '../../test/renderApp';
import { makeTaskDetail } from '../../test/taskDetail';

beforeEach(() => {
  setCsrfToken(null);
});

const taskCalls = (/** @type {{mock: {calls: any[][]}}} */ fetchMock) =>
  fetchMock.mock.calls.map(([url]) => url).filter((url) => url.startsWith('/api/tasks'));

describe('loading', () => {
  it('shows a skeleton and a status while the task loads', async () => {
    /** @type {(response: ReturnType<typeof mockResponse>) => void} */
    let release = () => {};
    renderApp('/tasks/42', {
      table: {
        'GET /api/tasks/42': () =>
          new Promise((resolve) => {
            release = resolve;
          }),
      },
    });
    expect(await screen.findByTestId('task-skeleton')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Loading');
    await act(async () => release(mockResponse(200, makeTaskDetail())));
    expect(await screen.findByRole('heading', { level: 1 })).toBeInTheDocument();
    expect(screen.queryByTestId('task-skeleton')).not.toBeInTheDocument();
  });
});

describe('task not found', () => {
  it('shows a translated state with a link back to the board on 404', async () => {
    renderApp('/tasks/99', { table: { 'GET /api/tasks/99': errorResponse(404, 'not_found') } });
    expect(await screen.findByRole('heading', { name: 'Task not found' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to board' })).toHaveAttribute('href', '/board');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('sends a designer back to My work', async () => {
    renderApp('/tasks/99', {
      me: makeMe({ role: 'designer' }),
      table: { 'GET /api/tasks/99': errorResponse(404, 'not_found') },
    });
    expect(await screen.findByRole('heading', { name: 'Task not found' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to My work' })).toHaveAttribute(
      'href',
      '/my-work',
    );
  });

  it('is shown in Russian too', async () => {
    renderApp('/tasks/99', {
      me: makeMe({ locale: 'ru' }),
      table: { 'GET /api/tasks/99': errorResponse(404, 'not_found') },
    });
    expect(await screen.findByRole('heading', { name: 'Задача не найдена' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Назад к доске' })).toBeInTheDocument();
  });

  it.each(['abc', '0', '-3', '1.5', '12abc', '007'])(
    'shows the same state for the id %j without asking the API',
    async (id) => {
      const { fetchMock } = renderApp(`/tasks/${id}`);
      expect(await screen.findByRole('heading', { name: 'Task not found' })).toBeInTheDocument();
      expect(taskCalls(fetchMock)).toEqual([]);
    },
  );
});

describe('error', () => {
  it('shows a translated alert with Retry, which asks again', async () => {
    let calls = 0;
    const { fetchMock } = renderApp('/tasks/42', {
      table: {
        'GET /api/tasks/42': () => {
          calls += 1;
          return calls === 1
            ? errorResponse(403, 'forbidden')
            : mockResponse(200, makeTaskDetail());
        },
      },
    });
    const alert = await screen.findByRole('alert');
    expect(within(alert).getByText('Could not load the task')).toBeInTheDocument();
    expect(alert).not.toHaveTextContent(/forbidden|errors:/);
    await userEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { level: 1 })).toBeInTheDocument();
    expect(taskCalls(fetchMock)).toHaveLength(2);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('treats a forbidden answer as an error, not as a missing task', async () => {
    renderApp('/tasks/42', { table: { 'GET /api/tasks/42': errorResponse(403, 'forbidden') } });
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Task not found' })).not.toBeInTheDocument();
  });
});

describe('roles', () => {
  it.each(['manager', 'buyer', 'designer'])('shows the page to a %s', async (role) => {
    renderApp('/tasks/42', {
      me: makeMe({ role: /** @type {any} */ (role) }),
      table: { 'GET /api/tasks/42': mockResponse(200, makeTaskDetail()) },
    });
    expect(
      await screen.findByRole('heading', { level: 1, name: /Casino Royal/ }),
    ).toBeInTheDocument();
  });

  it('gives a manager no action buttons', async () => {
    renderApp('/tasks/42', {
      me: makeMe({ role: 'manager' }),
      table: {
        'GET /api/tasks/42': mockResponse(
          200,
          makeTaskDetail({ status: 'under_review', allowedActions: [] }),
        ),
      },
    });
    await screen.findByRole('heading', { level: 1 });
    expect(screen.queryByRole('button', { name: /approve|submit|pick up|revisions/i })).toBeNull();
  });
});
