import { AppShell, Badge, Button, Container, Text } from '@mantine/core';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Kanban, ListChecks, ChartLineUp, PlusCircle, SignOut } from '@phosphor-icons/react';
import { NavLink, Outlet } from 'react-router';
import { BrandMark } from '../components/BrandMark';
import { Icon } from '../components/Icon';
import { LanguageSwitcher } from '../components/LanguageSwitcher';
import { useAuth } from '../features/auth/useAuth';
import './AppLayout.scss';

const BOARD = { to: '/board', labelKey: 'nav.board', icon: Kanban };

/** @type {Record<import('../features/auth/useAuth').Me['role'], {to: string, labelKey: string, icon: import('../components/Icon').IconComponent}[]>} */
const NAV_LINKS = {
  buyer: [BOARD, { to: '/tasks/new', labelKey: 'nav.newTask', icon: PlusCircle }],
  designer: [{ to: '/my-work', labelKey: 'nav.myWork', icon: ListChecks }, BOARD],
  manager: [{ to: '/dashboard', labelKey: 'nav.dashboard', icon: ChartLineUp }, BOARD],
};

/** @param {{isActive: boolean}} state */
function navLinkClass({ isActive }) {
  return isActive ? 'app-nav__link app-nav__link--active' : 'app-nav__link';
}

/** The shell of every signed-in page: header with navigation and the user, content below. */
export function AppLayout() {
  const { user, signOut } = useAuth();
  const { t } = useTranslation('common');
  const [isSigningOut, setIsSigningOut] = useState(false);
  const [signOutFailed, setSignOutFailed] = useState(false);

  if (user === null) {
    return null;
  }

  async function handleSignOut() {
    setIsSigningOut(true);
    setSignOutFailed(false);
    try {
      await signOut();
    } catch {
      setSignOutFailed(true);
      setIsSigningOut(false);
    }
  }

  return (
    <AppShell header={{ height: 60 }} padding="md">
      <AppShell.Header className="app-shell-header">
        <div className="app-header">
          <div className="app-header__start">
            <span className="app-header__title">
              <BrandMark />
              {t('appName')}
            </span>
            <nav className="app-nav" aria-label={t('mainNavigation')}>
              {NAV_LINKS[user.role].map((link) => (
                <NavLink key={link.to} to={link.to} className={navLinkClass}>
                  {({ isActive }) => (
                    <>
                      <Icon icon={link.icon} size={18} weight={isActive ? 'fill' : 'regular'} />
                      {t(link.labelKey)}
                    </>
                  )}
                </NavLink>
              ))}
            </nav>
          </div>
          <div className="app-header__end">
            <LanguageSwitcher />
            {signOutFailed && <span className="app-header__error">{t('signOutFailed')}</span>}
            <Text size="sm" visibleFrom="sm">
              {user.displayName}
            </Text>
            <Badge variant="light" visibleFrom="xs">
              {t(`roles.${user.role}`)}
            </Badge>
            <Button
              variant="default"
              size="xs"
              loading={isSigningOut}
              leftSection={<Icon icon={SignOut} />}
              onClick={handleSignOut}
            >
              {t('signOut')}
            </Button>
          </div>
        </div>
      </AppShell.Header>
      <AppShell.Main>
        <Container size="xl" p={0}>
          <Outlet />
        </Container>
      </AppShell.Main>
    </AppShell>
  );
}
