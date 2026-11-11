import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { setCsrfToken } from '../../api/client';
import {
  callsTo,
  fillValidForm,
  mockNow,
  openOptions,
  optionNames,
} from '../../test/createTaskForm';
import { EMPTY_BOARD_TABLE } from '../../test/board';
import { errorResponse, makeMe, mockResponse } from '../../test/http';
import { makeOptions, renderApp } from '../../test/renderApp';

const OPTIONS_OK = { 'GET /api/options': mockResponse(200, makeOptions()) };

/** @param {Parameters<typeof renderApp>[1]} [options] */
function renderForm(options) {
  return renderApp('/tasks/new', { ...options, table: { ...OPTIONS_OK, ...options?.table } });
}

const heading = () => screen.findByRole('heading', { level: 1, name: 'New task' });
const submit = () => screen.getByRole('button', { name: 'Create task' });
const quantity = () => screen.getByLabelText(/^Quantity/);
const offer = () => screen.getByLabelText(/^Offer/);

beforeEach(() => {
  setCsrfToken(null);
});
afterEach(() => {
  jest.restoreAllMocks();
});

describe('access', () => {
  it('opens from the buyer navigation link', async () => {
    renderApp('/board', { table: EMPTY_BOARD_TABLE });
    await userEvent.click(
      within(await screen.findByRole('navigation')).getByRole('link', { name: 'New task' }),
    );
    expect(await heading()).toBeInTheDocument();
  });

  it.each([
    ['designer', '/my-work'],
    ['manager', '/dashboard'],
  ])('redirects a %s to %s without asking for options', async (role, landing) => {
    const { router, fetchMock } = renderForm({
      me: makeMe({ role: /** @type {'designer' | 'manager'} */ (role) }),
      table: EMPTY_BOARD_TABLE,
    });
    await screen.findByRole('heading', { level: 1 });
    expect(router.state.location.pathname).toBe(landing);
    expect(callsTo(fetchMock, 'GET', '/api/options')).toHaveLength(0);
  });
});

describe('layout', () => {
  it('has the five sections, marks required fields and offers Create and Cancel', async () => {
    renderForm();
    await heading();
    for (const name of ['Basics', 'Target', 'Details', 'Deadline', 'References']) {
      expect(await screen.findByRole('heading', { level: 2, name })).toBeInTheDocument();
    }
    const required = [...document.querySelectorAll('label[data-required="true"]')].map((label) =>
      (label.textContent ?? '').replace(/\s*\*$/, ''),
    );
    expect(required).toEqual([
      'Offer',
      'Creative type',
      'Quantity',
      'GEO',
      'Description and requirements',
      'Deadline',
    ]);
    expect(submit()).toBeEnabled();
    expect(screen.getByRole('link', { name: 'Cancel' })).toHaveAttribute('href', '/board');
  });

  it('shows the form in Russian', async () => {
    renderForm();
    await heading();
    await userEvent.click(screen.getByRole('radio', { name: 'RU' }));
    expect(await screen.findByRole('button', { name: 'Создать задачу' })).toBeInTheDocument();
    for (const label of [
      /^Оффер/,
      /^Тип креатива/,
      /^Количество/,
      /^ГЕО/,
      /^Описание и требования/,
      /^Дедлайн/,
    ]) {
      expect(screen.getAllByLabelText(label)[0]).toBeInTheDocument();
    }
    expect(screen.getByRole('heading', { level: 2, name: 'Референсы' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Прикрепить файлы' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Обычный' })).toBeChecked();
  });

  it('shows a loader while the options load, and an alert with a retry when they fail', async () => {
    const { fetchMock } = renderApp('/tasks/new', {
      table: { 'GET /api/options': errorResponse(500, 'internal_error') },
    });
    expect(await screen.findByRole('alert')).toHaveTextContent('Something went wrong on our side.');
    expect(screen.queryByLabelText(/^Offer/)).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
    await waitFor(() =>
      expect(callsTo(fetchMock, 'GET', '/api/options').length).toBeGreaterThan(1),
    );
  });
});

describe('options', () => {
  it('fills creative type and priority with translated labels, priority defaults to normal', async () => {
    renderForm();
    await heading();
    const options = await openOptions(/^Creative type/);
    expect(optionNames(options)).toEqual([
      'Static image',
      'Video',
      'Carousel',
      'Animated GIF',
      'HTML5 banner',
      'Other',
    ]);
    const radios = screen
      .getAllByRole('radio')
      .filter((r) => !['en', 'ru'].includes(r.getAttribute('value') ?? ''));
    expect(radios.map((r) => r.getAttribute('value'))).toEqual(['low', 'normal', 'high', 'urgent']);
    expect(screen.getByRole('radio', { name: 'Normal' })).toBeChecked();
    expect(screen.getByRole('radio', { name: 'Urgent' })).not.toBeChecked();
  });

  it('translates the creative types in Russian', async () => {
    renderForm();
    await heading();
    await userEvent.click(screen.getByRole('radio', { name: 'RU' }));
    const options = await openOptions(/^Тип креатива/);
    expect(optionNames(options)).toEqual([
      'Статичное изображение',
      'Видео',
      'Карусель',
      'GIF-анимация',
      'HTML5-баннер',
      'Другое',
    ]);
  });

  it('offers regions first, then countries by localized name, re-sorted in Russian', async () => {
    renderForm();
    await heading();
    const names = optionNames(await openOptions(/^GEO/));
    expect(names.slice(0, 2)).toEqual(['Worldwide', 'Europe']);
    expect(names.slice(5)).toEqual([
      'Austria',
      'Brazil',
      'France',
      'Germany',
      'Poland',
      'United States',
    ]);
    await userEvent.click(screen.getByRole('radio', { name: 'RU' }));
    expect(optionNames(await openOptions(/^ГЕО/)).slice(5)).toEqual([
      'Австрия',
      'Бразилия',
      'Германия',
      'Польша',
      'Соединенные Штаты',
      'Франция',
    ]);
  });

  it('defaults the deadline to tomorrow at 12:00 local time', async () => {
    mockNow('2026-06-15T08:00:00Z');
    renderForm();
    await heading();
    expect(await screen.findByRole('button', { name: /^Deadline/ })).toHaveTextContent(
      '16 Jun 2026 12:00',
    );
  });
});

describe('client-side validation', () => {
  it('submitting an empty form shows required errors, sends nothing and focuses the first invalid field', async () => {
    const { fetchMock } = renderForm();
    await heading();
    await userEvent.click(await screen.findByRole('button', { name: 'Create task' }));
    expect(await screen.findAllByText('This field is required.')).toHaveLength(4);
    expect(offer()).toBeInvalid();
    expect(screen.getByRole('combobox', { name: /^Creative type/ })).toBeInvalid();
    expect(screen.getByRole('combobox', { name: /^GEO/ })).toBeInvalid();
    expect(screen.getByLabelText(/^Description/)).toBeInvalid();
    expect(offer()).toHaveFocus();
    expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(0);
  });

  it('offer "a" is too short, in English and in Russian', async () => {
    renderForm();
    await heading();
    await userEvent.type(await screen.findByLabelText(/^Offer/), 'a');
    await userEvent.click(submit());
    expect(await screen.findByText('Must be at least 2 characters')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('radio', { name: 'RU' }));
    expect(await screen.findByText('Минимум 2 символа')).toBeInTheDocument();
  });

  it.each([
    ['0', 'Must be between 1 and 100.'],
    ['101', 'Must be between 1 and 100.'],
    ['2.5', 'This value is not valid.'],
  ])('rejects quantity %s', async (typed, message) => {
    const { fetchMock } = renderForm();
    await heading();
    await fillValidForm();
    await userEvent.clear(quantity());
    await userEvent.type(quantity(), typed);
    await userEvent.click(submit());
    expect(await screen.findByText(message)).toBeInTheDocument();
    expect(quantity()).toBeInvalid();
    expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(0);
  });

  it.each(['1', '100'])('accepts quantity %s', async (typed) => {
    const { fetchMock } = renderForm({
      table: { 'POST /api/tasks': mockResponse(201, { id: 5 }) },
    });
    await heading();
    await fillValidForm();
    await userEvent.clear(quantity());
    await userEvent.type(quantity(), typed);
    await userEvent.click(submit());
    await waitFor(() => expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(1));
    expect(JSON.parse(callsTo(fetchMock, 'POST', '/api/tasks')[0][1].body).quantity).toBe(
      Number(typed),
    );
  });

  it('does not clamp a quantity silently', async () => {
    renderForm();
    await heading();
    await userEvent.clear(quantity());
    await userEvent.type(quantity(), '500');
    await userEvent.tab();
    expect(quantity()).toHaveValue('500');
  });

  it('requires a description made of more than spaces', async () => {
    renderForm();
    await heading();
    await userEvent.type(await screen.findByLabelText(/^Description/), '   ');
    await userEvent.click(submit());
    await waitFor(() => expect(screen.getByLabelText(/^Description/)).toBeInvalid());
  });

  it('rejects a description over 10000 characters', async () => {
    renderForm();
    await heading();
    await userEvent.click(await screen.findByLabelText(/^Description/));
    await userEvent.paste('x'.repeat(10001));
    await userEvent.click(submit());
    expect(await screen.findByText('Must be at most 10000 characters')).toBeInTheDocument();
  });

  it('blocks a deadline that has passed by the time of submit with must_be_future', async () => {
    const { fetchMock } = renderForm({
      table: { 'POST /api/tasks': mockResponse(201, { id: 5 }) },
    });
    await heading();
    await fillValidForm();
    // The form stays open for three days: the default deadline (tomorrow 12:00) is now behind us.
    mockNow(new Date(Date.now() + 3 * 24 * 3600 * 1000).toISOString());
    await userEvent.click(submit());
    expect(await screen.findByText('Choose a date and time in the future.')).toBeInTheDocument();
    expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(0);
    await userEvent.click(screen.getByRole('radio', { name: 'RU' }));
    expect(await screen.findByText('Выберите дату и время в будущем.')).toBeInTheDocument();
  });

  it.each(['javascript:alert(1)', 'ftp://x', 'example.com'])(
    'rejects the link %s',
    async (value) => {
      const { fetchMock } = renderForm();
      await heading();
      await fillValidForm();
      await userEvent.click(screen.getByRole('button', { name: 'Add link' }));
      await userEvent.type(screen.getByLabelText('Link 1'), value);
      await userEvent.click(submit());
      expect(
        await screen.findByText('Enter a valid link, for example https://example.com.'),
      ).toBeInTheDocument();
      expect(screen.getByLabelText('Link 1')).toBeInvalid();
      expect(callsTo(fetchMock, 'POST', '/api/tasks')).toHaveLength(0);
    },
  );

  it('adds and removes link rows', async () => {
    renderForm();
    await heading();
    await userEvent.click(await screen.findByRole('button', { name: 'Add link' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add link' }));
    expect(screen.getByLabelText('Link 1')).toBeInTheDocument();
    expect(screen.getByLabelText('Link 2')).toHaveFocus();
    await userEvent.type(screen.getByLabelText('Link 2'), 'https://b.example');
    await userEvent.click(screen.getByRole('button', { name: 'Remove link 1' }));
    expect(screen.queryByLabelText('Link 2')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Link 1')).toHaveValue('https://b.example');
  });

  it('stops offering "Add link" at 50 links', async () => {
    renderForm();
    await heading();
    const add = await screen.findByRole('button', { name: 'Add link' });
    for (let i = 0; i < 50; i += 1) {
      await userEvent.click(add);
    }
    expect(add).toBeDisabled();
    expect(within(document.body).getAllByLabelText(/^Link \d+$/)).toHaveLength(50);
    // Fifty real clicks: more than the default 5 s when the whole suite runs in parallel.
  }, 30_000);
});
