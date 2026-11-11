import { zodResolver } from '@hookform/resolvers/zod';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useForm } from 'react-hook-form';
import { setCsrfToken } from '../../../api/client';
import { renderInProviders } from '../../../test/actions';
import { errorResponse, mockResponse } from '../../../test/http';
import { AiUsageEditor } from './AiUsageEditor';
import { aiUsageFormSchema, toApiLines } from './schemas';

const PROVIDERS = mockResponse(200, {
  providers: [
    { id: 2, name: 'Midjourney' },
    { id: 1, name: 'Runway' },
  ],
});

/** @param {{onValid: (values: unknown) => void, defaults?: {provider: string, credits: string}[]}} props */
function Harness({ onValid, defaults = [] }) {
  const { control, handleSubmit } = useForm({
    resolver: /** @type {any} */ (zodResolver(aiUsageFormSchema)),
    defaultValues: { aiUsage: defaults },
  });
  return (
    <form onSubmit={handleSubmit(onValid)} noValidate>
      <AiUsageEditor control={control} />
      <button type="submit">Check</button>
    </form>
  );
}

function renderEditor(
  /** @type {Partial<Parameters<typeof Harness>[0]>} */ props = {},
  /** @type {ReturnType<typeof mockResponse>} */ providers = PROVIDERS,
) {
  const onValid = jest.fn();
  const utils = renderInProviders(<Harness onValid={onValid} {...props} />, {
    'GET /api/ai-providers': providers,
  });
  return { onValid, ...utils };
}

const addLine = () => userEvent.click(screen.getByRole('button', { name: 'Add line' }));
const check = () => userEvent.click(screen.getByRole('button', { name: 'Check' }));

/** @param {number} number @param {string} provider @param {string} credits */
async function fillLine(number, provider, credits) {
  await userEvent.type(screen.getByRole('combobox', { name: `Provider ${number}` }), provider);
  await userEvent.type(screen.getByLabelText(`Credits ${number}`), credits);
}

beforeEach(() => {
  setCsrfToken('c'.repeat(64));
});

describe('AiUsageEditor', () => {
  it('starts with no lines and says that no AI used is fine', async () => {
    const { onValid } = renderEditor();
    expect(screen.queryByRole('combobox', { name: 'Provider 1' })).not.toBeInTheDocument();
    expect(screen.getByText('No AI used')).toBeInTheDocument();
    expect(screen.getByText(/Leave the list empty if you used no AI/)).toBeInTheDocument();
    await check();
    expect(onValid).toHaveBeenCalledWith({ aiUsage: [] }, expect.anything());
  });

  it('adds and removes lines', async () => {
    renderEditor();
    await addLine();
    await addLine();
    expect(screen.getByRole('combobox', { name: 'Provider 2' })).toBeInTheDocument();
    expect(screen.queryByText('No AI used')).not.toBeInTheDocument();
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 1' }), 'Runway');
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 2' }), 'Sora');
    await userEvent.click(screen.getByRole('button', { name: 'Remove line 1' }));
    expect(screen.queryByRole('combobox', { name: 'Provider 2' })).not.toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Provider 1' })).toHaveValue('Sora');
    await userEvent.click(screen.getByRole('button', { name: 'Remove line 1' }));
    expect(screen.getByText('No AI used')).toBeInTheDocument();
  });

  it.each(['0', '0.1', '120', '120.50', '9999999.99', '7.5'])(
    'accepts the amount %j and sends it as the same string',
    async (credits) => {
      const { onValid } = renderEditor();
      await addLine();
      await fillLine(1, '  Runway  ', credits);
      await check();
      expect(onValid).toHaveBeenCalledTimes(1);
      const [values] = onValid.mock.calls[0];
      // The form keeps what was typed; the dialogs send the lines through toApiLines.
      expect(toApiLines(values.aiUsage)).toEqual([{ provider: 'Runway', credits }]);
      expect(typeof values.aiUsage[0].credits).toBe('string');
    },
  );

  it.each(['-1', '1.234', 'abc', '1e3', ' 5', '5 ', '.5', '1.', '1,5', '12345678', '+5'])(
    'rejects the amount %j with the invalid-amount message',
    async (credits) => {
      const { onValid } = renderEditor();
      await addLine();
      await fillLine(1, 'Runway', credits);
      await check();
      expect(onValid).not.toHaveBeenCalled();
      expect(
        await screen.findByText('Enter a number of zero or more, with up to two decimal places.'),
      ).toBeInTheDocument();
      expect(screen.getByLabelText('Credits 1')).toBeInvalid();
    },
  );

  it('requires a provider and an amount in a line', async () => {
    const { onValid } = renderEditor();
    await addLine();
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 1' }), '   ');
    await check();
    expect(onValid).not.toHaveBeenCalled();
    expect(await screen.findAllByText('This field is required.')).toHaveLength(2);
    expect(screen.getByRole('combobox', { name: 'Provider 1' })).toBeInvalid();
    expect(screen.getByLabelText('Credits 1')).toBeInvalid();
  });

  it('allows a provider of 50 characters and refuses 51', async () => {
    const { onValid } = renderEditor();
    await addLine();
    await userEvent.click(screen.getByRole('combobox', { name: 'Provider 1' }));
    await userEvent.paste('é'.repeat(50));
    await userEvent.type(screen.getByLabelText('Credits 1'), '1');
    await check();
    expect(onValid).toHaveBeenCalledTimes(1);

    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 1' }), 'x');
    await check();
    expect(onValid).toHaveBeenCalledTimes(1);
    expect(await screen.findByText('Must be at most 50 characters')).toBeInTheDocument();
  });

  it('allows the same provider on two lines', async () => {
    const { onValid } = renderEditor();
    await addLine();
    await addLine();
    await fillLine(1, 'Runway', '10');
    await fillLine(2, 'Runway', '5.5');
    await check();
    expect(onValid.mock.calls[0][0].aiUsage).toEqual([
      { provider: 'Runway', credits: '10' },
      { provider: 'Runway', credits: '5.5' },
    ]);
  });

  it('blocks a 21st line', async () => {
    renderEditor();
    for (let line = 0; line < 20; line += 1) {
      await addLine();
    }
    expect(screen.getAllByRole('combobox', { name: /^Provider \d+$/ })).toHaveLength(20);
    expect(screen.getByRole('button', { name: 'Add line' })).toBeDisabled();
    await userEvent.click(screen.getByRole('button', { name: 'Remove line 20' }));
    expect(screen.getByRole('button', { name: 'Add line' })).toBeEnabled();
  }, 15_000);

  it('suggests the providers of the API and still takes free text', async () => {
    const { onValid } = renderEditor();
    await addLine();
    const field = screen.getByRole('combobox', { name: 'Provider 1' });
    await userEvent.click(field);
    const options = await screen.findAllByRole('option', { hidden: true });
    expect(options.map((option) => option.textContent)).toEqual(['Midjourney', 'Runway']);

    await userEvent.click(options[1]);
    expect(field).toHaveValue('Runway');

    await addLine();
    await userEvent.type(screen.getByRole('combobox', { name: 'Provider 2' }), 'Brand New Tool');
    await userEvent.type(screen.getByLabelText('Credits 1'), '3');
    await userEvent.type(screen.getByLabelText('Credits 2'), '4');
    await check();
    expect(
      onValid.mock.calls[0][0].aiUsage.map((/** @type {any} */ line) => line.provider),
    ).toEqual(['Runway', 'Brand New Tool']);
  });

  it('works without suggestions when the providers cannot be loaded', async () => {
    const { onValid } = renderEditor({}, errorResponse(500, 'internal_error'));
    await addLine();
    await fillLine(1, 'Runway', '2');
    await check();
    expect(onValid).toHaveBeenCalledTimes(1);
  });

  it('is prefilled from the lines it is given', () => {
    renderEditor({ defaults: [{ provider: 'Runway', credits: '60.00' }] });
    expect(screen.getByRole('combobox', { name: 'Provider 1' })).toHaveValue('Runway');
    expect(screen.getByLabelText('Credits 1')).toHaveValue('60.00');
  });
});
