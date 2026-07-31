import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { StatusBadge } from '@/components/domain';
import { StatCard } from '@/components/charts';
import { Card, EmptyState, SkeletonList } from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { addDaysIso, formatDate, todayIso } from '@/lib/format';

export function StaffDashboardPage() {
  const overview = useQuery({ queryKey: ['stats', 'overview'], queryFn: () => api.getStatsOverview() });
  const pending = useQuery({
    queryKey: ['orders', { status: 'pending' }],
    queryFn: () => api.getOrders({ status: 'pending', per_page: 8, sort: 'submitted_at', order: 'asc' }),
  });
  const calendar = useQuery({
    queryKey: ['orders-calendar', 'dashboard'],
    queryFn: () => api.getStaffCalendar({ from: todayIso(), to: addDaysIso(todayIso(), 7) }),
  });

  const op = overview.data?.operational;
  const today = calendar.data?.days.find((day) => day.date === todayIso());

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.area')}</p>
        <h1>{t('staff.dashboardTitle')}</h1>
        <p className="vl-lead">{t('staff.dashboardLead')}</p>
      </div>

      <div className="vl-stats-grid" style={{ marginBottom: 'var(--sp-5)' }}>
        <StatCard label={t('staff.pendingQueue')} value={op?.orders_pending ?? '—'} tone="accent" />
        <StatCard label={t('staff.todayPickups')} value={op?.pickups_today ?? '—'} />
        <StatCard label={t('staff.todayReturns')} value={op?.returns_today ?? '—'} />
        <StatCard label={t('staff.overdue')} value={op?.orders_overdue ?? '—'} tone="danger" />
        <StatCard label={t('staff.returnsNext7')} value={op?.returns_next_7_days ?? '—'} />
      </div>

      <div className="vl-split">
        <Card
          title={t('staff.pendingQueue')}
          headingLevel={2}
          actions={
            <Link to="/gestione/ordini" className="vl-btn vl-btn--ghost vl-btn--sm">
              {t('staff.ordersQueue')}
              <Icon name="chevron-right" size={14} />
            </Link>
          }
        >
          {pending.isLoading ? (
            <SkeletonList rows={4} height={54} />
          ) : (pending.data?.data ?? []).length === 0 ? (
            <EmptyState icon="check" title={t('staff.noPending')} />
          ) : (
            <ul className="vl-stack" style={{ gap: 'var(--sp-2)' }}>
              {(pending.data?.data ?? []).map((order) => (
                <li key={order.id}>
                  <Link
                    to={`/gestione/ordini/${order.id}`}
                    className="vl-row"
                    style={{
                      padding: 'var(--sp-3)',
                      border: '1px solid var(--color-line)',
                      borderRadius: 'var(--radius-sm)',
                      color: 'inherit',
                    }}
                  >
                    <span className="vl-mono" style={{ fontWeight: 600 }}>
                      {order.code}
                    </span>
                    <span style={{ flex: 1, minWidth: 0 }}>{order.user.display_name}</span>
                    <span className="vl-subtle">{formatDate(order.pickup_date)}</span>
                    <StatusBadge status={order.status} />
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card title={t('staff.calendarTitle')} headingLevel={2}>
          <div className="vl-stack" style={{ gap: 'var(--sp-4)' }}>
            <div>
              <p className="vl-eyebrow">{t('staff.todayPickups')}</p>
              {today && today.pickups.length > 0 ? (
                <ul className="vl-stack" style={{ gap: 'var(--sp-1)', fontSize: 'var(--fs-sm)' }}>
                  {today.pickups.map((entry) => (
                    <li key={`p-${entry.order_id}`} className="vl-row">
                      <span className="vl-mono">{entry.time ?? '—'}</span>
                      <Link to={`/gestione/ordini/${entry.order_id}`}>{entry.user_display_name}</Link>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="vl-subtle">—</p>
              )}
            </div>
            <div>
              <p className="vl-eyebrow">{t('staff.todayReturns')}</p>
              {today && today.returns.length > 0 ? (
                <ul className="vl-stack" style={{ gap: 'var(--sp-1)', fontSize: 'var(--fs-sm)' }}>
                  {today.returns.map((entry) => (
                    <li key={`r-${entry.order_id}`} className="vl-row">
                      <span className="vl-mono">{entry.time ?? '—'}</span>
                      <Link to={`/gestione/ordini/${entry.order_id}`}>{entry.user_display_name}</Link>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="vl-subtle">—</p>
              )}
            </div>
          </div>
        </Card>
      </div>
    </>
  );
}
