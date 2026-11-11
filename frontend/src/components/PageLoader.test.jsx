import { act, render, screen } from '@testing-library/react';
import i18n from '../i18n';
import { PageLoader } from './PageLoader';
import { AppProviders } from '../app/providers';

describe('PageLoader', () => {
  it('is a status with a translated label', async () => {
    render(
      <AppProviders>
        <PageLoader />
      </AppProviders>,
    );
    expect(screen.getByRole('status', { name: 'Loading' })).toBeInTheDocument();
    await act(() => i18n.changeLanguage('ru'));
    expect(screen.getByRole('status', { name: 'Загрузка' })).toBeInTheDocument();
  });
});
