import { useState, type FormEvent } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthProvider';
import { Alert, Button, Field, TextInput } from '@/components/ui';
import { Icon } from '@/components/Icon';
import { Logo } from '@/components/Logo';
import { t } from '@/i18n/it';

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const next = params.get('next') ?? '/';

  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<{ username?: string; password?: string }>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const health = useQuery({ queryKey: ['health'], queryFn: api.getHealth, retry: false });
  const isFakeLdap = health.data?.ldap_mode === 'fake';

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    const nextErrors: typeof errors = {};
    if (!username.trim()) nextErrors.username = t('login.usernameRequired');
    if (!password) nextErrors.password = t('login.passwordRequired');
    setErrors(nextErrors);
    setFormError(null);
    if (Object.keys(nextErrors).length > 0) return;

    setSubmitting(true);
    try {
      await login(username.trim(), password);
      navigate(next, { replace: true });
    } catch (error) {
      if (error instanceof ApiError) {
        setFormError(error.message);
        const fields = error.fieldErrors;
        setErrors({
          username: fields['username']?.[0],
          password: fields['password']?.[0],
        });
      } else {
        setFormError(t('app.unknownError'));
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="vl-login">
      <aside className="vl-login__aside">
        <Logo className="vl-login__logo" variant="full" tone="dark-bg" size={48} title={t('app.name')} />
        <p className="vl-eyebrow vl-eyebrow--on-dark">{t('app.subtitle')}</p>
        <h2>{t('home.heroTitle')}</h2>
        <p style={{ color: 'var(--color-on-dark-muted)', maxWidth: '44ch' }}>{t('home.heroLead')}</p>
      </aside>

      <div className="vl-login__form">
        <div className="vl-login__inner">
          <p className="vl-eyebrow">{t('app.name')}</p>
          <h1 style={{ marginBottom: 'var(--sp-2)' }}>{t('login.title')}</h1>
          <p className="vl-muted" style={{ marginBottom: 'var(--sp-5)' }}>
            {t('login.lead')}
          </p>

          {isFakeLdap ? (
            <div style={{ marginBottom: 'var(--sp-4)' }}>
              <Alert level="info" icon="info" title={t('login.fakeModeBadge')}>
                {t('login.fakeHint')}
              </Alert>
            </div>
          ) : null}

          {formError ? (
            <div style={{ marginBottom: 'var(--sp-4)' }}>
              <Alert level="danger" icon="alert">
                {formError}
              </Alert>
            </div>
          ) : null}

          <form onSubmit={onSubmit} noValidate className="vl-stack">
            <Field label={t('login.username')} htmlFor="login-username" error={errors.username}>
              <TextInput
                id="login-username"
                name="username"
                autoComplete="username"
                autoFocus
                value={username}
                aria-invalid={errors.username ? true : undefined}
                aria-describedby={errors.username ? 'login-username-error' : undefined}
                onChange={(e) => setUsername(e.target.value)}
              />
            </Field>
            <Field label={t('login.password')} htmlFor="login-password" error={errors.password}>
              <TextInput
                id="login-password"
                name="password"
                type="password"
                autoComplete="current-password"
                value={password}
                aria-invalid={errors.password ? true : undefined}
                aria-describedby={errors.password ? 'login-password-error' : undefined}
                onChange={(e) => setPassword(e.target.value)}
              />
            </Field>
            <Button type="submit" variant="primary" size="lg" block loading={submitting}>
              {submitting ? t('login.submitting') : t('login.submit')}
            </Button>
          </form>

          <p className="vl-subtle" style={{ marginTop: 'var(--sp-5)', display: 'flex', gap: 'var(--sp-2)' }}>
            <Icon name="lock" size={14} />
            {t('login.footerNote')}
          </p>
        </div>
      </div>
    </div>
  );
}
