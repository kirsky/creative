import { Button, Stack, Text, Title } from '@mantine/core';
import { ArrowClockwise, WarningOctagon } from '@phosphor-icons/react';
import { useTranslation } from 'react-i18next';
import { Icon } from './Icon';
import { reloadPage } from './reloadPage';

/**
 * What a route shows in place of a page that threw while rendering, or whose code chunk failed
 * to download (after a new version is deployed the old hashed chunks are gone). A reload fetches
 * the current index.html and with it the current chunk names.
 */
export function RouteError() {
  const { t } = useTranslation('common');
  return (
    <Stack gap="sm" align="flex-start" role="alert">
      <Icon icon={WarningOctagon} size={48} weight="duotone" />
      <Title order={1}>{t('routeError.title')}</Title>
      <Text>{t('routeError.text')}</Text>
      <Button variant="gradient" leftSection={<Icon icon={ArrowClockwise} />} onClick={reloadPage}>
        {t('routeError.reload')}
      </Button>
    </Stack>
  );
}
