import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useEnums } from '@/hooks/useEnums';
import { useToast } from '@/components/Toast';
import { OrderActions, StatusBadge } from '@/components/domain';
import {
  Badge,
  Button,
  EmptyState,
  Field,
  Modal,
  Pagination,
  SearchInput,
  Select,
  SkeletonList,
  TextArea,
  TextInput,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate } from '@/lib/format';
import type { OrderAction, OrderSummary } from '@/types/api';

const STATUS_FILTERS = ['pending', 'approved', 'picked_up', 'overdue', 'returned', 'returned_late'];

export function StaffOrdersPage() {
  const [params, setParams] = useSearchParams();
  const queryClient = useQueryClient();
  const { label } = useEnums();
  const { push, pushError } = useToast();

  const [drawerOrderId, setDrawerOrderId] = useState<number | null>(null);
  const [rejectOrder, setRejectOrder] = useState<OrderSummary | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [busy, setBusy] = useState<OrderAction | null>(null);

  const status = params.get('status');
  const q = params.get('q') ?? '';
  const from = params.get('from') ?? '';
  const to = params.get('to') ?? '';
  const lateOnly = params.get('late_only') === 'true';
  const page = Number(params.get('page') ?? '1');

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(patch)) {
      if (value === null || value === '') next.delete(key);
      else next.set(key, value);
    }
    if (!('page' in patch)) next.delete('page');
    setParams(next);
  };

  const listKey = ['orders', { scope: 'all', status, q, from, to, lateOnly, page }] as const;
  const query = useQuery({
    queryKey: listKey,
    queryFn: () =>
      api.getOrders({
        scope: 'all',
        status,
        q: q || null,
        from: from || null,
        to: to || null,
        late_only: lateOnly ? 'true' : null,
        page,
        per_page: 20,
      }),
  });

  const detail = useQuery({
    queryKey: ['order', drawerOrderId],
    queryFn: () => api.getOrder(drawerOrderId!),
    enabled: drawerOrderId !== null,
  });

  const transition = useMutation({
    mutationFn: ({ id, action, body }: { id: number; action: string; body?: Record<string, unknown> }) =>
      api.orderAction(id, action === 'mark_no_show' ? 'no-show' : action, body ?? {}),
    onSuccess: (order) => {
      queryClient.setQueryData(['order', order.id], order);
      queryClient.setQueryData<typeof query.data>(listKey, (prev) =>
        prev
          ? {
              ...prev,
              data: prev.data.map((row) =>
                row.id === order.id ? { ...row, status: order.status, status_label: order.status_label } : row,
              ),
            }
          : prev,
      );
      setBusy(null);
      setRejectOrder(null);
      setRejectReason('');
      push(t('staff.approved'), 'success');
    },
    onError: (error) => {
      setBusy(null);
      pushError(error);
    },
  });

  function runAction(order: { id: number }, action: OrderAction) {
    if (action === 'reject') {
      const summary = (query.data?.data ?? []).find((row) => row.id === order.id) ?? null;
      setRejectOrder(summary ?? ({ id: order.id, code: null } as unknown as OrderSummary));
      return;
    }
    setBusy(action);
    transition.mutate({ id: order.id, action });
  }

  const orders = query.data?.data ?? [];

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.area')}</p>
        <h1>{t('staff.ordersTitle')}</h1>
        <p className="vl-lead">{t('staff.ordersLead')}</p>
      </div>

      <div className="vl-toolbar" style={{ position: 'static' }}>
        <div className="vl-toolbar__search">
          <SearchInput
            value={q}
            onChange={(value) => update({ q: value || null })}
            label={t('app.search')}
            placeholder={t('staff.filterQuery')}
          />
        </div>
        <label className="vl-sr-only" htmlFor="staff-status">
          {t('staff.filterStatus')}
        </label>
        <Select
          id="staff-status"
          value={status ?? ''}
          onChange={(e) => update({ status: e.target.value || null })}
          style={{ width: 'auto', minWidth: 150 }}
        >
          <option value="">{t('app.all')}</option>
          {STATUS_FILTERS.map((value) => (
            <option key={value} value={value}>
              {label('order_status', value)}
            </option>
          ))}
        </Select>
        <label className="vl-sr-only" htmlFor="staff-from">
          {t('staff.filterFrom')}
        </label>
        <TextInput
          id="staff-from"
          type="date"
          value={from}
          onChange={(e) => update({ from: e.target.value || null })}
          style={{ width: 'auto' }}
        />
        <label className="vl-sr-only" htmlFor="staff-to">
          {t('staff.filterTo')}
        </label>
        <TextInput
          id="staff-to"
          type="date"
          value={to}
          onChange={(e) => update({ to: e.target.value || null })}
          style={{ width: 'auto' }}
        />
        <Button
          size="sm"
          variant={lateOnly ? 'secondary' : 'ghost'}
          aria-pressed={lateOnly}
          onClick={() => update({ late_only: lateOnly ? null : 'true' })}
        >
          {t('staff.filterLate')}
        </Button>
      </div>

      {query.isLoading ? (
        <SkeletonList rows={6} height={48} />
      ) : orders.length === 0 ? (
        <EmptyState icon="clipboard" title={t('orders.empty')} body={t('staff.noPending')} />
      ) : (
        <>
          <div className="vl-table-wrap">
            <table className="vl-table">
              <caption className="vl-sr-only">{t('staff.ordersTitle')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('orders.code')}</th>
                  <th scope="col">{t('staff.student')}</th>
                  <th scope="col">{t('orders.pickup')}</th>
                  <th scope="col">{t('orders.return')}</th>
                  <th scope="col">{t('orders.status')}</th>
                  <th scope="col">
                    <span className="vl-sr-only">{t('app.details')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {orders.map((order) => (
                  <tr key={order.id}>
                    <td>
                      <Link to={`/gestione/ordini/${order.id}`} className="vl-mono">
                        {order.code ?? `#${order.id}`}
                      </Link>
                      {order.exceeds_limits ? (
                        <div>
                          <Badge tone="pending" plain>
                            {t('orders.exceedsLimits')}
                          </Badge>
                        </div>
                      ) : null}
                    </td>
                    <td>{order.user.display_name}</td>
                    <td>
                      {formatDate(order.pickup_date)} <span className="vl-subtle">{order.pickup_time}</span>
                    </td>
                    <td>
                      {formatDate(order.return_date)} <span className="vl-subtle">{order.return_time}</span>
                    </td>
                    <td>
                      <StatusBadge status={order.status} />
                    </td>
                    <td>
                      <Button size="sm" variant="ghost" onClick={() => setDrawerOrderId(order.id)}>
                        {t('app.details')}
                        <Icon name="chevron-right" size={14} />
                      </Button>
                    </td>
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

      {/* Detail drawer: actions come exclusively from allowed_actions */}
      <Modal
        open={drawerOrderId !== null}
        onClose={() => setDrawerOrderId(null)}
        title={
          detail.data?.code
            ? t('orders.detailTitle', { code: detail.data.code })
            : t('app.details')
        }
        wide
        footer={
          detail.data ? (
            <>
              <Link to={`/gestione/ordini/${detail.data.id}`} className="vl-btn vl-btn--ghost">
                {t('app.details')}
              </Link>
              <OrderActions
                actions={detail.data.allowed_actions}
                busyAction={busy}
                onAction={(action) => runAction(detail.data!, action)}
              />
            </>
          ) : null
        }
      >
        {detail.isLoading || !detail.data ? (
          <SkeletonList rows={3} height={40} />
        ) : (
          <div className="vl-stack">
            <div className="vl-row">
              <StatusBadge status={detail.data.status} />
              <span className="vl-subtle">{detail.data.user.display_name}</span>
            </div>
            <dl className="vl-form-grid vl-form-grid--2" style={{ margin: 0 }}>
              <div>
                <dt className="vl-datacard__label">{t('orders.pickup')}</dt>
                <dd style={{ margin: 0 }}>
                  {formatDate(detail.data.pickup_date)} {detail.data.pickup_time}
                </dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('orders.return')}</dt>
                <dd style={{ margin: 0 }}>
                  {formatDate(detail.data.return_date)} {detail.data.return_time}
                </dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('checkout.subject')}</dt>
                <dd style={{ margin: 0 }}>{detail.data.subject ?? '—'}</dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('checkout.motivation')}</dt>
                <dd style={{ margin: 0 }}>{detail.data.motivation ?? '—'}</dd>
              </div>
            </dl>
            <ul className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)' }}>
              {detail.data.items.map((item) => (
                <li key={item.id} className="vl-row" style={{ justifyContent: 'space-between' }}>
                  <span>{item.product_name_snapshot}</span>
                  <strong>×{item.quantity}</strong>
                </li>
              ))}
            </ul>
          </div>
        )}
      </Modal>

      <Modal
        open={rejectOrder !== null}
        onClose={() => setRejectOrder(null)}
        title={t('staff.rejectTitle', { code: rejectOrder?.code ?? '' })}
        footer={
          <>
            <Button variant="ghost" onClick={() => setRejectOrder(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="danger"
              disabled={rejectReason.trim().length === 0}
              loading={transition.isPending}
              onClick={() =>
                rejectOrder &&
                transition.mutate({ id: rejectOrder.id, action: 'reject', body: { reason: rejectReason } })
              }
            >
              {t('actions.reject')}
            </Button>
          </>
        }
      >
        <Field
          label={t('staff.rejectReason')}
          htmlFor="reject-reason"
          error={rejectReason.trim().length === 0 ? t('staff.rejectReasonRequired') : undefined}
        >
          <TextArea
            id="reject-reason"
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
          />
        </Field>
      </Modal>
    </>
  );
}
