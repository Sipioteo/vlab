import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { usePermission } from '@/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { SettingField } from '@/components/SettingField';
import { Alert, Button, Card, Skeleton, Tabs } from '@/components/ui';
import { t } from '@/i18n/it';

export function SettingsPage() {
  const canManage = usePermission('settings.manage');
  const queryClient = useQueryClient();
  const { push, pushError } = useToast();
  const [activeGroup, setActiveGroup] = useState<string | null>(null);
  const [dirty, setDirty] = useState<Record<string, unknown>>({});
  const [ldapResult, setLdapResult] = useState<{ ok: boolean; message: string } | null>(null);

  const query = useQuery({ queryKey: ['settings'], queryFn: () => api.getSettings() });

  const groups = query.data?.groups ?? [];
  const currentGroup = activeGroup ?? groups[0]?.key ?? null;

  const settings = useMemo(
    () => (query.data?.data ?? []).filter((setting) => setting.group === currentGroup),
    [query.data, currentGroup],
  );

  const save = useMutation({
    mutationFn: () => api.putSettings(dirty),
    onSuccess: (data) => {
      queryClient.setQueryData(['settings'], data);
      void queryClient.invalidateQueries({ queryKey: ['settings', 'public'] });
      setDirty({});
      push(t('staff.settingsSaved'), 'success');
    },
    onError: pushError,
  });

  const testLdap = useMutation({
    mutationFn: () => api.testLdap({}),
    onSuccess: (result) => setLdapResult(result),
    onError: pushError,
  });

  const dirtyCount = Object.keys(dirty).length;

  if (query.isLoading) return <Skeleton height={320} radius={6} />;

  return (
    <>
      <div className="vl-page-head">
        <div className="vl-row">
          <div style={{ flex: 1 }}>
            <p className="vl-eyebrow">{t('staff.area')}</p>
            <h1>{t('staff.settingsTitle')}</h1>
            <p className="vl-lead">{t('staff.settingsLead')}</p>
          </div>
          {canManage ? (
            <Button
              variant="primary"
              disabled={dirtyCount === 0}
              loading={save.isPending}
              onClick={() => save.mutate()}
            >
              {dirtyCount > 0 ? t('staff.settingsDirty', { n: dirtyCount }) : t('app.save')}
            </Button>
          ) : null}
        </div>
      </div>

      {!canManage ? (
        <div style={{ marginBottom: 'var(--sp-4)' }}>
          <Alert level="info" icon="lock">
            {t('staff.settingsReadOnly')}
          </Alert>
        </div>
      ) : null}

      <Tabs
        tabs={groups.map((group) => ({ id: group.key, label: group.label_it }))}
        active={currentGroup ?? ''}
        onChange={setActiveGroup}
        label={t('staff.settingsTitle')}
      />

      <Card headingLevel={2}>
        <div className="vl-stack" style={{ gap: 'var(--sp-5)' }}>
          {settings.map((setting) => (
            <SettingField
              key={setting.key}
              setting={setting}
              disabled={!canManage}
              value={setting.key in dirty ? dirty[setting.key] : setting.value}
              onChange={(value) => setDirty((prev) => ({ ...prev, [setting.key]: value }))}
            />
          ))}
          {settings.length === 0 ? <p className="vl-subtle">—</p> : null}
        </div>

        {currentGroup === 'ldap' && canManage ? (
          <div style={{ marginTop: 'var(--sp-5)' }}>
            <Button variant="ghost" loading={testLdap.isPending} onClick={() => testLdap.mutate()}>
              {testLdap.isPending ? t('staff.settingsLdapTesting') : t('staff.settingsLdapTest')}
            </Button>
            {ldapResult ? (
              <div style={{ marginTop: 'var(--sp-3)' }}>
                <Alert level={ldapResult.ok ? 'success' : 'danger'} icon={ldapResult.ok ? 'check' : 'alert'}>
                  {ldapResult.message}
                </Alert>
              </div>
            ) : null}
          </div>
        ) : null}
      </Card>
    </>
  );
}
