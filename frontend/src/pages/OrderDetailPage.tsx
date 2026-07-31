import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { LimitWarningList, OrderActions, OrderStatusTimeline, StatusBadge } from '@/components/domain';
import { Alert, Card, ConfirmDialog, Field, ProductImage, Skeleton, TextArea } from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate, formatDateTime } from '@/lib/format';
import { canPrintOrderForm, orderFormUrl } from '@/lib/orderForm';
import type { OrderAction } from '@/types/api';

export function OrderDetailPage() {
  const { id } = useParams();
  const orderId = Number(id);
  const queryClient = useQueryClient();
  const { permissions } = useAuth();
  const { push, pushError } = useToast();
  const [cancelOpen, setCancelOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');

  const query = useQuery({
    queryKey: ['order', orderId],
    queryFn: () => api.getOrder(orderId),
    enabled: Number.isFinite(orderId),
  });

  const events = useQuery({
    queryKey: ['order-events', orderId],
    queryFn: () => api.getOrderEvents(orderId),
    enabled: Number.isFinite(orderId),
  });

  const cancelMutation = useMutation({
    mutationFn: () => api.orderAction(orderId, 'cancel', { reason: cancelReason || null }),
    onSuccess: (order) => {
      queryClient.setQueryData(['order', orderId], order);
      void queryClient.invalidateQueries({ queryKey: ['orders'] });
      void queryClient.invalidateQueries({ queryKey: ['order-events', orderId] });
      setCancelOpen(false);
      push(t('orders.cancelled'), 'success');
    },
    onError: pushError,
  });

  if (query.isLoading) {
    return (
      <div className="vl-container vl-page">
        <Skeleton height={32} width="40%" />
        <div style={{ marginTop: 'var(--sp-5)' }}>
          <Skeleton height={260} radius={6} />
        </div>
      </div>
    );
  }

  const order = query.data;
  if (query.isError || !order) {
    return (
      <div className="vl-container vl-page">
        <h1>{t('orders.notFound')}</h1>
        <Link to="/ordini" className="vl-btn vl-btn--ghost" style={{ marginTop: 'var(--sp-4)' }}>
          {t('nav.myOrders')}
        </Link>
      </div>
    );
  }

  const timelineEvents = events.data?.data ?? order.events ?? [];
  const isConfirmed = order.status === 'approved';

  function handleAction(action: OrderAction) {
    if (action === 'cancel') {
      setCancelOpen(true);
      return;
    }
    /* Staff transitions live in the staff detail page; here we only expose cancel. */
    push(t('app.unknownError'), 'info');
  }

  return (
    <div className="vl-container vl-page">
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.myOrders')}</p>
        <h1>{t('orders.detailTitle', { code: order.code ?? `#${order.id}` })}</h1>
        <div className="vl-row" style={{ marginTop: 'var(--sp-3)' }}>
          <StatusBadge status={order.status} />
          {order.exceeds_limits ? (
            <span className="vl-badge vl-badge--pending">{t('orders.exceedsLimits')}</span>
          ) : null}
          {order.is_late && order.late_days ? (
            <span className="vl-badge vl-badge--overdue">
              {t('orders.lateBy', { n: order.late_days })}
            </span>
          ) : null}
        </div>
      </div>

      {isConfirmed ? (
        <div style={{ marginBottom: 'var(--sp-5)' }}>
          <Alert level="success" icon="check" title={t('orders.confirmedTitle')}>
            {t('orders.confirmedBody', {
              date: formatDate(order.pickup_date),
              time: order.pickup_time ?? '',
            })}
          </Alert>
        </div>
      ) : order.status === 'pending' ? (
        <div style={{ marginBottom: 'var(--sp-5)' }}>
          <Alert level="info" icon="clock" title={t('orders.pendingTitle')}>
            {t('orders.pendingBody')} {t('orders.frozenNote')}
          </Alert>
        </div>
      ) : null}

      {order.rejection_reason ? (
        <div style={{ marginBottom: 'var(--sp-5)' }}>
          <Alert level="danger" icon="alert" title={t('orders.rejectionReason')}>
            {order.rejection_reason}
          </Alert>
        </div>
      ) : null}

      <div className="vl-split">
        <div className="vl-stack">
          <Card title={t('orders.itemsTitle')} headingLevel={2}>
            <ul className="vl-stack">
              {order.items.map((item) => (
                <li key={item.id} className="vl-row" style={{ gap: 'var(--sp-4)' }}>
                  <div style={{ width: 64, flex: 'none' }}>
                    <ProductImage src={item.product.image_url} alt="" />
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>
                      <Link to={`/prodotto/${item.product.slug}`}>{item.product_name_snapshot}</Link>
                    </div>
                    <div className="vl-subtle">{item.product_brand_snapshot ?? ''}</div>
                    {item.assigned_units && item.assigned_units.length > 0 ? (
                      <div className="vl-subtle vl-mono">
                        {item.assigned_units.map((unit) => unit.unit_label).join(' · ')}
                      </div>
                    ) : null}
                  </div>
                  <strong>×{item.quantity}</strong>
                </li>
              ))}
            </ul>
          </Card>

          <Card title={t('orders.infoTitle')} headingLevel={2}>
            <dl className="vl-stack" style={{ gap: 'var(--sp-3)', margin: 0, fontSize: 'var(--fs-sm)' }}>
              <div>
                <dt className="vl-datacard__label">{t('checkout.subject')}</dt>
                <dd style={{ margin: 0 }}>{order.subject ?? '—'}</dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('checkout.motivation')}</dt>
                <dd style={{ margin: 0 }}>{order.motivation ?? '—'}</dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('orders.professorLabel')}</dt>
                <dd style={{ margin: 0 }}>{order.professor ?? '—'}</dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('checkout.notes')}</dt>
                <dd style={{ margin: 0 }}>{order.notes ?? '—'}</dd>
              </div>
              {permissions['orders.manage'] && order.staff_notes !== undefined ? (
                <div>
                  <dt className="vl-datacard__label">{t('orders.staffNotes')}</dt>
                  <dd style={{ margin: 0 }}>{order.staff_notes ?? '—'}</dd>
                </div>
              ) : null}
            </dl>
          </Card>

          {order.limit_violations.length > 0 ? (
            <LimitWarningList violations={order.limit_violations} />
          ) : null}
        </div>

        <div className="vl-stack">
          <Card title={t('cart.dates')} headingLevel={2}>
            <dl className="vl-stack" style={{ gap: 'var(--sp-3)', margin: 0, fontSize: 'var(--fs-sm)' }}>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('orders.pickup')}</dt>
                <dd style={{ margin: 0 }}>
                  {formatDate(order.pickup_date)} {order.pickup_time ?? ''}
                </dd>
              </div>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('orders.return')}</dt>
                <dd style={{ margin: 0 }}>
                  {formatDate(order.return_date)} {order.return_time ?? ''}
                </dd>
              </div>
              {order.picked_up_at ? (
                <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                  <dt className="vl-subtle">{t('actions.pickup')}</dt>
                  <dd style={{ margin: 0 }}>{formatDateTime(order.picked_up_at)}</dd>
                </div>
              ) : null}
              {order.returned_at ? (
                <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                  <dt className="vl-subtle">{t('actions.return')}</dt>
                  <dd style={{ margin: 0 }}>{formatDateTime(order.returned_at)}</dd>
                </div>
              ) : null}
            </dl>
            <div style={{ marginTop: 'var(--sp-4)' }}>
              <OrderActions
                actions={order.allowed_actions.filter((a) => a === 'cancel')}
                onAction={handleAction}
                size="sm"
              />
              {canPrintOrderForm(order.status) ? (
                <a
                  href={orderFormUrl(order.id)}
                  target="_blank"
                  rel="noreferrer"
                  className="vl-btn vl-btn--ghost vl-btn--sm"
                  style={{ marginTop: 'var(--sp-2)' }}
                  title={t('orderForm.hint')}
                >
                  <Icon name="file" size={14} />
                  {t('orderForm.print')}
                </a>
              ) : null}
              {permissions['orders.manage'] ? (
                <Link
                  to={`/gestione/ordini/${order.id}`}
                  className="vl-btn vl-btn--ghost vl-btn--sm"
                  style={{ marginTop: 'var(--sp-2)' }}
                >
                  <Icon name="settings" size={14} />
                  {t('staff.area')}
                </Link>
              ) : null}
            </div>
          </Card>

          <Card title={t('orders.timeline')} headingLevel={2}>
            {timelineEvents.length === 0 ? (
              <p className="vl-subtle">—</p>
            ) : (
              <OrderStatusTimeline events={timelineEvents} />
            )}
          </Card>
        </div>
      </div>

      <ConfirmDialog
        open={cancelOpen}
        title={t('orders.cancelTitle')}
        body={t('orders.cancelBody')}
        danger
        confirmLabel={t('actions.cancel')}
        loading={cancelMutation.isPending}
        onCancel={() => setCancelOpen(false)}
        onConfirm={() => cancelMutation.mutate()}
      >
        <Field label={t('orders.cancelReason')} htmlFor="cancel-reason" optional>
          <TextArea
            id="cancel-reason"
            value={cancelReason}
            onChange={(e) => setCancelReason(e.target.value)}
          />
        </Field>
      </ConfirmDialog>
    </div>
  );
}
