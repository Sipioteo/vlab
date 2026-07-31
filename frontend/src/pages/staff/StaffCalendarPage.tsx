import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { StatCard } from '@/components/charts';
import { Button, Card, Skeleton } from '@/components/ui';
import { StatusBadge } from '@/components/domain';
import { t } from '@/i18n/it';
import { addDaysIso, formatDayLong, formatMonthLabel, todayIso } from '@/lib/format';

export function StaffCalendarPage() {
  const [monthStart, setMonthStart] = useState(() => `${todayIso().slice(0, 7)}-01`);
  const monthEnd = addDaysIso(monthStart, 31);

  const calendar = useQuery({
    queryKey: ['orders-calendar', monthStart],
    queryFn: () => api.getStaffCalendar({ from: monthStart, to: monthEnd }),
  });

  const shiftMonth = (delta: number) => {
    const date = new Date(`${monthStart}T00:00:00`);
    date.setMonth(date.getMonth() + delta);
    setMonthStart(`${date.toISOString().slice(0, 7)}-01`);
  };

  const days = (calendar.data?.days ?? []).filter(
    (day) => day.pickups.length + day.returns.length + day.overdue.length > 0,
  );

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.area')}</p>
        <h1>{t('staff.calendarTitle')}</h1>
        <p className="vl-lead">{t('staff.calendarLead')}</p>
      </div>

      <div className="vl-row" style={{ marginBottom: 'var(--sp-4)' }}>
        <Button size="sm" icon="chevron-left" onClick={() => shiftMonth(-1)}>
          {t('app.back')}
        </Button>
        <strong style={{ textTransform: 'capitalize' }}>{formatMonthLabel(monthStart)}</strong>
        <Button size="sm" onClick={() => shiftMonth(1)}>
          {t('pagination.next')}
        </Button>
      </div>

      <div className="vl-stats-grid" style={{ marginBottom: 'var(--sp-5)' }}>
        <StatCard label={t('orders.pickup')} value={calendar.data?.totals.pickups ?? '—'} />
        <StatCard label={t('orders.return')} value={calendar.data?.totals.returns ?? '—'} />
        <StatCard label={t('staff.overdue')} value={calendar.data?.totals.overdue ?? '—'} tone="danger" />
      </div>

      {calendar.isLoading ? (
        <Skeleton height={280} radius={6} />
      ) : (
        <div className="vl-stack">
          {days.map((day) => (
            <Card key={day.date} title={formatDayLong(day.date)} headingLevel={2}>
              <div className="vl-form-grid vl-form-grid--2">
                <div>
                  <p className="vl-eyebrow">{t('orders.pickup')}</p>
                  <ul className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)' }}>
                    {day.pickups.map((entry) => (
                      <li key={`p${entry.order_id}`} className="vl-row">
                        <span className="vl-mono">{entry.time ?? '—'}</span>
                        <Link to={`/gestione/ordini/${entry.order_id}`} style={{ flex: 1 }}>
                          {entry.code} · {entry.user_display_name}
                        </Link>
                        <StatusBadge status={entry.status} />
                      </li>
                    ))}
                    {day.pickups.length === 0 ? <li className="vl-subtle">—</li> : null}
                  </ul>
                </div>
                <div>
                  <p className="vl-eyebrow">{t('orders.return')}</p>
                  <ul className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)' }}>
                    {day.returns.map((entry) => (
                      <li key={`r${entry.order_id}`} className="vl-row">
                        <span className="vl-mono">{entry.time ?? '—'}</span>
                        <Link to={`/gestione/ordini/${entry.order_id}`} style={{ flex: 1 }}>
                          {entry.code} · {entry.user_display_name}
                        </Link>
                        <StatusBadge status={entry.status} />
                      </li>
                    ))}
                    {day.returns.length === 0 ? <li className="vl-subtle">—</li> : null}
                  </ul>
                </div>
              </div>
            </Card>
          ))}
          {days.length === 0 ? <p className="vl-subtle">{t('staff.noPending')}</p> : null}
        </div>
      )}
    </>
  );
}
