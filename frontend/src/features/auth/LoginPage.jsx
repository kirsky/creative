import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button, PasswordInput, Stack, TextInput, Title } from '@mantine/core';
import { useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Navigate, useLocation } from 'react-router';
import { z } from 'zod';
import { ApiRequestError } from '../../api/ApiRequestError';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import { groupFieldErrors, translateApiError, translateFieldError } from '../../i18n/errors';
import { landingPath, returnPath } from './landing';
import { useAuth } from './useAuth';
import './LoginPage.scss';

// The limits of POST /api/auth/login (openapi.yaml).
const USERNAME_MAX = 100;
const PASSWORD_MAX = 1000;
const MAX_LENGTHS = { username: USERNAME_MAX, password: PASSWORD_MAX };

// The messages of the schema are API field-error codes. They are translated where they are shown
// (translateFieldError), the same path as the errors the server sends, so a language switch
// re-translates a message that is already on screen.
const loginSchema = z.object({
  username: z.string().min(1, 'required').max(USERNAME_MAX, 'too_long'),
  password: z.string().min(1, 'required').max(PASSWORD_MAX, 'too_long'),
});

/** @typedef {z.infer<typeof loginSchema>} LoginValues */
/** @typedef {keyof LoginValues} LoginField */

/** @param {string} name @returns {name is LoginField} */
function isLoginField(name) {
  return name === 'username' || name === 'password';
}

/**
 * Sorts a failed sign-in into what belongs on an input and what belongs to the form.
 * @param {import('i18next').TFunction} t
 * @param {unknown} error
 * @returns {{fields: Partial<Record<LoginField, string>>, form: string[]}}
 */
function describeFailure(t, error) {
  if (!(error instanceof ApiRequestError)) {
    return { fields: {}, form: [t('errors:unknown')] };
  }
  if (error.code !== 'validation_failed') {
    return { fields: {}, form: [translateApiError(t, error)] };
  }
  const grouped = groupFieldErrors(t, error);
  /** @type {Partial<Record<LoginField, string>>} */
  const fields = {};
  const form = [...grouped.form];
  for (const [name, message] of Object.entries(grouped.fields)) {
    if (isLoginField(name)) {
      fields[name] = message;
    } else if (!form.includes(message)) {
      // A field this form does not have: the user cannot fix it, but must be told.
      form.push(message);
    }
  }
  if (form.length === 0 && Object.keys(fields).length === 0) {
    form.push(translateApiError(t, error));
  }
  return { fields, form };
}

/**
 * The sign-in page (route /login, outside AuthGuard). Anyone who is signed in is sent on: to the
 * page AuthGuard stopped them from reaching, else to the landing page of their role. The page
 * offers no list of accounts, no one-click sign-in and no hint of a credential.
 */
export function LoginPage() {
  const { user, signedOut, signIn } = useAuth();
  const { t } = useTranslation(['auth', 'errors']);
  const location = useLocation();
  // Decided once, when the page opens: after an explicit sign-out nothing is remembered, and a
  // later change of this flag (the sign-in itself resets it) must not bring a page back.
  const [forgetPreviousPage] = useState(signedOut);
  const pending = useRef(false);
  const [failure, setFailure] = useState(/** @type {unknown} */ (null));

  const {
    register,
    handleSubmit,
    setValue,
    setFocus,
    formState: { errors, isSubmitting },
  } = useForm({
    resolver: zodResolver(loginSchema),
    defaultValues: { username: '', password: '' },
  });

  if (user !== null) {
    const destination =
      (forgetPreviousPage ? null : returnPath(location.state)) ?? landingPath(user.role);
    return <Navigate to={destination} replace />;
  }

  const failed = failure === null ? null : describeFailure(t, failure);

  /** @param {LoginField} field */
  function fieldError(field) {
    const serverMessage = failed?.fields[field];
    if (serverMessage !== undefined) {
      return serverMessage;
    }
    const code = errors[field]?.message;
    return code === undefined
      ? undefined
      : translateFieldError(t, { code, params: { max: MAX_LENGTHS[field] } });
  }

  /**
   * One request at a time, even for submits that arrive before the disabled button has rendered.
   * @param {import('react').SyntheticEvent<HTMLFormElement>} event
   */
  function handleFormSubmit(event) {
    if (pending.current) {
      event.preventDefault();
      return;
    }
    pending.current = true;
    void handleSubmit(onSubmit)(event).finally(() => {
      pending.current = false;
    });
  }

  /** @param {LoginValues} values */
  async function onSubmit({ username, password }) {
    setFailure(null);
    try {
      // On success `user` appears and the redirect above takes over.
      await signIn(username, password);
    } catch (error) {
      setFailure(error);
      // Keep the username; a password that was refused is not worth keeping.
      setValue('password', '');
      const fieldToFix =
        error instanceof ApiRequestError &&
        error.code === 'validation_failed' &&
        error.fields.some((item) => item.field === 'username')
          ? 'username'
          : 'password';
      setFocus(fieldToFix);
    }
  }

  return (
    <div className="login-page">
      <div className="login-page__language">
        <LanguageSwitcher rememberForSignIn />
      </div>
      <main className="login-page__card">
        <form className="login-page__form" onSubmit={handleFormSubmit} noValidate>
          <Stack gap="md">
            <div>
              <Title order={1} size="h2">
                {t('common:appName')}
              </Title>
              <Title order={2} size="h4" fw={400} c="dimmed">
                {t('auth:login.title')}
              </Title>
            </div>
            {failed !== null && failed.form.length > 0 && (
              <Alert color="red" variant="light" role="alert">
                {failed.form.map((message) => (
                  <div key={message}>{message}</div>
                ))}
              </Alert>
            )}
            <TextInput
              label={t('auth:login.username')}
              autoComplete="username"
              autoCapitalize="none"
              autoCorrect="off"
              spellCheck={false}
              withAsterisk
              error={fieldError('username')}
              {...register('username')}
            />
            <PasswordInput
              label={t('auth:login.password')}
              autoComplete="current-password"
              withAsterisk
              visibilityToggleButtonProps={{ 'aria-label': t('auth:login.togglePassword') }}
              error={fieldError('password')}
              aria-invalid={fieldError('password') !== undefined}
              {...register('password')}
            />
            <Button type="submit" fullWidth loading={isSubmitting}>
              {t('auth:login.submit')}
            </Button>
          </Stack>
        </form>
      </main>
    </div>
  );
}
