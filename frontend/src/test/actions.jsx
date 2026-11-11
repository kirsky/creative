import { MantineProvider } from '@mantine/core';
import { Notifications, notifications } from '@mantine/notifications';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { installFetch, mockResponse } from './http';

afterEach(() => {
  notifications.clean();
});

/**
 * Renders a component of the task actions with the providers it needs (Mantine, toasts, TanStack
 * Query) but without the router and the session, over a fetch mock.
 * @param {import('react').ReactNode} ui
 * @param {Parameters<typeof installFetch>[0]} [table]
 */
export function renderInProviders(ui, table = {}) {
  const fetchMock = installFetch(table);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const utils = render(
    <MantineProvider>
      <Notifications position="bottom-right" />
      <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>
    </MantineProvider>,
  );
  return { fetchMock, queryClient, ...utils };
}

/** @param {string} name @returns {File} */
export function png(name) {
  return new File(['abc'], name, { type: 'image/png' });
}

export const fileInput = () =>
  /** @type {HTMLInputElement} */ (document.querySelector('input[type="file"]'));

/**
 * The answer of POST /api/files for the n-th call.
 * @param {number} firstId
 */
export function fileUploads(firstId = 11) {
  let id = firstId - 1;
  return () => {
    id += 1;
    return mockResponse(201, { id, name: `f${id}.png`, mime: 'image/png', size: 3 });
  };
}

/** The body of the n-th call to a method and path, parsed. */
export function bodyOf(
  /** @type {{mock: {calls: any[][]}}} */ fetchMock,
  /** @type {string} */ method,
  /** @type {string} */ path,
  index = 0,
) {
  const calls = fetchMock.mock.calls.filter(
    ([url, init]) => url === path && (init?.method ?? 'GET') === method,
  );
  return calls[index] === undefined ? undefined : JSON.parse(calls[index][1].body);
}

/** Pastes text into a field: typing thousands of characters one by one is slow. */
export async function paste(/** @type {HTMLElement} */ field, /** @type {string} */ text) {
  await userEvent.click(field);
  await userEvent.paste(text);
}

export const dialog = () => screen.findByRole('dialog');
