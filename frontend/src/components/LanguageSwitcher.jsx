import { SegmentedControl } from '@mantine/core';
import { useTranslation } from 'react-i18next';
import { isLanguage } from '../i18n/detect';
import { changeLanguage } from '../i18n';

// Language names stay in their own language, so a visitor can find theirs.
const OPTIONS = [
  { value: 'en', label: 'EN' },
  { value: 'ru', label: 'RU' },
];

/** EN | RU switch for the app header and the login page. */
export function LanguageSwitcher() {
  const { t, i18n } = useTranslation('common');

  return (
    <SegmentedControl
      size="xs"
      aria-label={t('language.label')}
      data={OPTIONS}
      value={isLanguage(i18n.language) ? i18n.language : 'en'}
      onChange={(value) => {
        if (isLanguage(value)) {
          void changeLanguage(value);
        }
      }}
    />
  );
}
