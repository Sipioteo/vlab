import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { usePermission } from '@/auth/AuthProvider';
import { DonutChart, GroupedBarChart, LineChart, RankedBars, StatCard } from '@/components/charts';
import { Alert, Card, Select, Skeleton } from '@/components/ui';
import { t } from '@/i18n/it';
import { percent } from '@/lib/format';
import type { StatsGranularity } from '@/types/api';

/* Categorical series ramp. Cyan is deliberately absent: it is reserved for
   availability semantics everywhere else in the app (SPEC §12.3). */
const PALETTE = [
  'var(--color-primary)',
  'var(--color-accent)',
  'var(--color-primary-400)',
  'var(--color-success)',
  'var(--color-danger)',
  'var(--color-primary-300)',
];

export function StatsPage() {
  const canViewFull = usePermission('stats.view_full');
  const [granularity, setGranularity] = useState<StatsGranularity>('week');

  const overview = useQuery({ queryKey: ['stats', 'overview'], queryFn: () => api.getStatsOverview() });
  const lateReturns = useQuery({ queryKey: ['stats', 'late'], queryFn: () => api.getLateReturns() });
  const myActivity = useQuery({ queryKey: ['stats', 'mine'], queryFn: () => api.getMyActivity() });

  /* Forbidden for assistants — the query is never issued (SPEC §9.2). */
  const loans = useQuery({
    queryKey: ['stats', 'loans', granularity],
    queryFn: () => api.getLoansOverTime({ granularity }),
    enabled: canViewFull,
  });
  const topProducts = useQuery({
    queryKey: ['stats', 'top'],
    queryFn: () => api.getTopProducts({ limit: 8 }),
    enabled: canViewFull,
  });
  const byCategory = useQuery({
    queryKey: ['stats', 'by-category'],
    queryFn: () => api.getStatsByCategory(),
    enabled: canViewFull,
  });

  const op = overview.data?.operational;
  const totals = overview.data?.totals;
  const inventory = overview.data?.inventory;

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.area')}</p>
        <h1>{t('staff.statsTitle')}</h1>
        <p className="vl-lead">{t('staff.statsLead')}</p>
      </div>

      {!canViewFull ? (
        <div style={{ marginBottom: 'var(--sp-5)' }}>
          <Alert level="info" icon="info">
            {t('staff.statsLimitedNote')}
          </Alert>
        </div>
      ) : null}

      <section className="vl-section" style={{ marginTop: 0 }}>
        <h2 className="vl-sr-only">{t('staff.dashboardTitle')}</h2>
        <div className="vl-stats-grid">
          <StatCard label={t('staff.pendingQueue')} value={op?.orders_pending ?? '—'} tone="accent" />
          <StatCard label={t('staff.todayPickups')} value={op?.pickups_today ?? '—'} />
          <StatCard label={t('staff.todayReturns')} value={op?.returns_today ?? '—'} />
          <StatCard label={t('staff.overdue')} value={op?.orders_overdue ?? '—'} tone="danger" />
        </div>
      </section>

      {canViewFull && totals ? (
        <section className="vl-section">
          <h2 style={{ marginBottom: 'var(--sp-4)' }}>{t('staff.statsLoans')}</h2>
          <div className="vl-stats-grid" style={{ marginBottom: 'var(--sp-4)' }}>
            <StatCard label="Richieste totali" value={totals.orders_total} />
            <StatCard label="Tasso di approvazione" value={percent(totals.approval_rate)} tone="success" />
            <StatCard label="Tasso di ritardo" value={percent(totals.late_rate)} tone="warning" />
            <StatCard label="Durata media (giorni)" value={totals.avg_loan_days} />
          </div>

          <Card
            title={t('staff.statsLoans')}
            headingLevel={2}
            actions={
              <>
                <label className="vl-sr-only" htmlFor="stats-granularity">
                  {t('staff.statsGranularity')}
                </label>
                <Select
                  id="stats-granularity"
                  value={granularity}
                  onChange={(e) => setGranularity(e.target.value as StatsGranularity)}
                  style={{ width: 'auto' }}
                >
                  <option value="day">{t('staff.granularityDay')}</option>
                  <option value="week">{t('staff.granularityWeek')}</option>
                  <option value="month">{t('staff.granularityMonth')}</option>
                </Select>
              </>
            }
          >
            {loans.isLoading ? (
              <Skeleton height={220} radius={6} />
            ) : (
              <GroupedBarChart
                caption={t('staff.statsLoans')}
                data={(loans.data?.series ?? []).map((row) => ({
                  bucket: row.bucket,
                  submitted: row.submitted,
                  approved: row.approved,
                  returned: row.returned,
                }))}
                series={[
                  { key: 'submitted', label: 'Inviate', color: PALETTE[0]! },
                  { key: 'approved', label: 'Approvate', color: PALETTE[1]! },
                  { key: 'returned', label: 'Restituite', color: PALETTE[3]! },
                ]}
              />
            )}
          </Card>
        </section>
      ) : null}

      {canViewFull ? (
        <section className="vl-section">
          <div className="vl-split">
            <Card title={t('staff.statsTopProducts')} headingLevel={2}>
              {topProducts.isLoading ? (
                <Skeleton height={200} radius={6} />
              ) : (
                <RankedBars
                  caption={t('staff.statsTopProducts')}
                  rows={(topProducts.data?.data ?? []).map((row) => ({
                    label: row.name,
                    value: row.orders_count,
                    hint: row.category.name,
                  }))}
                />
              )}
            </Card>
            <Card title={t('staff.statsByCategory')} headingLevel={2}>
              {byCategory.isLoading ? (
                <Skeleton height={200} radius={6} />
              ) : (
                <DonutChart
                  caption={t('staff.statsByCategory')}
                  slices={(byCategory.data?.data ?? []).slice(0, 6).map((row, index) => ({
                    label: row.name,
                    value: row.orders_count,
                    color: PALETTE[index % PALETTE.length]!,
                  }))}
                />
              )}
            </Card>
          </div>
        </section>
      ) : null}

      {canViewFull && inventory ? (
        <section className="vl-section">
          <h2 style={{ marginBottom: 'var(--sp-4)' }}>{t('staff.statsUtilization')}</h2>
          <div className="vl-stats-grid">
            <StatCard label="Unità totali" value={inventory.units_total} />
            <StatCard label="Prestabili" value={inventory.units_available} tone="success" />
            <StatCard label="In manutenzione" value={inventory.units_maintenance} tone="warning" />
            <StatCard label="In prestito ora" value={inventory.units_on_loan_now} />
            <StatCard label="Utilizzo" value={percent(inventory.utilization_now)} tone="accent" />
          </div>
        </section>
      ) : null}

      <section className="vl-section">
        <div className="vl-split">
          <Card title={t('staff.statsLate')} headingLevel={2}>
            {lateReturns.isLoading ? (
              <Skeleton height={160} radius={6} />
            ) : (
              <>
                <div className="vl-stats-grid" style={{ marginBottom: 'var(--sp-4)' }}>
                  <StatCard
                    label={t('staff.statsLate')}
                    value={lateReturns.data?.summary.late_orders ?? 0}
                    tone="warning"
                  />
                  <StatCard
                    label="Ritardo medio"
                    value={lateReturns.data?.summary.avg_late_days ?? 0}
                  />
                  <StatCard
                    label={t('staff.overdue')}
                    value={lateReturns.data?.summary.currently_overdue ?? 0}
                    tone="danger"
                  />
                </div>
                <ul className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)' }}>
                  {(lateReturns.data?.data ?? []).slice(0, 6).map((row) => (
                    <li key={row.order_id} className="vl-row" style={{ justifyContent: 'space-between' }}>
                      <span className="vl-mono">{row.code}</span>
                      <span style={{ flex: 1 }}>{row.user.display_name}</span>
                      <strong>{row.late_days} gg</strong>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </Card>

          <Card title={t('staff.statsMyActivity')} headingLevel={2}>
            {myActivity.isLoading ? (
              <Skeleton height={160} radius={6} />
            ) : (
              <>
                <LineChart
                  caption={t('staff.statsMyActivity')}
                  points={(myActivity.data?.series ?? []).map((row) => ({
                    label: row.bucket,
                    value: row.actions,
                  }))}
                />
                <ul className="vl-stack" style={{ gap: 'var(--sp-1)', fontSize: 'var(--fs-sm)', marginTop: 'var(--sp-3)' }}>
                  {Object.entries(myActivity.data?.counts ?? {}).map(([key, value]) => (
                    <li key={key} className="vl-row" style={{ justifyContent: 'space-between' }}>
                      <span className="vl-subtle">{key}</span>
                      <strong>{value}</strong>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </Card>
        </div>
      </section>
    </>
  );
}
