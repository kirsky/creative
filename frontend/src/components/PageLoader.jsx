import { Center, Loader } from '@mantine/core';
import { useTranslation } from 'react-i18next';

/** What a page that is still being downloaded shows in its place. */
export function PageLoader() {
  const { t } = useTranslation('common');
  return (
    <Center h="50vh" role="status" aria-label={t('loading')}>
      <Loader />
    </Center>
  );
}
