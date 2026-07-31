import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthProvider';
import { useEnums } from '@/hooks/useEnums';
import { useLiveCheck } from '@/hooks/useLiveCheck';
import { useToast } from '@/components/Toast';
import {
  AvailabilityBadge,
  DateRangePicker,
  LimitWarningList,
  OrderStatusTimeline,
  RowAvailability,
  StatusBadge,
  primaryOrderAction,
} from '@/components/domain';
import {
  Alert,
  Badge,
  Button,
  Card,
  ConfirmDialog,
  Field,
  MenuButton,
  Modal,
  QuantityStepper,
  SearchInput,
  Select,
  Skeleton,
  Switch,
  TextArea,
  TextInput,
  type MenuItem,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate, formatDateTime } from '@/lib/format';
import { canPrintOrderForm, orderFormUrl } from '@/lib/orderForm';
import type {
  ConditionValue,
  Order,
  OrderAction,
  OrderOverbookedProduct,
  ProductUnit,
  SuggestedSubstitute,
} from '@/types/api';

type DialogKind = 'reject' | 'pickup' | 'return' | 'reopen' | 'cancel' | 'no_show' | null;

interface EditItemRow {
  product_id: number;
  name: string;
  quantity: number;
}

interface EditFormState {
  pickup_date: string | null;
  return_date: string | null;
  custom_times: boolean;
  pickup_time: string;
  pickup_time_end: string;
  return_time: string;
  return_time_end: string;
  subject: string;
  professor: string;
  motivation: string;
  notes: string;
  staff_notes: string;
  items: EditItemRow[];
}

/**
 * Staff order detail. Action layout (owner request E, one capability = one
 * place): ONE primary CTA for the state machine's main transition, ONE edit
 * surface (the panel absorbs dates, items, fields and internal notes), the
 * destructive/secondary transitions in the "Altro ▾" overflow, the PDF as a
 * secondary icon-button. Transition dialogs carry ONLY transition-specific
 * input (reject reason, unit assignment, return inspection).
 */
export function StaffOrderDetailPage() {
  const { id } = useParams();
  const orderId = Number(id);
  const queryClient = useQueryClient();
  const { list, label } = useEnums();
  const { push, pushError } = useToast();

  const { permissions } = useAuth();
  const canEditFull = permissions['orders.edit_full'];

  const [dialog, setDialog] = useState<DialogKind>(null);
  const [reason, setReason] = useState('');
  const [assignments, setAssignments] = useState<Record<number, number[]>>({});
  const [conditions, setConditions] = useState<Record<number, ConditionValue>>({});
  const [logTitle, setLogTitle] = useState('');
  const [logProductId, setLogProductId] = useState<number | null>(null);

  /* -------- the ONE edit surface (admin full / staff pre-pickup) -------- */
  const [editOpen, setEditOpen] = useState(false);
  const [editForm, setEditForm] = useState<EditFormState | null>(null);
  const [editForce, setEditForce] = useState(false);
  const [editConflicts, setEditConflicts] = useState<OrderOverbookedProduct[] | null>(null);
  const [productSearch, setProductSearch] = useState('');

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
      setReason('');
      setLogTitle('');
      push(t('app.saved'), 'success');
    },
    onError: pushError,
  });

  /* Live availability inside the edit panel (owner request A). */
  const editLive = useLiveCheck({
    items: editForm?.items ?? [],
    pickupDate: editForm?.pickup_date ?? null,
    returnDate: editForm?.return_date ?? null,
    excludeOrderId: orderId,
    enabled: editOpen,
  });
  const editLiveConflicts = (editLive.check?.availability ?? []).filter((e) => !e.sufficient);
  const editBlocked = editLiveConflicts.length > 0 && !(canEditFull && editForce);

  /* Product picker inside the edit panel shows availability for the dates. */
  const productResults = useQuery({
    queryKey: ['edit-product-search', productSearch, editForm?.pickup_date, editForm?.return_date],
    queryFn: () =>
      editForm?.pickup_date && editForm.return_date
        ? api.getAvailableProducts({
            q: productSearch,
            start_date: editForm.pickup_date,
            end_date: editForm.return_date,
            include_unavailable: 'true',
            exclude_order_id: orderId,
            per_page: 8,
          })
        : api.getProducts({ q: productSearch, per_page: 8 }),
    enabled: editOpen && productSearch.trim().length >= 2,
  });

  const editMutation = useMutation({
    mutationFn: (body: Record<string, unknown>) => api.updateOrder(orderId, body),
    onSuccess: (updated: Order) => {
      queryClient.setQueryData(['order', orderId], updated);
      void queryClient.invalidateQueries({ queryKey: ['orders'] });
      void queryClient.invalidateQueries({ queryKey: ['order-events', orderId] });
      setEditOpen(false);
      setEditConflicts(null);
      setEditForce(false);
      push(updated.forced_overbook ? t('staff.editForcedSaved') : t('staff.editSaved'), updated.forced_overbook ? 'info' : 'success');
    },
    onError: (err: unknown) => {
      if (err instanceof ApiError && err.code === 'insufficient_availability') {
        setEditConflicts((err.details?.['products'] as OrderOverbookedProduct[] | undefined) ?? []);
        return;
      }
      pushError(err);
    },
  });

  function openEdit() {
    if (!order) return;
    setEditForm({
      pickup_date: order.pickup_date,
      return_date: order.return_date,
      custom_times: order.pickup_time !== null || order.return_time !== null,
      pickup_time: order.pickup_time ?? '',
      pickup_time_end: order.pickup_time_end ?? '',
      return_time: order.return_time ?? '',
      return_time_end: order.return_time_end ?? '',
      subject: order.subject ?? '',
      professor: order.professor ?? '',
      motivation: order.motivation ?? '',
      notes: order.notes ?? '',
      staff_notes: order.staff_notes ?? '',
      items: order.items.map((item) => ({
        product_id: item.product_id,
        name: item.product_name_snapshot ?? item.product.name,
        quantity: item.quantity,
      })),
    });
    setEditForce(false);
    setEditConflicts(null);
    setProductSearch('');
    setEditOpen(true);
  }

  function submitEdit() {
    if (!editForm) return;
    const useCustom = editForm.custom_times;
    const body: Record<string, unknown> = {
      pickup_date: editForm.pickup_date,
      return_date: editForm.return_date,
      // NULL = the lab's weekday window (SPEC v1.4 §5.3).
      pickup_time: useCustom && editForm.pickup_time !== '' ? editForm.pickup_time : null,
      pickup_time_end:
        useCustom && editForm.pickup_time !== '' && editForm.pickup_time_end !== ''
          ? editForm.pickup_time_end
          : null,
      return_time: useCustom && editForm.return_time !== '' ? editForm.return_time : null,
      return_time_end:
        useCustom && editForm.return_time !== '' && editForm.return_time_end !== ''
          ? editForm.return_time_end
          : null,
      subject: editForm.subject !== '' ? editForm.subject : null,
      professor: editForm.professor !== '' ? editForm.professor : null,
      staff_notes: editForm.staff_notes !== '' ? editForm.staff_notes : null,
      items: editForm.items.map(({ product_id, quantity }) => ({ product_id, quantity })),
    };
    if (canEditFull) {
      body['motivation'] = editForm.motivation !== '' ? editForm.motivation : null;
      body['notes'] = editForm.notes !== '' ? editForm.notes : null;
      if (editForce) {
        body['force'] = true;
      }
    }
    setEditConflicts(null);
    editMutation.mutate(body);
  }

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

  const allowed = order.allowed_actions;
  const primary = primaryOrderAction(allowed);
  const canEdit = canEditFull || allowed.includes('edit');

  function runPrimary(action: OrderAction) {
    if (action === 'approve') {
      transition.mutate({ action: 'approve', body: {} });
      return;
    }
    if (action === 'pickup' || action === 'return') {
      setDialog(action);
    }
  }

  /* Destructive/secondary transitions live in the overflow (owner request E). */
  const overflowItems: MenuItem[] = [];
  if (allowed.includes('reject')) {
    overflowItems.push({ id: 'reject', label: t('actions.reject'), icon: 'close', danger: true, onSelect: () => setDialog('reject') });
  }
  if (allowed.includes('mark_no_show')) {
    overflowItems.push({ id: 'no_show', label: t('actions.mark_no_show'), icon: 'clock', onSelect: () => setDialog('no_show') });
  }
  if (allowed.includes('cancel')) {
    overflowItems.push({ id: 'cancel', label: t('actions.cancel'), icon: 'trash', danger: true, onSelect: () => setDialog('cancel') });
  }
  if (allowed.includes('reopen')) {
    overflowItems.push({ id: 'reopen', label: t('actions.reopen'), icon: 'refresh', onSelect: () => setDialog('reopen') });
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

      {/* Problems FIRST (owner request B): everything that needs attention sits
          above the actions it conditions. */}
      {order.rejection_reason !== null || order.limit_violations.length > 0 ? (
        <div className="vl-problems" style={{ marginBottom: 'var(--sp-5)' }}>
          {order.rejection_reason ? (
            <Alert level="danger" icon="alert" title={t('orders.rejectionReason')}>
              {order.rejection_reason}
            </Alert>
          ) : null}
          {order.limit_violations.length > 0 ? (
            <LimitWarningList violations={order.limit_violations} />
          ) : null}
        </div>
      ) : null}

      {/* Actions: primary CTA · Modifica · Altro ▾ · PDF — nothing else. */}
      <div className="vl-row" style={{ marginBottom: 'var(--sp-5)' }} data-testid="order-actions">
        {primary ? (
          <Button
            variant="primary"
            loading={transition.isPending && dialog === null}
            onClick={() => runPrimary(primary)}
          >
            {t(`actions.${primary}`)}
          </Button>
        ) : null}
        {canEdit ? (
          <Button variant="secondary" onClick={openEdit}>
            <Icon name="edit" size={14} />
            {t('actions.edit')}
          </Button>
        ) : null}
        <MenuButton label={t('staff.moreActions')} items={overflowItems} />
        {canPrintOrderForm(order.status) ? (
          <a
            href={orderFormUrl(order.id)}
            target="_blank"
            rel="noreferrer"
            className="vl-btn vl-btn--ghost vl-btn--icon"
            title={t('orderForm.print')}
            aria-label={t('orderForm.print')}
          >
            <Icon name="file" size={16} />
          </a>
        ) : null}
      </div>

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
        </div>

        <div className="vl-stack">
          <Card title={t('cart.dates')} headingLevel={2}>
            <dl className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)', margin: 0 }}>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('orders.pickup')}</dt>
                <dd style={{ margin: 0 }}>
                  {formatDate(order.pickup_date)}
                  {order.pickup_window ? ` · ${order.pickup_window}` : ''}
                </dd>
              </div>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('orders.return')}</dt>
                <dd style={{ margin: 0 }}>
                  {formatDate(order.return_date)}
                  {order.return_window ? ` · ${order.return_window}` : ''}
                </dd>
              </div>
              {order.pickup_time === null && order.return_time === null ? (
                <p className="vl-window-note" style={{ marginTop: 'var(--sp-1)' }}>
                  <Icon name="clock" size={14} />
                  {t('timeWindow.labWindow')}
                </p>
              ) : null}
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

      {/* reject — transition-specific input: the reason, nothing else */}
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

      {/* pickup — transition-specific input: unit assignment */}
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
              disabled={
                !(order.items ?? []).every((item) => (assignments[item.id]?.length ?? 0) === item.quantity)
              }
              loading={transition.isPending}
              onClick={() =>
                transition.mutate({
                  action: 'pickup',
                  body: {
                    picked_up_at: null,
                    assignments: (order.items ?? []).map((item) => ({
                      order_item_id: item.id,
                      product_unit_ids: assignments[item.id] ?? [],
                      condition_out: 'ok',
                      note: null,
                    })),
                  },
                })
              }
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
        </div>
      </Modal>

      {/* return — transition-specific input: inspection + optional damage log */}
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
            <Button
              variant="primary"
              loading={transition.isPending}
              onClick={() =>
                transition.mutate({
                  action: 'return',
                  body: {
                    returned_at: null,
                    returns: (order.items ?? []).map((item) => ({
                      order_item_id: item.id,
                      returned_quantity: item.quantity,
                      units: (item.assigned_units ?? []).map((unit) => ({
                        product_unit_id: unit.product_unit_id,
                        condition_in: conditions[unit.product_unit_id] ?? 'ok',
                        note: null,
                      })),
                    })),
                    logs: logTitle.trim()
                      ? [
                          {
                            product_id: logProductId ?? order.items[0]?.product_id,
                            type: 'damage',
                            severity: 'warning',
                            title: logTitle,
                            body: null,
                            is_public: true,
                          },
                        ]
                      : [],
                  },
                })
              }
            >
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
        </div>
      </Modal>

      {/* no-show — nothing to type: confirm and done */}
      <ConfirmDialog
        open={dialog === 'no_show'}
        title={t('staff.noShowTitle')}
        body={t('staff.noShowBody')}
        confirmLabel={t('actions.mark_no_show')}
        loading={transition.isPending}
        onCancel={() => setDialog(null)}
        onConfirm={() => transition.mutate({ action: 'no-show', body: {} })}
      />

      {/* cancel — transition-specific input: the optional reason */}
      <Modal
        open={dialog === 'cancel'}
        onClose={() => setDialog(null)}
        title={t('staff.cancelOrderTitle', { code: order.code ?? `#${order.id}` })}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={transition.isPending}
              onClick={() => transition.mutate({ action: 'cancel', body: { reason: reason || null } })}
            >
              {t('actions.cancel')}
            </Button>
          </>
        }
      >
        <p className="vl-subtle">{t('staff.cancelOrderBody')}</p>
        <div style={{ marginTop: 'var(--sp-4)' }}>
          <Field label={t('orders.cancelReason')} htmlFor="staff-cancel-reason" optional>
            <TextArea
              id="staff-cancel-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
          </Field>
        </div>
      </Modal>

      {/* reopen — transition-specific input: the reason (required) */}
      <Modal
        open={dialog === 'reopen'}
        onClose={() => setDialog(null)}
        title={t('actions.reopen')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDialog(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="primary"
              disabled={reason.trim().length === 0}
              loading={transition.isPending}
              onClick={() => transition.mutate({ action: 'reopen', body: { to_status: 'approved', reason } })}
            >
              {t('app.confirm')}
            </Button>
          </>
        }
      >
        <Field label={t('staff.rejectReason')} htmlFor="reopen-reason">
          <TextArea id="reopen-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
      </Modal>

      {/* THE edit surface: dates, window override, items (with live
          availability), fields and internal notes — everything in one place */}
      <Modal
        open={editOpen && editForm !== null}
        onClose={() => setEditOpen(false)}
        title={t('staff.editOrderTitle', { code: order.code ?? `#${order.id}` })}
        wide
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditOpen(false)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="primary"
              disabled={editForm === null || editForm.items.length === 0 || editBlocked}
              loading={editMutation.isPending}
              onClick={submitEdit}
            >
              {t('app.save')}
            </Button>
          </>
        }
      >
        {editForm !== null ? (
          <div className="vl-stack" style={{ gap: 'var(--sp-4)' }}>
            <p className="vl-subtle">{t('staff.editOrderLead')}</p>

            {/* Problems first, inside the panel too (owner request B). */}
            <div className="vl-problems">
              {editConflicts !== null ? (
                <Alert level="danger" icon="alert" title={t('staff.editConflictTitle')}>
                  <ul>
                    {editConflicts.map((conflict) => (
                      <li key={conflict.product_id}>
                        {t('staff.editConflictLine', {
                          product:
                            conflict.name ??
                            editForm.items.find((i) => i.product_id === conflict.product_id)?.name ??
                            `#${conflict.product_id}`,
                          requested: conflict.requested,
                          available: conflict.available,
                        })}
                      </li>
                    ))}
                  </ul>
                  <p style={{ marginTop: 'var(--sp-2)' }}>{t('staff.editConflictHint')}</p>
                </Alert>
              ) : null}
              {editBlocked ? (
                <Alert level="warning" icon="alert" title={t('staff.editConflictTitle')}>
                  {t('liveCheck.conflictsBlock')}
                </Alert>
              ) : null}
            </div>

            <Field label={t('cart.dates')} htmlFor="edit-dates">
              <DateRangePicker
                id="edit-dates"
                pickupDate={editForm.pickup_date}
                returnDate={editForm.return_date}
                minDate="2000-01-01"
                respectClosures={false}
                onChange={({ pickup_date, return_date }) =>
                  setEditForm((prev) =>
                    prev ? { ...prev, pickup_date, return_date } : prev,
                  )
                }
              />
            </Field>

            <div>
              <Switch
                checked={editForm.custom_times}
                onChange={(value) =>
                  setEditForm((prev) => (prev ? { ...prev, custom_times: value } : prev))
                }
                label={t('timeWindow.customToggle')}
              />
              <p className="vl-subtle" style={{ marginTop: 'var(--sp-1)' }}>
                {t('timeWindow.customHint')}
              </p>
            </div>
            {editForm.custom_times ? (
              <div className="vl-form-grid vl-form-grid--2">
                <Field label={t('timeWindow.pickupStart')} htmlFor="edit-pickup-time">
                  <TextInput
                    id="edit-pickup-time"
                    type="time"
                    value={editForm.pickup_time}
                    onChange={(e) =>
                      setEditForm((prev) => (prev ? { ...prev, pickup_time: e.target.value } : prev))
                    }
                  />
                </Field>
                <Field label={t('timeWindow.pickupEnd')} htmlFor="edit-pickup-time-end" optional>
                  <TextInput
                    id="edit-pickup-time-end"
                    type="time"
                    value={editForm.pickup_time_end}
                    onChange={(e) =>
                      setEditForm((prev) => (prev ? { ...prev, pickup_time_end: e.target.value } : prev))
                    }
                  />
                </Field>
                <Field label={t('timeWindow.returnStart')} htmlFor="edit-return-time">
                  <TextInput
                    id="edit-return-time"
                    type="time"
                    value={editForm.return_time}
                    onChange={(e) =>
                      setEditForm((prev) => (prev ? { ...prev, return_time: e.target.value } : prev))
                    }
                  />
                </Field>
                <Field label={t('timeWindow.returnEnd')} htmlFor="edit-return-time-end" optional>
                  <TextInput
                    id="edit-return-time-end"
                    type="time"
                    value={editForm.return_time_end}
                    onChange={(e) =>
                      setEditForm((prev) => (prev ? { ...prev, return_time_end: e.target.value } : prev))
                    }
                  />
                </Field>
              </div>
            ) : null}

            <fieldset
              style={{
                border: '1px solid var(--color-line)',
                borderRadius: 'var(--radius-sm)',
                padding: 'var(--sp-4)',
              }}
            >
              <legend style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>
                {t('staff.editItems')}
              </legend>
              <div className="vl-stack" style={{ gap: 'var(--sp-3)' }}>
                {editForm.items.map((item) => (
                  <div key={item.product_id} className="vl-stack" style={{ gap: 'var(--sp-1)' }}>
                    <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                      <span style={{ flex: 1, minWidth: 0 }}>{item.name}</span>
                      <QuantityStepper
                        value={item.quantity}
                        onChange={(quantity) =>
                          setEditForm((prev) =>
                            prev
                              ? {
                                  ...prev,
                                  items: prev.items.map((row) =>
                                    row.product_id === item.product_id ? { ...row, quantity } : row,
                                  ),
                                }
                              : prev,
                          )
                        }
                        label={`${t('cart.quantity')} ${item.name}`}
                      />
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                          setEditForm((prev) =>
                            prev
                              ? {
                                  ...prev,
                                  items: prev.items.filter((row) => row.product_id !== item.product_id),
                                }
                              : prev,
                          )
                        }
                      >
                        {t('staff.editRemoveItem')}
                      </Button>
                    </div>
                    <RowAvailability
                      entry={editLive.entryFor(item.product_id)}
                      checking={editLive.checking}
                      onSwap={(substitute: SuggestedSubstitute) =>
                        setEditForm((prev) =>
                          prev
                            ? {
                                ...prev,
                                items: prev.items.map((row) =>
                                  row.product_id === item.product_id
                                    ? { ...row, product_id: substitute.product_id, name: substitute.name }
                                    : row,
                                ),
                              }
                            : prev,
                        )
                      }
                    />
                  </div>
                ))}
                {editForm.items.length === 0 ? (
                  <p className="vl-field__error" role="alert">
                    {t('staff.editNoItems')}
                  </p>
                ) : null}
                <SearchInput
                  value={productSearch}
                  onChange={setProductSearch}
                  label={t('staff.editAddProduct')}
                  placeholder={t('staff.editSearchProduct')}
                />
                {productSearch.trim().length >= 2 ? (
                  <div className="vl-stack" style={{ gap: 'var(--sp-1)' }}>
                    {(productResults.data?.data ?? [])
                      .filter((p) => !editForm.items.some((row) => row.product_id === p.id))
                      .map((p) => (
                        <div key={p.id} className="vl-row" style={{ gap: 'var(--sp-2)' }}>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                              setEditForm((prev) =>
                                prev
                                  ? {
                                      ...prev,
                                      items: [
                                        ...prev.items,
                                        { product_id: p.id, name: p.name, quantity: 1 },
                                      ],
                                    }
                                  : prev,
                              );
                              setProductSearch('');
                            }}
                          >
                            <Icon name="plus" size={14} />
                            {p.name}
                          </Button>
                          {editForm.pickup_date && editForm.return_date ? (
                            <AvailabilityBadge available={p.available_quantity} capacity={p.capacity} />
                          ) : null}
                        </div>
                      ))}
                  </div>
                ) : null}
              </div>
            </fieldset>

            <div className="vl-form-grid vl-form-grid--2">
              <Field label={t('checkout.subject')} htmlFor="edit-subject">
                <TextInput
                  id="edit-subject"
                  value={editForm.subject}
                  onChange={(e) =>
                    setEditForm((prev) => (prev ? { ...prev, subject: e.target.value } : prev))
                  }
                />
              </Field>
              <Field label={t('orders.professorLabel')} htmlFor="edit-professor">
                <TextInput
                  id="edit-professor"
                  value={editForm.professor}
                  onChange={(e) =>
                    setEditForm((prev) => (prev ? { ...prev, professor: e.target.value } : prev))
                  }
                />
              </Field>
            </div>
            {canEditFull ? (
              <>
                <Field label={t('checkout.motivation')} htmlFor="edit-motivation">
                  <TextArea
                    id="edit-motivation"
                    value={editForm.motivation}
                    onChange={(e) =>
                      setEditForm((prev) => (prev ? { ...prev, motivation: e.target.value } : prev))
                    }
                  />
                </Field>
                <Field label={t('checkout.notes')} htmlFor="edit-notes" optional>
                  <TextArea
                    id="edit-notes"
                    value={editForm.notes}
                    onChange={(e) =>
                      setEditForm((prev) => (prev ? { ...prev, notes: e.target.value } : prev))
                    }
                  />
                </Field>
              </>
            ) : null}
            <Field label={t('orders.staffNotes')} htmlFor="edit-staff-notes" optional>
              <TextArea
                id="edit-staff-notes"
                value={editForm.staff_notes}
                onChange={(e) =>
                  setEditForm((prev) => (prev ? { ...prev, staff_notes: e.target.value } : prev))
                }
              />
            </Field>

            {canEditFull && (editLiveConflicts.length > 0 || editConflicts !== null) ? (
              <div>
                <Switch checked={editForce} onChange={setEditForce} label={t('staff.editForce')} />
                {editForce ? (
                  <p className="vl-subtle" style={{ marginTop: 'var(--sp-2)' }}>
                    {t('staff.editForceWarning')}
                  </p>
                ) : null}
              </div>
            ) : null}
          </div>
        ) : null}
      </Modal>
    </>
  );
}
