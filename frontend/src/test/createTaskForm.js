import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Pins the clock the form reads (Date.now) so the default deadline and "in the future" are fixed.
 * Restore with jest.restoreAllMocks() in afterEach.
 * @param {string} iso
 */
export function mockNow(iso) {
  return jest.spyOn(Date, 'now').mockReturnValue(Date.parse(iso));
}

/**
 * Opens a Select or MultiSelect by its label and returns its options. Mantine keeps the closed
 * dropdowns of other selects in the page, so the options are taken from the opened one only;
 * jsdom reports a dropdown in transition as hidden, hence `hidden: true`.
 * @param {RegExp} label
 * @returns {Promise<HTMLElement[]>}
 */
export async function openOptions(label) {
  const combobox = await screen.findByRole('combobox', { name: label });
  await userEvent.click(combobox);
  const listbox = document.getElementById(combobox.getAttribute('aria-controls') ?? '');
  if (listbox === null) {
    throw new Error('the dropdown did not open');
  }
  return within(listbox).findAllByRole('option', { hidden: true });
}

/** @param {HTMLElement[]} options */
export const optionNames = (options) => options.map((option) => option.textContent);

/**
 * Opens a Select or MultiSelect by its label and clicks an option.
 * @param {RegExp} label
 * @param {string} option
 */
export async function chooseOption(label, option) {
  const options = await openOptions(label);
  const wanted = options.find((candidate) => candidate.textContent === option);
  if (wanted === undefined) {
    throw new Error(`no option "${option}"`);
  }
  await userEvent.click(wanted);
}

/** Fills every required field of the brief with valid values (English labels). */
export async function fillValidForm() {
  await userEvent.type(await screen.findByLabelText(/^Offer/), 'Casino Royal');
  await chooseOption(/^Creative type/, 'Video');
  await chooseOption(/^GEO/, 'Germany');
  await userEvent.type(screen.getByLabelText(/^Description/), 'Make it pop');
}

/** @param {ReturnType<typeof jest.fn>} fetchMock @param {string} method @param {string} path */
export function callsTo(fetchMock, method, path) {
  return fetchMock.mock.calls.filter(
    ([url, init]) => url === path && (init?.method ?? 'GET') === method,
  );
}
