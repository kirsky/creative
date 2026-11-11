import { MantineProvider } from '@mantine/core';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import i18n from '../../i18n';
import { GeoSelect } from './GeoSelect';

const REGIONS = ['WW', 'EU', 'LATAM', 'MENA', 'CIS'];
const COUNTRIES = ['DE', 'US', 'FR', 'BR', 'AT', 'PL'];

/** @param {{initial?: string[], countries?: string[], maxValues?: number}} props */
function Harness({ initial = [], countries = COUNTRIES, maxValues }) {
  const [value, setValue] = useState(initial);
  return (
    <MantineProvider>
      <GeoSelect
        regions={REGIONS}
        countries={countries}
        value={value}
        onChange={setValue}
        maxValues={maxValues}
      />
      <output data-testid="count">{value.length}</output>
      <output data-testid="value">{value.join(',')}</output>
    </MantineProvider>
  );
}

const input = () => screen.getByRole('combobox', { name: /^GEO/ });
const optionNames = async () =>
  (await screen.findAllByRole('option', { hidden: true })).map((option) => option.textContent);

describe('GeoSelect', () => {
  it('lists the regions first, then the countries sorted by their English names', async () => {
    render(<Harness />);
    await userEvent.click(input());
    expect(await optionNames()).toEqual([
      'Worldwide',
      'Europe',
      'Latin America',
      'Middle East and North Africa',
      'CIS',
      'Austria',
      'Brazil',
      'France',
      'Germany',
      'Poland',
      'United States',
    ]);
    expect(screen.getByText('Regions')).toBeInTheDocument();
    expect(screen.getByText('Countries')).toBeInTheDocument();
  });

  it('re-sorts and renames the countries when the language changes to Russian', async () => {
    render(<Harness />);
    await act(() => i18n.changeLanguage('ru'));
    await userEvent.click(screen.getByRole('combobox', { name: /^ГЕО/ }));
    expect(await optionNames()).toEqual([
      'Весь мир',
      'Европа',
      'Латинская Америка',
      'Ближний Восток и Северная Африка',
      'СНГ',
      'Австрия',
      'Бразилия',
      'Германия',
      'Польша',
      'Соединенные Штаты',
      'Франция',
    ]);
  });

  it('is searchable by the localized name', async () => {
    render(<Harness />);
    await userEvent.type(input(), 'ger');
    expect(await optionNames()).toEqual(['Germany']);
  });

  it('selects countries and regions', async () => {
    render(<Harness />);
    await userEvent.click(input());
    await userEvent.click(await screen.findByRole('option', { name: 'Germany', hidden: true }));
    await userEvent.click(await screen.findByRole('option', { name: 'Europe', hidden: true }));
    expect(screen.getByTestId('value')).toHaveTextContent('DE,EU');
  });

  it('allows no more than the maximum number of values (30 by default)', async () => {
    const many = [
      ...'AT BE BG BR CA CH CZ DE DK EE ES FI FR GB GR HR HU IE IT LT LU LV NL NO NZ PL PT RO SE SI SK TR UA US AU CY'.split(
        ' ',
      ),
    ];
    expect(many).toHaveLength(36);
    render(<Harness countries={many} initial={many.slice(0, 30)} />);
    await userEvent.click(input());
    await userEvent.click(await screen.findByRole('option', { name: 'Cyprus', hidden: true }));
    expect(screen.getByTestId('count')).toHaveTextContent('30');
    // Removing the last value (Backspace) makes room again.
    await userEvent.click(input());
    await userEvent.keyboard('{Backspace}');
    expect(screen.getByTestId('count')).toHaveTextContent('29');
    await userEvent.click(input());
    await userEvent.click(await screen.findByRole('option', { name: 'Cyprus', hidden: true }));
    expect(screen.getByTestId('count')).toHaveTextContent('30');
  });

  it('shows the error it is given', () => {
    render(
      <MantineProvider>
        <GeoSelect
          regions={REGIONS}
          countries={COUNTRIES}
          value={[]}
          onChange={() => {}}
          error="Bad"
        />
      </MantineProvider>,
    );
    expect(screen.getByText('Bad')).toBeInTheDocument();
    expect(input()).toBeInvalid();
  });
});
