import { Link, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useEnums } from '@/hooks/useEnums';
import { StatusBadge } from '@/components/domain';
import { Badge, EmptyState, Pagination, SkeletonList } from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate } from '@/lib/format';

const FILTERS = ['pending', 'approved', 'picked_up', 'returned'] as const;

export function MyOrdersPage() {
  const [params, setParams] = useSearchParams();
  const { label } = useEnums();
  const status = params.get('status');
  const page = Number(params.get('page') ?? '1');

  const query = useQuery({
    queryKey: ['orders', { scope: 'mine', status, page }],
    queryFn: () => api.getOrders({ scope: 'mine', status, page, per_page: 10 }),
  });

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(patch)) {
      if (value === null) next.delete(key);
      else next.set(key, value);
    }
    setParams(next);
  };

  const orders = query.data?.data ?? [];

  return (
    <div className="vl-container vl-page">
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.myOrders')}</p>
        <h1>{t('orders.title')}</h1>
        <p className="vl-lead">{t('orders.lead')}</p>
      </div>

      <div className="vl-chips" style={{ marginBottom: 'var(--sp-5)' }}>
        <button
          type="button"
          className="vl-chip"
          aria-pressed={!status}
          onClick={() => update({ status: null, page: null })}
        >
          {t('orders.filterAll')}
        </button>
        {FILTERS.map((value) => (
          <button
            key={value}
            type="button"
            className="vl-chip"
            aria-pressed={status === value}
            onClick={() => update({ status: status === value ? null : value, page: null })}
          >
            {label('order_status', value)}
            {query.data?.summary?.[value] ? (
              <span className="vl-chip__count">{query.data.summary[value]}</span>
            ) : null}
          </button>
        ))}
      </div>

      {query.isLoading ? (
        <SkeletonList rows={4} height={96} />
      ) : orders.length === 0 ? (
        <EmptyState
          icon="clipboard"
          title={t('orders.empty')}
          body={t('orders.emptyBody')}
          action={
            <Link to="/catalogo" className="vl-btn vl-btn--primary">
              {t('cart.goToCatalog')}
            </Link>
          }
        />
      ) : (
        <ul className="vl-stack">
          {orders.map((order) => (
            <li key={order.id}>
              <Link
                to={`/ordini/${order.id}`}
                className="vl-card"
                style={{ display: 'block', padding: 'var(--sp-4)', color: 'inherit' }}
              >
                <div className="vl-row">
                  <span className="vl-mono" style={{ fontWeight: 600 }}>
                    {order.code ?? '—'}
                  </span>
                  <StatusBadge status={order.status} />
                  {order.exceeds_limits ? <Badge tone="pending">{t('orders.exceedsLimits')}</Badge> : null}
                  <span className="vl-spacer" />
                  <span className="vl-subtle">
                    {order.submitted_at
                      ? t('orders.submittedAt', { date: formatDate(order.submitted_at.slice(0, 10)) })
                      : ''}
                  </span>
                  <Icon name="chevron-right" size={16} />
                </div>
                <div className="vl-row" style={{ marginTop: 'var(--sp-3)', gap: 'var(--sp-5)' }}>
                  <span className="vl-subtle">
                    <strong>{t('orders.pickup')}:</strong> {formatDate(order.pickup_date)}{' '}
                    {order.pickup_window ?? ''}
                  </span>
                  <span className="vl-subtle">
                    <strong>{t('orders.return')}:</strong> {formatDate(order.return_date)}{' '}
                    {order.return_window ?? ''}
                  </span>
                  <span className="vl-subtle">{t('orders.itemsCount', { n: order.items_count })}</span>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}

      <Pagination
        page={query.data?.meta?.page ?? 1}
        totalPages={query.data?.meta?.total_pages ?? 1}
        onChange={(next) => update({ page: String(next) })}
      />
    </div>
  );
}
