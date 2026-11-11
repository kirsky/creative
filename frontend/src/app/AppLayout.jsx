import { AppShell, Badge, Button, Container, Text } from '@mantine/core';
import { useState } from 'react';
import { NavLink, Outlet } from 'react-router';
import { useAuth } from '../features/auth/useAuth';
import './AppLayout.scss';

// All user-visible text of this component.
const TEXT = {
  appName: 'Creative tasks',
  mainNavigation: 'Main',
  signOut: 'Sign out',
  signOutFailed: 'Could not sign out, try again',
  roles: { buyer: 'Media buyer', designer: 'Designer', manager: 'Manager' },
  nav: { board: 'Board', newTask: 'New task', myWork: 'My work', dashboard: 'Dashboard' },
};

const BOARD = { to: '/board', label: TEXT.nav.board };

/** @type {Record<import('../features/auth/useAuth').Me['role'], {to: string, label: string}[]>} */
const NAV_LINKS = {
  buyer: [BOARD, { to: '/tasks/new', label: TEXT.nav.newTask }],
  designer: [{ to: '/my-work', label: TEXT.nav.myWork }, BOARD],
  manager: [{ to: '/dashboard', label: TEXT.nav.dashboard }, BOARD],
};

/** @param {{isActive: boolean}} state */
function navLinkClass({ isActive }) {
  return isActive ? 'app-nav__link app-nav__link--active' : 'app-nav__link';
}

/** The shell of every signed-in page: header with navigation and the user, content below. */
export function AppLayout() {
  const { user, signOut } = useAuth();
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
      <AppShell.Header>
        <div className="app-header">
          <div className="app-header__start">
            <span className="app-header__title">{TEXT.appName}</span>
            <nav className="app-nav" aria-label={TEXT.mainNavigation}>
              {NAV_LINKS[user.role].map((link) => (
                <NavLink key={link.to} to={link.to} className={navLinkClass}>
                  {link.label}
                </NavLink>
              ))}
            </nav>
          </div>
          <div className="app-header__end">
            {signOutFailed && <span className="app-header__error">{TEXT.signOutFailed}</span>}
            <Text size="sm" visibleFrom="sm">
              {user.displayName}
            </Text>
            <Badge variant="light" visibleFrom="xs">
              {TEXT.roles[user.role]}
            </Badge>
            <Button variant="default" size="xs" loading={isSigningOut} onClick={handleSignOut}>
              {TEXT.signOut}
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
