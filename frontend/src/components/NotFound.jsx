import { Anchor, Stack, Text, Title } from '@mantine/core';
import { Compass } from '@phosphor-icons/react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Icon } from './Icon';

export function NotFound() {
  const { t } = useTranslation('common');
  return (
    <Stack gap="xs">
      <Icon icon={Compass} size={48} weight="duotone" />
      <Title order={1}>{t('pageNotFound')}</Title>
      <Text>
        <Anchor component={Link} to="/">
          {t('goToStartPage')}
        </Anchor>
      </Text>
    </Stack>
  );
}
