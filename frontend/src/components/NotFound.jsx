import { Anchor, Stack, Text, Title } from '@mantine/core';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

export function NotFound() {
  const { t } = useTranslation('common');
  return (
    <Stack gap="xs">
      <Title order={1}>{t('pageNotFound')}</Title>
      <Text>
        <Anchor component={Link} to="/">
          {t('goToStartPage')}
        </Anchor>
      </Text>
    </Stack>
  );
}
