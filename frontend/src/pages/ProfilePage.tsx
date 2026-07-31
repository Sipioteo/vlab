import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { IcalFeedCard } from '@/components/IcalFeedCard';
import { Badge, Button, Card, Field, TextInput } from '@/components/ui';
import { t } from '@/i18n/it';
import { formatDateTime } from '@/lib/format';

export function ProfilePage() {
  const { user, refresh } = useAuth();
  const { push, pushError } = useToast();
  const [phone, setPhone] = useState('');
  const [course, setCourse] = useState('');

  useEffect(() => {
    setPhone(user?.phone ?? '');
    setCourse(user?.course ?? '');
  }, [user]);

  const regulations = useQuery({
    queryKey: ['regulations', 'profile'],
    queryFn: () => api.getRegulations({ per_page: 50 }),
  });

  const save = useMutation({
    mutationFn: () => api.updateMe({ phone: phone || null, course: course || null }),
    onSuccess: async () => {
      push(t('profile.saved'), 'success');
      await refresh();
    },
    onError: pushError,
  });

  if (!user) return null;

  return (
    <div className="vl-container vl-page">
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.profile')}</p>
        <h1>{t('profile.title')}</h1>
        <p className="vl-lead">{t('profile.lead')}</p>
      </div>

      <div className="vl-split">
        <div className="vl-stack">
          <Card title={t('profile.personalData')} headingLevel={2}>
            <dl className="vl-form-grid vl-form-grid--2" style={{ margin: 0 }}>
              <div>
                <dt className="vl-datacard__label">{t('login.username')}</dt>
                <dd style={{ margin: 0 }} className="vl-mono">
                  {user.ldap_uid}
                </dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('profile.email')}</dt>
                <dd style={{ margin: 0 }}>{user.email}</dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('profile.matricola')}</dt>
                <dd style={{ margin: 0 }} className="vl-mono">
                  {user.matricola ?? '—'}
                </dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('profile.lastLogin')}</dt>
                <dd style={{ margin: 0 }}>{formatDateTime(user.last_login_at)}</dd>
              </div>
            </dl>
            <p className="vl-field__hint" style={{ marginTop: 'var(--sp-4)' }}>
              {t('profile.ldapNote')}
            </p>
          </Card>

          <Card title={t('profile.contact')} headingLevel={2}>
            <form
              className="vl-stack"
              onSubmit={(event: FormEvent) => {
                event.preventDefault();
                save.mutate();
              }}
            >
              <div className="vl-form-grid vl-form-grid--2">
                <Field label={t('profile.phone')} htmlFor="profile-phone" optional>
                  <TextInput
                    id="profile-phone"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    inputMode="tel"
                  />
                </Field>
                <Field label={t('profile.course')} htmlFor="profile-course" optional>
                  <TextInput id="profile-course" value={course} onChange={(e) => setCourse(e.target.value)} />
                </Field>
              </div>
              <div>
                <Button type="submit" variant="primary" loading={save.isPending}>
                  {t('app.save')}
                </Button>
              </div>
            </form>
          </Card>

          <IcalFeedCard />
        </div>

        <div className="vl-stack">
          <Card title={t('profile.role')} headingLevel={2}>
            <div className="vl-row">
              <span className="vl-avatar" aria-hidden="true">
                {user.display_name.slice(0, 2).toUpperCase()}
              </span>
              <div>
                <div style={{ fontWeight: 600 }}>{user.display_name}</div>
                <Badge tone="accent" plain>
                  {user.role_label}
                </Badge>
              </div>
            </div>
          </Card>

          <Card title={t('profile.acceptedRegulations')} headingLevel={2}>
            <ul className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)' }}>
              {(regulations.data?.data ?? [])
                .filter((reg) => reg.acceptance?.accepted)
                .map((reg) => (
                  <li key={reg.id} className="vl-row" style={{ justifyContent: 'space-between' }}>
                    <span>{reg.title}</span>
                    <Badge tone="returned" plain>
                      v{reg.acceptance?.version}
                    </Badge>
                  </li>
                ))}
              {(regulations.data?.data ?? []).every((reg) => !reg.acceptance?.accepted) ? (
                <li className="vl-subtle">{t('regulations.notAccepted')}</li>
              ) : null}
            </ul>
          </Card>
        </div>
      </div>
    </div>
  );
}
