import { Stack, Text, Title } from '@mantine/core';
import { useTranslation } from 'react-i18next';

/**
 * Marks a route whose page is built in a later task.
 * @param {{titleKey: string, values?: Record<string, string | number>}} props the translation
 *   key of the page name (for example "board:title") and its interpolation values
 */
export function ComingSoon({ titleKey, values }) {
  const { t } = useTranslation('common');
  return (
    <Stack gap="xs" data-testid="coming-soon">
      <Title order={1}>{t(titleKey, { ...values })}</Title>
      <Text c="dimmed">{t('comingSoon')}</Text>
    </Stack>
  );
}
