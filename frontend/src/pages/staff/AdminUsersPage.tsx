import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { usePermission } from '@/auth/AuthProvider';
import { useEnums } from '@/hooks/useEnums';
import { useToast } from '@/components/Toast';
import { StatusBadge } from '@/components/domain';
import {
  Badge,
  Button,
  Card,
  Field,
  Modal,
  Pagination,
  SearchInput,
  Select,
  Skeleton,
  SkeletonList,
  Switch,
  TextArea,
} from '@/components/ui';
import { t } from '@/i18n/it';
import { formatDate, formatDateTime } from '@/lib/format';
import type { User } from '@/types/api';

export function AdminUsersPage() {
  const [params, setParams] = useSearchParams();
  const queryClient = useQueryClient();
  const canManage = usePermission('users.manage');
  const { list } = useEnums();
  const { push, pushError } = useToast();
  const [editing, setEditing] = useState<User | null>(null);
  const [role, setRole] = useState('student');
  const [isActive, setIsActive] = useState(true);
  const [notes, setNotes] = useState('');

  const q = params.get('q') ?? '';
  const page = Number(params.get('page') ?? '1');

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(patch)) {
      if (!value) next.delete(key);
      else next.set(key, value);
    }
    if (!('page' in patch)) next.delete('page');
    setParams(next);
  };

  const query = useQuery({
    queryKey: ['users', { q, page }],
    queryFn: () => api.getUsers({ q: q || null, page, per_page: 25 }),
  });

  const save = useMutation({
    mutationFn: (user: User) =>
      api.updateUser(user.id, { role, is_active: isActive, notes: notes || null }),
    onSuccess: () => {
      push(t('staff.userSaved'), 'success');
      setEditing(null);
      void queryClient.invalidateQueries({ queryKey: ['users'] });
    },
    onError: pushError,
  });

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.area')}</p>
        <h1>{t('staff.usersTitle')}</h1>
        <p className="vl-lead">{t('staff.usersLead')}</p>
      </div>

      <div className="vl-toolbar" style={{ position: 'static' }}>
        <div className="vl-toolbar__search">
          <SearchInput
            value={q}
            onChange={(value) => update({ q: value || null })}
            label={t('app.search')}
            placeholder={t('staff.usersLead')}
          />
        </div>
      </div>

      {query.isLoading ? (
        <SkeletonList rows={8} height={44} />
      ) : (
        <>
          <div className="vl-table-wrap">
            <table className="vl-table">
              <caption className="vl-sr-only">{t('staff.usersTitle')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('staff.student')}</th>
                  <th scope="col">{t('login.username')}</th>
                  <th scope="col">{t('staff.userRole')}</th>
                  <th scope="col">{t('nav.myOrders')}</th>
                  <th scope="col">{t('profile.lastLogin')}</th>
                  {canManage ? (
                    <th scope="col">
                      <span className="vl-sr-only">{t('app.edit')}</span>
                    </th>
                  ) : null}
                </tr>
              </thead>
              <tbody>
                {(query.data?.data ?? []).map((user) => (
                  <tr key={user.id}>
                    <td>
                      <Link to={`/gestione/utenti/${user.id}`}>{user.display_name}</Link>
                      <div className="vl-subtle">{user.email}</div>
                    </td>
                    <td className="vl-mono">{user.ldap_uid}</td>
                    <td>
                      <Badge tone="neutral" plain>
                        {user.role_label}
                      </Badge>
                    </td>
                    <td>{user.orders_count ?? '—'}</td>
                    <td>{formatDate(user.last_login_at?.slice(0, 10) ?? null)}</td>
                    {canManage ? (
                      <td>
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => {
                            setEditing(user);
                            setRole(user.role);
                            setIsActive(user.is_active);
                            setNotes(user.notes ?? '');
                          }}
                        >
                          {t('app.edit')}
                        </Button>
                      </td>
                    ) : null}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination
            page={query.data?.meta?.page ?? 1}
            totalPages={query.data?.meta?.total_pages ?? 1}
            onChange={(next) => update({ page: String(next) })}
          />
        </>
      )}

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.display_name ?? ''}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              onClick={() => editing && save.mutate(editing)}
            >
              {t('app.save')}
            </Button>
          </>
        }
      >
        <div className="vl-stack">
          <Field label={t('staff.userRole')} htmlFor="user-role">
            <Select id="user-role" value={role} onChange={(e) => setRole(e.target.value)}>
              {list('role').map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </Field>
          <Switch checked={isActive} onChange={setIsActive} label={t('staff.userActive')} />
          <Field label={t('staff.userNotes')} htmlFor="user-notes" optional>
            <TextArea id="user-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </Field>
        </div>
      </Modal>
    </>
  );
}

export function UserDetailPage() {
  const { id } = useParams();
  const userId = Number(id);

  const user = useQuery({
    queryKey: ['user', userId],
    queryFn: () => api.getUser(userId),
    enabled: Number.isFinite(userId),
  });
  const orders = useQuery({
    queryKey: ['user-orders', userId],
    queryFn: () => api.getUserOrders(userId, { per_page: 20 }),
    enabled: Number.isFinite(userId),
  });

  if (user.isLoading) return <Skeleton height={280} radius={6} />;
  if (!user.data) return <h1>{t('errors.notFoundTitle')}</h1>;

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.users')}</p>
        <h1>{user.data.display_name}</h1>
        <div className="vl-row" style={{ marginTop: 'var(--sp-3)' }}>
          <Badge tone="accent" plain>
            {user.data.role_label}
          </Badge>
          <span className="vl-subtle vl-mono">{user.data.ldap_uid}</span>
          <span className="vl-subtle">{user.data.email}</span>
        </div>
      </div>

      <div className="vl-split">
        <Card title={t('nav.myOrders')} headingLevel={2}>
          <div className="vl-table-wrap">
            <table className="vl-table">
              <thead>
                <tr>
                  <th scope="col">{t('orders.code')}</th>
                  <th scope="col">{t('orders.pickup')}</th>
                  <th scope="col">{t('orders.status')}</th>
                </tr>
              </thead>
              <tbody>
                {(orders.data?.data ?? []).map((order) => (
                  <tr key={order.id}>
                    <td>
                      <Link to={`/gestione/ordini/${order.id}`} className="vl-mono">
                        {order.code}
                      </Link>
                    </td>
                    <td>{formatDate(order.pickup_date)}</td>
                    <td>
                      <StatusBadge status={order.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        <Card title={t('profile.personalData')} headingLevel={2}>
          <dl className="vl-stack" style={{ gap: 'var(--sp-3)', margin: 0, fontSize: 'var(--fs-sm)' }}>
            <div>
              <dt className="vl-datacard__label">{t('profile.matricola')}</dt>
              <dd style={{ margin: 0 }} className="vl-mono">
                {user.data.matricola ?? '—'}
              </dd>
            </div>
            <div>
              <dt className="vl-datacard__label">{t('profile.course')}</dt>
              <dd style={{ margin: 0 }}>{user.data.course ?? '—'}</dd>
            </div>
            <div>
              <dt className="vl-datacard__label">{t('profile.lastLogin')}</dt>
              <dd style={{ margin: 0 }}>{formatDateTime(user.data.last_login_at)}</dd>
            </div>
            <div>
              <dt className="vl-datacard__label">{t('staff.userNotes')}</dt>
              <dd style={{ margin: 0 }}>{user.data.notes ?? '—'}</dd>
            </div>
          </dl>
        </Card>
      </div>
    </>
  );
}
