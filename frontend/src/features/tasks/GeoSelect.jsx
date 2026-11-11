import { MultiSelect } from '@mantine/core';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { isLanguage } from '../../i18n/detect';
import { formatCountry } from '../../i18n/format';
import { LIMITS } from './schema';

/**
 * The GEO field: regions first, then the countries, all named in the interface language and
 * sorted by that name (re-sorted when the language changes). Searchable, at most `maxValues`.
 * Anything else (ref, name, error, onBlur…) goes to the Mantine MultiSelect.
 * @param {{
 *   regions: string[],
 *   countries: string[],
 *   value: string[],
 *   onChange: (value: string[]) => void,
 *   maxValues?: number,
 * } & Omit<import('@mantine/core').MultiSelectProps, 'data' | 'value' | 'onChange' | 'maxValues'>} props
 */
export function GeoSelect({
  regions,
  countries,
  value,
  onChange,
  maxValues = LIMITS.geo.max,
  ...rest
}) {
  const { t, i18n } = useTranslation('task');
  const language = isLanguage(i18n.language) ? i18n.language : 'en';

  const data = useMemo(() => {
    const regionItems = regions.map((code) => ({
      value: code,
      label: formatCountry(code, language, t),
    }));
    const countryItems = countries
      .map((code) => ({ value: code, label: formatCountry(code, language, t) }))
      .sort((a, b) => a.label.localeCompare(b.label, language));
    return [
      { group: t('create.geo.regions'), items: regionItems },
      { group: t('create.geo.countries'), items: countryItems },
    ];
  }, [regions, countries, language, t]);

  return (
    <MultiSelect
      label={t('create.geo.label')}
      description={t('create.geo.hint', { count: maxValues })}
      placeholder={t('create.geo.placeholder')}
      nothingFoundMessage={t('create.geo.nothingFound')}
      withAsterisk
      searchable
      data={data}
      value={value}
      onChange={onChange}
      maxValues={maxValues}
      {...rest}
    />
  );
}
