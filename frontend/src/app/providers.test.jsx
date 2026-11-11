import { useDatesContext } from '@mantine/dates';
import { act, render, screen } from '@testing-library/react';
import { changeLanguage } from '../i18n';
import { errorResponse, installFetch } from '../test/http';
import { AppProviders } from './providers';

function DatesLocale() {
  const { locale } = useDatesContext();
  return <p data-testid="dates-locale">{locale}</p>;
}

describe('AppProviders', () => {
  it('points the Mantine date components at the interface language', async () => {
    installFetch({ 'GET /api/auth/me': errorResponse(401, 'unauthenticated') });
    render(
      <AppProviders>
        <DatesLocale />
      </AppProviders>,
    );
    expect(screen.getByTestId('dates-locale')).toHaveTextContent('en');
    await act(() => changeLanguage('ru'));
    expect(screen.getByTestId('dates-locale')).toHaveTextContent('ru');
  });
});
