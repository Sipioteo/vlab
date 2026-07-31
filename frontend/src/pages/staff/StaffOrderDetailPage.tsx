import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthProvider';
import { useEnums } from '@/hooks/useEnums';
import { useToast } from '@/components/Toast';
import {
  DateRangePicker,
  LimitWarningList,
  OrderActions,
  OrderStatusTimeline,
  StatusBadge,
} from '@/components/domain';
import {
  Alert,
  Badge,
  Button,
  Card,
  Field,
  Modal,
  QuantityStepper,
  SearchInput,
  Select,
  Skeleton,
  Switch,
  TextArea,
  TextInput,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate, formatDateTime } from '@/lib/format';
import { canPrintOrderForm, orderFormUrl } from '@/lib/orderForm';
import type { ConditionValue, Order, OrderAction, OrderOverbookedProduct, ProductUnit } from '@/types/api';

type DialogKind = 'approve' | 'reject' | 'pickup' | 'return' | 'mark_no_show' | 'note' | 'reopen' | null;

interface EditItemRow {
  product_id: number;
  name: string;
  quantity: number;
}

interface EditFormState {
  pickup_date: string | null;
  return_date: string | null;
  pickup_time: string;
  return_time: string;
  subject: string;
  professor: string;
  motivation: string;
  notes: string;
  staff_notes: string;
  items: EditItemRow[];
}

export function StaffOrderDetailPage() {
  const { id } = useParams();
  const orderId = Number(id);
  const queryClient = useQueryClient();
  const { list, label } = useEnums();
  const { push, pushError } = useToast();

  const { permissions } = useAuth();
  const canEditFull = permissions['orders.edit_full'];

  const [dialog, setDialog] = useState<DialogKind>(null);
  const [comment, setComment] = useState('');
  const [reason, setReason] = useState('');
  const [staffNotes, setStaffNotes] = useState('');
  const [assignments, setAssignments] = useState<Record<number, number[]>>({});
  const [conditions, setConditions] = useState<Record<number, ConditionValue>>({});
  const [logTitle, setLogTitle] = useState('');
  const [logProductId, setLogProductId] = useState<number | null>(null);

  /* -------- admin full edit (`orders.edit_full`) -------- */
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
      setComment('');
      setReason('');
      setLogTitle('');
      push(t('app.saved'), 'success');
    },
    onError: pushError,
  });

  const productResults = useQuery({
    queryKey: ['edit-product-search', productSearch],
    queryFn: () => api.getProducts({ q: productSearch, per_page: 8 }),
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
      pickup_time: order.pickup_time ?? '',
      return_time: order.return_time ?? '',
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
    const body: Record<string, unknown> = {
      pickup_date: editForm.pickup_date,
      return_date: editForm.return_date,
      pickup_time: editForm.pickup_time !== '' ? editForm.pickup_time : null,
      return_time: editForm.return_time !== '' ? editForm.return_time : null,
      subject: editForm.subject !== '' ? editForm.subject : null,
      professor: editForm.professor !== '' ? editForm.professor : null,
      motivation: editForm.motivation !== '' ? editForm.motivation : null,
      notes: editForm.notes !== '' ? editForm.notes : null,
      staff_notes: editForm.staff_notes !== '' ? editForm.staff_notes : null,
      items: editForm.items.map(({ product_id, quantity }) => ({ product_id, quantity })),
    };
    if (editForce) {
      body['force'] = true;
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

  function openDialog(action: OrderAction) {
    if (action === 'change_dates' || (action === 'edit' && canEditFull)) {
      openEdit();
      return;
    }
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
        {/* change_dates is folded into the full edit panel below */}
        <OrderActions
          actions={order.allowed_actions.filter((a) => a !== 'change_dates')}
          onAction={openDialog}
        />
        {canEditFull ? (
          <Button variant="secondary" size="sm" onClick={openEdit}>
            <Icon name="edit" size={14} />
            {t('staff.editOrder')}
          </Button>
        ) : null}
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

      {/* admin full edit (orders.edit_full) */}
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
              disabled={editForm === null || editForm.items.length === 0}
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
            <div className="vl-form-grid vl-form-grid--2">
              <Field label={t('orders.pickup')} htmlFor="edit-pickup-time">
                <TextInput
                  id="edit-pickup-time"
                  type="time"
                  value={editForm.pickup_time}
                  onChange={(e) =>
                    setEditForm((prev) => (prev ? { ...prev, pickup_time: e.target.value } : prev))
                  }
                />
              </Field>
              <Field label={t('orders.return')} htmlFor="edit-return-time">
                <TextInput
                  id="edit-return-time"
                  type="time"
                  value={editForm.return_time}
                  onChange={(e) =>
                    setEditForm((prev) => (prev ? { ...prev, return_time: e.target.value } : prev))
                  }
                />
              </Field>
            </div>

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
                  <div key={item.product_id} className="vl-row" style={{ justifyContent: 'space-between' }}>
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
                        <Button
                          key={p.id}
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
            <Field label={t('orders.staffNotes')} htmlFor="edit-staff-notes" optional>
              <TextArea
                id="edit-staff-notes"
                value={editForm.staff_notes}
                onChange={(e) =>
                  setEditForm((prev) => (prev ? { ...prev, staff_notes: e.target.value } : prev))
                }
              />
            </Field>

            <div>
              <Switch checked={editForce} onChange={setEditForce} label={t('staff.editForce')} />
              {editForce ? (
                <p className="vl-subtle" style={{ marginTop: 'var(--sp-2)' }}>
                  {t('staff.editForceWarning')}
                </p>
              ) : null}
            </div>
          </div>
        ) : null}
      </Modal>
    </>
  );
}
