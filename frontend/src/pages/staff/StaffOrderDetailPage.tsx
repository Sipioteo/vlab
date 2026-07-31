import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useEnums } from '@/hooks/useEnums';
import { useToast } from '@/components/Toast';
import { LimitWarningList, OrderActions, OrderStatusTimeline, StatusBadge } from '@/components/domain';
import {
  Alert,
  Badge,
  Button,
  Card,
  Field,
  Modal,
  Select,
  Skeleton,
  TextArea,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate, formatDateTime } from '@/lib/format';
import { canPrintOrderForm, orderFormUrl } from '@/lib/orderForm';
import type { ConditionValue, OrderAction, ProductUnit } from '@/types/api';

type DialogKind = 'approve' | 'reject' | 'pickup' | 'return' | 'mark_no_show' | 'note' | 'reopen' | null;

export function StaffOrderDetailPage() {
  const { id } = useParams();
  const orderId = Number(id);
  const queryClient = useQueryClient();
  const { list, label } = useEnums();
  const { push, pushError } = useToast();

  const [dialog, setDialog] = useState<DialogKind>(null);
  const [comment, setComment] = useState('');
  const [reason, setReason] = useState('');
  const [staffNotes, setStaffNotes] = useState('');
  const [assignments, setAssignments] = useState<Record<number, number[]>>({});
  const [conditions, setConditions] = useState<Record<number, ConditionValue>>({});
  const [logTitle, setLogTitle] = useState('');
  const [logProductId, setLogProductId] = useState<number | null>(null);

  const query = useQuery({
    queryKey: ['order', orderId],
    queryFn: () => api.getOrder(orderId),
    enabled: Number.isFinite(orderId),
  });
  const order = query.data;

  const unitQueries = useQueries({
    queries: (order?.items ?? []).map((item) => ({
      queryKey: ['product-units', item.product_id],
      queryFn: () => api.getProductUnits(item.product_id),
      enabled: dialog === 'pickup',
    })),
  });

  const unitsByProduct = useMemo(() => {
    const map = new Map<number, ProductUnit[]>();
    (order?.items ?? []).forEach((item, index) => {
      map.set(item.product_id, unitQueries[index]?.data?.data ?? []);
    });
    return map;
  }, [order, unitQueries]);

  const transition = useMutation({
    mutationFn: ({ action, body }: { action: string; body?: Record<string, unknown> }) =>
      api.orderAction(orderId, action, body ?? {}),
    onSuccess: (updated) => {
      queryClient.setQueryData(['order', orderId], updated);
      void queryClient.invalidateQueries({ queryKey: ['orders'] });
      void queryClient.invalidateQueries({ queryKey: ['order-events', orderId] });
      setDialog(null);
      setComment('');
      setReason('');
      setLogTitle('');
      push(t('app.saved'), 'success');
    },
    onError: pushError,
  });

  if (query.isLoading) {
    return <Skeleton height={320} radius={6} />;
  }
  if (!order) {
    return (
      <>
        <h1>{t('orders.notFound')}</h1>
        <Link to="/gestione/ordini" className="vl-btn vl-btn--ghost">
          {t('staff.ordersQueue')}
        </Link>
      </>
    );
  }

  function openDialog(action: OrderAction) {
    if (action === 'edit') {
      setDialog('note');
      return;
    }
    setDialog(action as DialogKind);
  }

  function pickupValid(): boolean {
    return (order?.items ?? []).every(
      (item) => (assignments[item.id]?.length ?? 0) === item.quantity,
    );
  }

  function submitPickup() {
    transition.mutate({
      action: 'pickup',
      body: {
        picked_up_at: null,
        comment: comment || null,
        assignments: (order?.items ?? []).map((item) => ({
          order_item_id: item.id,
          product_unit_ids: assignments[item.id] ?? [],
          condition_out: 'ok',
          note: null,
        })),
      },
    });
  }

  function submitReturn() {
    const logs = logTitle.trim()
      ? [
          {
            product_id: logProductId ?? order?.items[0]?.product_id,
            type: 'damage',
            severity: 'warning',
            title: logTitle,
            body: null,
            is_public: true,
          },
        ]
      : [];
    transition.mutate({
      action: 'return',
      body: {
        returned_at: null,
        comment: comment || null,
        returns: (order?.items ?? []).map((item) => ({
          order_item_id: item.id,
          returned_quantity: item.quantity,
          units: (item.assigned_units ?? []).map((unit) => ({
            product_unit_id: unit.product_unit_id,
            condition_in: conditions[unit.product_unit_id] ?? 'ok',
            note: null,
          })),
        })),
        logs,
      },
    });
  }

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.ordersQueue')}</p>
        <h1>{t('orders.detailTitle', { code: order.code ?? `#${order.id}` })}</h1>
        <div className="vl-row" style={{ marginTop: 'var(--sp-3)' }}>
          <StatusBadge status={order.status} />
          <span className="vl-subtle">{order.user.display_name}</span>
          {order.exceeds_limits ? <Badge tone="pending">{t('orders.exceedsLimits')}</Badge> : null}
        </div>
      </div>

      <div className="vl-row" style={{ marginBottom: 'var(--sp-5)' }}>
        <OrderActions actions={order.allowed_actions} onAction={openDialog} />
        {canPrintOrderForm(order.status) ? (
          <a
            href={orderFormUrl(order.id)}
            target="_blank"
            rel="noreferrer"
            className="vl-btn vl-btn--ghost vl-btn--sm"
            title={t('orderForm.hint')}
          >
            <Icon name="file" size={14} />
            {t('orderForm.print')}
          </a>
        ) : null}
      </div>

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
            <div className="vl-table-wrap">
              <table className="vl-table">
                <thead>
                  <tr>
                    <th scope="col">{t('product.brand')}</th>
                    <th scope="col">{t('cart.quantity')}</th>
                    <th scope="col">{t('staff.tabUnits')}</th>
                  </tr>
                </thead>
                <tbody>
                  {order.items.map((item) => (
                    <tr key={item.id}>
                      <td>
                        <Link to={`/prodotto/${item.product.slug}`}>{item.product_name_snapshot}</Link>
                        <div className="vl-subtle">{item.product_brand_snapshot}</div>
                      </td>
                      <td>{item.quantity}</td>
                      <td className="vl-mono">
                        {(item.assigned_units ?? []).map((unit) => unit.unit_label).join(' · ') || '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>

          <Card title={t('orders.infoTitle')} headingLevel={2}>
            <dl className="vl-form-grid vl-form-grid--2" style={{ margin: 0 }}>
              <div>
                <dt className="vl-datacard__label">{t('checkout.subject')}</dt>
                <dd style={{ margin: 0 }}>{order.subject ?? '—'}</dd>
              </div>
              <div>
                <dt className="vl-datacard__label">{t('orders.professorLabel')}</dt>
                <dd style={{ margin: 0 }}>{order.professor ?? '—'}</dd>
              </div>
              <div style={{ gridColumn: '1 / -1' }}>
                <dt className="vl-datacard__label">{t('checkout.motivation')}</dt>
                <dd style={{ margin: 0 }}>{order.motivation ?? '—'}</dd>
              </div>
              <div style={{ gridColumn: '1 / -1' }}>
                <dt className="vl-datacard__label">{t('orders.staffNotes')}</dt>
                <dd style={{ margin: 0 }}>{order.staff_notes ?? '—'}</dd>
              </div>
            </dl>
          </Card>

          {order.limit_violations.length > 0 ? (
            <LimitWarningList violations={order.limit_violations} />
          ) : null}
        </div>

        <div className="vl-stack">
          <Card title={t('cart.dates')} headingLevel={2}>
            <dl className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)', margin: 0 }}>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('orders.pickup')}</dt>
                <dd style={{ margin: 0 }}>
                  {formatDate(order.pickup_date)} {order.pickup_time}
                </dd>
              </div>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('orders.return')}</dt>
                <dd style={{ margin: 0 }}>
                  {formatDate(order.return_date)} {order.return_time}
                </dd>
              </div>
              {order.decided_by ? (
                <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                  <dt className="vl-subtle">{t('actions.approve')}</dt>
                  <dd style={{ margin: 0 }}>
                    {order.decided_by.display_name} · {formatDateTime(order.decided_at)}
                  </dd>
                </div>
              ) : null}
            </dl>
          </Card>

          <Card title={t('orders.timeline')} headingLevel={2}>
            <OrderStatusTimeline events={order.events ?? []} />
          </Card>
        </div>
      </div>

      {/* approve */}
      <Modal
        open={dialog === 'approve'}
        onClose={() => setDialog(null)}
        title={t('staff.approveTitle', { code: order.code ?? '' })}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="primary"
              loading={transition.isPending}
              onClick={() =>
                transition.mutate({
                  action: 'approve',
                  body: { comment: comment || null, staff_notes: staffNotes || null },
                })
              }
            >
              {t('actions.approve')}
            </Button>
          </>
        }
      >
        <Field label={t('staff.approveComment')} htmlFor="approve-comment" optional>
          <TextArea id="approve-comment" value={comment} onChange={(e) => setComment(e.target.value)} />
        </Field>
        <div style={{ marginTop: 'var(--sp-4)' }}>
          <Field label={t('orders.staffNotes')} htmlFor="approve-notes" optional>
            <TextArea id="approve-notes" value={staffNotes} onChange={(e) => setStaffNotes(e.target.value)} />
          </Field>
        </div>
      </Modal>

      {/* reject */}
      <Modal
        open={dialog === 'reject'}
        onClose={() => setDialog(null)}
        title={t('staff.rejectTitle', { code: order.code ?? '' })}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="danger"
              disabled={reason.trim().length === 0}
              loading={transition.isPending}
              onClick={() => transition.mutate({ action: 'reject', body: { reason } })}
            >
              {t('actions.reject')}
            </Button>
          </>
        }
      >
        <Field
          label={t('staff.rejectReason')}
          htmlFor="staff-reject-reason"
          error={reason.trim().length === 0 ? t('staff.rejectReasonRequired') : undefined}
        >
          <TextArea id="staff-reject-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
      </Modal>

      {/* pickup with unit assignment */}
      <Modal
        open={dialog === 'pickup'}
        onClose={() => setDialog(null)}
        title={t('staff.pickupTitle', { code: order.code ?? '' })}
        wide
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="primary"
              disabled={!pickupValid()}
              loading={transition.isPending}
              onClick={submitPickup}
            >
              {t('actions.pickup')}
            </Button>
          </>
        }
      >
        <p className="vl-subtle">{t('staff.pickupLead')}</p>
        <div className="vl-stack" style={{ marginTop: 'var(--sp-4)' }}>
          {order.items.map((item) => {
            const units = unitsByProduct.get(item.product_id) ?? [];
            const chosen = assignments[item.id] ?? [];
            const valid = chosen.length === item.quantity;
            return (
              <fieldset
                key={item.id}
                style={{
                  border: '1px solid var(--color-line)',
                  borderRadius: 'var(--radius-sm)',
                  padding: 'var(--sp-4)',
                }}
              >
                <legend style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>
                  {item.product_name_snapshot} (×{item.quantity})
                </legend>
                <div className="vl-chips">
                  {units.map((unit) => (
                    <label key={unit.id} className="vl-check">
                      <input
                        type="checkbox"
                        checked={chosen.includes(unit.id)}
                        disabled={unit.status !== 'available' && !chosen.includes(unit.id)}
                        onChange={(e) =>
                          setAssignments((prev) => {
                            const current = prev[item.id] ?? [];
                            return {
                              ...prev,
                              [item.id]: e.target.checked
                                ? [...current, unit.id]
                                : current.filter((x) => x !== unit.id),
                            };
                          })
                        }
                      />
                      <span className="vl-mono">{unit.label}</span>
                    </label>
                  ))}
                  {units.length === 0 ? <span className="vl-subtle">—</span> : null}
                </div>
                {!valid ? (
                  <p className="vl-field__error" role="alert" style={{ marginTop: 'var(--sp-2)' }}>
                    {t('staff.pickupUnitsMismatch', {
                      n: item.quantity,
                      product: item.product_name_snapshot,
                    })}
                  </p>
                ) : null}
              </fieldset>
            );
          })}
          <Field label={t('staff.comment')} htmlFor="pickup-comment" optional>
            <TextArea id="pickup-comment" value={comment} onChange={(e) => setComment(e.target.value)} />
          </Field>
        </div>
      </Modal>

      {/* return inspection */}
      <Modal
        open={dialog === 'return'}
        onClose={() => setDialog(null)}
        title={t('staff.returnTitle', { code: order.code ?? '' })}
        wide
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)}>
              {t('app.cancel')}
            </Button>
            <Button variant="primary" loading={transition.isPending} onClick={submitReturn}>
              {t('actions.return')}
            </Button>
          </>
        }
      >
        <p className="vl-subtle">{t('staff.returnLead')}</p>
        <div className="vl-stack" style={{ marginTop: 'var(--sp-4)' }}>
          {order.items.map((item) => (
            <fieldset
              key={item.id}
              style={{
                border: '1px solid var(--color-line)',
                borderRadius: 'var(--radius-sm)',
                padding: 'var(--sp-4)',
              }}
            >
              <legend style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>
                {item.product_name_snapshot}
              </legend>
              {(item.assigned_units ?? []).map((unit) => (
                <div key={unit.id} className="vl-row" style={{ marginBottom: 'var(--sp-2)' }}>
                  <span className="vl-mono" style={{ minWidth: 40 }}>
                    {unit.unit_label}
                  </span>
                  <label className="vl-sr-only" htmlFor={`cond-${unit.product_unit_id}`}>
                    {t('staff.returnCondition')}
                  </label>
                  <Select
                    id={`cond-${unit.product_unit_id}`}
                    value={conditions[unit.product_unit_id] ?? 'ok'}
                    onChange={(e) =>
                      setConditions((prev) => ({
                        ...prev,
                        [unit.product_unit_id]: e.target.value as ConditionValue,
                      }))
                    }
                    style={{ width: 'auto' }}
                  >
                    {list('condition').map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </div>
              ))}
              {(item.assigned_units ?? []).length === 0 ? (
                <span className="vl-subtle">{label('condition', 'ok')}</span>
              ) : null}
            </fieldset>
          ))}

          <Field label={t('staff.returnAddLog')} htmlFor="return-log" optional>
            <TextArea id="return-log" value={logTitle} onChange={(e) => setLogTitle(e.target.value)} />
          </Field>
          {logTitle.trim() ? (
            <Field label={t('staff.products')} htmlFor="return-log-product">
              <Select
                id="return-log-product"
                value={String(logProductId ?? order.items[0]?.product_id ?? '')}
                onChange={(e) => setLogProductId(Number(e.target.value))}
              >
                {order.items.map((item) => (
                  <option key={item.product_id} value={item.product_id}>
                    {item.product_name_snapshot}
                  </option>
                ))}
              </Select>
            </Field>
          ) : null}
          <Field label={t('staff.comment')} htmlFor="return-comment" optional>
            <TextArea id="return-comment" value={comment} onChange={(e) => setComment(e.target.value)} />
          </Field>
        </div>
      </Modal>

      {/* no-show / note / reopen / cancel */}
      <Modal
        open={dialog === 'mark_no_show' || dialog === 'note' || dialog === 'reopen'}
        onClose={() => setDialog(null)}
        title={
          dialog === 'mark_no_show'
            ? t('staff.noShowTitle')
            : dialog === 'reopen'
              ? t('actions.reopen')
              : t('staff.notesTitle')
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="primary"
              loading={transition.isPending}
              onClick={() => {
                if (dialog === 'mark_no_show') {
                  transition.mutate({ action: 'no-show', body: { comment: comment || null } });
                } else if (dialog === 'reopen') {
                  transition.mutate({ action: 'reopen', body: { to_status: 'approved', reason } });
                } else {
                  transition.mutate({
                    action: 'notes',
                    body: { staff_notes: staffNotes || null, comment: comment || null },
                  });
                }
              }}
            >
              {t('app.confirm')}
            </Button>
          </>
        }
      >
        {dialog === 'reopen' ? (
          <Field label={t('staff.rejectReason')} htmlFor="reopen-reason">
            <TextArea id="reopen-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
        ) : dialog === 'note' ? (
          <Field label={t('orders.staffNotes')} htmlFor="note-staff">
            <TextArea id="note-staff" value={staffNotes} onChange={(e) => setStaffNotes(e.target.value)} />
          </Field>
        ) : null}
        <div style={{ marginTop: 'var(--sp-4)' }}>
          <Field label={t('staff.comment')} htmlFor="generic-comment" optional>
            <TextArea id="generic-comment" value={comment} onChange={(e) => setComment(e.target.value)} />
          </Field>
        </div>
      </Modal>
    </>
  );
}
