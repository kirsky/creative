import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../api/client';
import { reloadPage } from '../components/reloadPage';
import { EMPTY_BOARD_TABLE } from '../test/board';
import { makeMe } from '../test/http';
import { renderApp } from '../test/renderApp';

// A deploy removes the old hashed chunks: the dynamic import of the dashboard then rejects,
// exactly like this module factory throwing.
jest.mock('../features/dashboard/DashboardPage', () => {
  throw new Error('Loading chunk 417 failed.');
});

// A page whose render throws.
jest.mock('../features/mywork/MyWorkPage', () => ({
  MyWorkPage: () => {
    throw new Error('render exploded');
  },
}));

jest.mock('../components/reloadPage', () => ({ reloadPage: jest.fn() }));

beforeEach(() => {
  setCsrfToken(null);
  jest.mocked(reloadPage).mockClear();
  // React and React Router log a caught render error; that is expected here.
  jest.spyOn(console, 'error').mockImplementation(() => undefined);
});
afterEach(() => {
  jest.restoreAllMocks();
});

describe('a page that fails to load or render', () => {
  it('shows a translated message with a Reload page button when a lazy chunk fails to load', async () => {
    renderApp('/dashboard', { me: makeMe({ role: 'manager' }), table: EMPTY_BOARD_TABLE });
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Something went wrong' }),
    ).toBeInTheDocument();
    expect(screen.getByText(/could not be shown/i)).toBeInTheDocument();

    // The header and navigation stay usable around the message.
    expect(screen.getByRole('navigation', { name: 'Main' })).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Reload page' }));
    expect(reloadPage).toHaveBeenCalledTimes(1);
  });

  it('also catches a component that throws while rendering', async () => {
    renderApp('/my-work', { me: makeMe({ role: 'designer' }), table: EMPTY_BOARD_TABLE });
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Something went wrong' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reload page' })).toBeInTheDocument();
  });

  it('is shown in Russian for a Russian user', async () => {
    renderApp('/my-work', {
      me: makeMe({ role: 'designer', locale: 'ru' }),
      table: EMPTY_BOARD_TABLE,
    });
    expect(
      await screen.findByRole('heading', { level: 1, name: 'Что-то пошло не так' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Перезагрузить страницу' })).toBeInTheDocument();
  });
});
