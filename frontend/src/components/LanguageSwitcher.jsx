import { SegmentedControl } from '@mantine/core';
import { useTranslation } from 'react-i18next';
import { isLanguage } from '../i18n/detect';
import { changeLanguage } from '../i18n';
import { rememberLoginLanguage } from '../i18n/loginLanguage';

// Language names stay in their own language, so a visitor can find theirs.
const OPTIONS = [
  { value: 'en', label: 'EN' },
  { value: 'ru', label: 'RU' },
];

/**
 * EN | RU switch for the app header and the login page. On the login page (`rememberForSignIn`) a
 * pick is also remembered, so that the sign-in that follows keeps it instead of the account's.
 * @param {{rememberForSignIn?: boolean}} props
 */
export function LanguageSwitcher({ rememberForSignIn = false }) {
  const { t, i18n } = useTranslation('common');

  return (
    <SegmentedControl
      size="xs"
      aria-label={t('language.label')}
      data={OPTIONS}
      value={isLanguage(i18n.language) ? i18n.language : 'en'}
      onChange={(value) => {
        if (isLanguage(value)) {
          if (rememberForSignIn) {
            rememberLoginLanguage(value);
          }
          void changeLanguage(value);
        }
      }}
    />
  );
}
