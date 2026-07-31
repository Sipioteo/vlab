import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { usePermission } from '@/auth/AuthProvider';
import { useLiveCheck } from '@/hooks/useLiveCheck';
import { useToast } from '@/components/Toast';
import { AvailabilityBadge, DateRangePicker, RowAvailability } from '@/components/domain';
import {
  Alert,
  Button,
  Card,
  Field,
  QuantityStepper,
  SearchInput,
  Select,
  Switch,
  TextArea,
  TextInput,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate, todayIso } from '@/lib/format';
import type { Order, OrderOverbookedProduct, SuggestedSubstitute, User } from '@/types/api';

interface ItemRow {
  product_id: number;
  name: string;
  quantity: number;
}

/**
 * /gestione/ordini/nuovo — staff registers a loan on behalf of a student
 * (`orders.create_manual`): the walk-in at the counter, a phone booking, an
 * after-the-fact correction.
 *
 * Availability is checked LIVE while the operator builds the order (owner
 * request A + C): every row shows its status for the chosen dates, the picker
 * itself shows availability so an unavailable product is visibly a bad idea
 * before it is added, and the submit button is disabled (with the reason) while
 * conflicts exist — unless an admin flips the force switch.
 *
 * Times are NOT chosen by default (owner request D): the lab's weekday window
 * applies. "Orario personalizzato" reveals per-leg overrides (precise time or
 * custom range via the optional end field).
 */
export function StaffOrderCreatePage() {
  const navigate = useNavigate();
  const { push, pushError } = useToast();
  const canForce = usePermission('orders.edit_full');

  const [selectedUser, setSelectedUser] = useState<User | null>(null);
  const [userSearch, setUserSearch] = useState('');
  const [items, setItems] = useState<ItemRow[]>([]);
  const [productSearch, setProductSearch] = useState('');
  const [pickupDate, setPickupDate] = useState<string | null>(todayIso());
  const [returnDate, setReturnDate] = useState<string | null>(todayIso());
  const [customTimes, setCustomTimes] = useState(false);
  const [pickupTime, setPickupTime] = useState('');
  const [pickupTimeEnd, setPickupTimeEnd] = useState('');
  const [returnTime, setReturnTime] = useState('');
  const [returnTimeEnd, setReturnTimeEnd] = useState('');
  const [subject, setSubject] = useState('');
  const [professor, setProfessor] = useState('');
  const [motivation, setMotivation] = useState('');
  const [notes, setNotes] = useState('');
  const [staffNotes, setStaffNotes] = useState('');
  const [initialStatus, setInitialStatus] = useState<'approved' | 'pending'>('approved');
  const [force, setForce] = useState(false);
  const [conflicts, setConflicts] = useState<OrderOverbookedProduct[] | null>(null);

  const hasDates = pickupDate !== null && returnDate !== null;

  /* Live availability (debounced 400 ms, stale responses dropped). */
  const live = useLiveCheck({ items, pickupDate, returnDate });
  const liveConflicts = (live.check?.availability ?? []).filter((entry) => !entry.sufficient);
  const blockedByAvailability = liveConflicts.length > 0 && !(canForce && force);

  const userResults = useQuery({
    queryKey: ['manual-user-search', userSearch],
    queryFn: () => api.getUsers({ q: userSearch, per_page: 8 }),
    enabled: userSearch.trim().length >= 2,
  });

  /* When dates are chosen, the picker itself reports availability (request A). */
  const productResults = useQuery({
    queryKey: ['manual-product-search', productSearch, hasDates ? pickupDate : null, hasDates ? returnDate : null],
    queryFn: () =>
      hasDates
        ? api.getAvailableProducts({
            q: productSearch,
            start_date: pickupDate,
            end_date: returnDate,
            include_unavailable: 'true',
            per_page: 8,
          })
        : api.getProducts({ q: productSearch, per_page: 8 }),
    enabled: productSearch.trim().length >= 2,
  });

  const create = useMutation({
    mutationFn: (body: Record<string, unknown>) => api.createManualOrder(body),
    onSuccess: (order: Order) => {
      push(
        order.forced_overbook ? t('staff.newOrderForcedCreated') : t('staff.newOrderCreated'),
        order.forced_overbook ? 'info' : 'success',
      );
      if ((order.pending_regulations ?? []).length > 0) {
        push(t('staff.newOrderRegulationWarning'), 'info');
      }
      navigate(`/gestione/ordini/${order.id}`);
    },
    onError: (err: unknown) => {
      if (err instanceof ApiError && err.code === 'insufficient_availability') {
        setConflicts((err.details?.['products'] as OrderOverbookedProduct[] | undefined) ?? []);
        return;
      }
      pushError(err);
    },
  });

  const valid = selectedUser !== null && items.length > 0 && hasDates && !blockedByAvailability;

  function swapRow(productId: number, substitute: SuggestedSubstitute) {
    setItems((prev) =>
      prev.map((row) =>
        row.product_id === productId
          ? { ...row, product_id: substitute.product_id, name: substitute.name }
          : row,
      ),
    );
  }

  function submit() {
    if (!valid || selectedUser === null) return;
    const body: Record<string, unknown> = {
      user_id: selectedUser.id,
      items: items.map(({ product_id, quantity }) => ({ product_id, quantity })),
      start_date: pickupDate,
      end_date: returnDate,
      // No override → null = the lab's weekday window (SPEC v1.4 §5.3).
      pickup_time: customTimes && pickupTime !== '' ? pickupTime : null,
      pickup_time_end: customTimes && pickupTime !== '' && pickupTimeEnd !== '' ? pickupTimeEnd : null,
      return_time: customTimes && returnTime !== '' ? returnTime : null,
      return_time_end: customTimes && returnTime !== '' && returnTimeEnd !== '' ? returnTimeEnd : null,
      subject: subject.trim() !== '' ? subject.trim() : null,
      professor: professor.trim() !== '' ? professor.trim() : null,
      motivation: motivation.trim() !== '' ? motivation.trim() : null,
      notes: notes.trim() !== '' ? notes.trim() : null,
      staff_notes: staffNotes.trim() !== '' ? staffNotes.trim() : null,
      initial_status: initialStatus,
    };
    if (canForce && force) {
      body['force'] = true;
    }
    setConflicts(null);
    create.mutate(body);
  }

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.ordersQueue')}</p>
        <h1>{t('staff.newOrderTitle')}</h1>
        <p className="vl-lead">{t('staff.newOrderLead')}</p>
      </div>

      <div className="vl-stack" style={{ maxWidth: 720, gap: 'var(--sp-5)' }}>
        {/* Problems first (owner request B). */}
        {conflicts !== null ? (
          <div className="vl-problems">
            <Alert level="danger" icon="alert" title={t('staff.editConflictTitle')}>
              <ul>
                {conflicts.map((conflict) => (
                  <li key={conflict.product_id}>
                    {t('staff.editConflictLine', {
                      product:
                        conflict.name ??
                        items.find((i) => i.product_id === conflict.product_id)?.name ??
                        `#${conflict.product_id}`,
                      requested: conflict.requested,
                      available: conflict.available,
                    })}
                  </li>
                ))}
              </ul>
              <p style={{ marginTop: 'var(--sp-2)' }}>{t('staff.newOrderConflictHint')}</p>
            </Alert>
          </div>
        ) : null}

        {/* ------------------------------------------------------- student */}
        <Card title={t('staff.newOrderStudent')} headingLevel={2}>
          {selectedUser === null ? (
            <div className="vl-stack" style={{ gap: 'var(--sp-2)' }}>
              <SearchInput
                value={userSearch}
                onChange={setUserSearch}
                label={t('staff.newOrderStudent')}
                placeholder={t('staff.newOrderSearchUser')}
              />
              {userSearch.trim().length >= 2 ? (
                <div className="vl-stack" style={{ gap: 'var(--sp-1)' }}>
                  {(userResults.data?.data ?? []).map((user) => (
                    <Button
                      key={user.id}
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        setSelectedUser(user);
                        setUserSearch('');
                      }}
                    >
                      <Icon name="plus" size={14} />
                      {user.display_name} · {user.ldap_uid}
                    </Button>
                  ))}
                  {!userResults.isLoading && (userResults.data?.data ?? []).length === 0 ? (
                    <p className="vl-subtle">{t('staff.newOrderNoUsers')}</p>
                  ) : null}
                </div>
              ) : null}
            </div>
          ) : (
            <div className="vl-row" style={{ justifyContent: 'space-between' }}>
              <span>
                <strong>{selectedUser.display_name}</strong>{' '}
                <span className="vl-subtle vl-mono">{selectedUser.ldap_uid}</span>
              </span>
              <Button variant="ghost" size="sm" onClick={() => setSelectedUser(null)}>
                {t('staff.newOrderChangeUser')}
              </Button>
            </div>
          )}
        </Card>

        {/* ---------------------------------------------------- dates & window */}
        <Card title={t('cart.dates')} headingLevel={2}>
          <div className="vl-stack" style={{ gap: 'var(--sp-4)' }}>
            <Field label={t('cart.dates')} htmlFor="manual-dates">
              <DateRangePicker
                id="manual-dates"
                pickupDate={pickupDate}
                returnDate={returnDate}
                minDate="2000-01-01"
                respectClosures={false}
                onChange={({ pickup_date, return_date }) => {
                  setPickupDate(pickup_date);
                  setReturnDate(return_date);
                }}
              />
            </Field>

            {!customTimes && hasDates && live.check ? (
              <div className="vl-stack" style={{ gap: 'var(--sp-1)' }}>
                {live.check.pickup_window ? (
                  <p className="vl-window-note">
                    <Icon name="clock" size={14} />
                    {t('timeWindow.pickupLine', {
                      date: formatDate(pickupDate),
                      window: live.check.pickup_window,
                    })}
                  </p>
                ) : null}
                {live.check.return_window ? (
                  <p className="vl-window-note">
                    <Icon name="clock" size={14} />
                    {t('timeWindow.returnLine', {
                      date: formatDate(returnDate),
                      window: live.check.return_window,
                    })}
                  </p>
                ) : null}
              </div>
            ) : null}

            <div>
              <Switch
                checked={customTimes}
                onChange={setCustomTimes}
                label={t('timeWindow.customToggle')}
              />
              <p className="vl-subtle" style={{ marginTop: 'var(--sp-1)' }}>
                {t('timeWindow.customHint')}
              </p>
            </div>
            {customTimes ? (
              <div className="vl-form-grid vl-form-grid--2">
                <Field label={t('timeWindow.pickupStart')} htmlFor="manual-pickup-time">
                  <TextInput
                    id="manual-pickup-time"
                    type="time"
                    value={pickupTime}
                    onChange={(e) => setPickupTime(e.target.value)}
                  />
                </Field>
                <Field label={t('timeWindow.pickupEnd')} htmlFor="manual-pickup-time-end" optional>
                  <TextInput
                    id="manual-pickup-time-end"
                    type="time"
                    value={pickupTimeEnd}
                    onChange={(e) => setPickupTimeEnd(e.target.value)}
                  />
                </Field>
                <Field label={t('timeWindow.returnStart')} htmlFor="manual-return-time">
                  <TextInput
                    id="manual-return-time"
                    type="time"
                    value={returnTime}
                    onChange={(e) => setReturnTime(e.target.value)}
                  />
                </Field>
                <Field label={t('timeWindow.returnEnd')} htmlFor="manual-return-time-end" optional>
                  <TextInput
                    id="manual-return-time-end"
                    type="time"
                    value={returnTimeEnd}
                    onChange={(e) => setReturnTimeEnd(e.target.value)}
                  />
                </Field>
              </div>
            ) : null}
          </div>
        </Card>

        {/* ------------------------------------------------------------ items */}
        <Card title={t('staff.editItems')} headingLevel={2}>
          <div className="vl-stack" style={{ gap: 'var(--sp-3)' }}>
            {items.map((item) => (
              <div key={item.product_id} className="vl-stack" style={{ gap: 'var(--sp-1)' }}>
                <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                  <span style={{ flex: 1, minWidth: 0 }}>{item.name}</span>
                  <QuantityStepper
                    value={item.quantity}
                    onChange={(quantity) =>
                      setItems((prev) =>
                        prev.map((row) =>
                          row.product_id === item.product_id ? { ...row, quantity } : row,
                        ),
                      )
                    }
                    label={`${t('cart.quantity')} ${item.name}`}
                  />
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                      setItems((prev) => prev.filter((row) => row.product_id !== item.product_id))
                    }
                  >
                    {t('staff.editRemoveItem')}
                  </Button>
                </div>
                {hasDates ? (
                  <RowAvailability
                    entry={live.entryFor(item.product_id)}
                    checking={live.checking}
                    onSwap={(substitute) => swapRow(item.product_id, substitute)}
                  />
                ) : null}
              </div>
            ))}
            {items.length === 0 ? <p className="vl-subtle">{t('staff.editNoItems')}</p> : null}
            <SearchInput
              value={productSearch}
              onChange={setProductSearch}
              label={t('staff.editAddProduct')}
              placeholder={t('staff.editSearchProduct')}
            />
            {productSearch.trim().length >= 2 ? (
              <div className="vl-stack" style={{ gap: 'var(--sp-1)' }}>
                {(productResults.data?.data ?? [])
                  .filter((p) => !items.some((row) => row.product_id === p.id))
                  .map((p) => (
                    <div key={p.id} className="vl-row" style={{ gap: 'var(--sp-2)' }}>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          setItems((prev) => [...prev, { product_id: p.id, name: p.name, quantity: 1 }]);
                          setProductSearch('');
                        }}
                      >
                        <Icon name="plus" size={14} />
                        {p.name}
                      </Button>
                      {hasDates ? (
                        <AvailabilityBadge available={p.available_quantity} capacity={p.capacity} />
                      ) : null}
                    </div>
                  ))}
              </div>
            ) : null}
          </div>
        </Card>

        {/* ---------------------------------------------------------- details */}
        <Card title={t('orders.infoTitle')} headingLevel={2}>
          <div className="vl-stack" style={{ gap: 'var(--sp-4)' }}>
            <div className="vl-form-grid vl-form-grid--2">
              <Field label={t('checkout.subject')} htmlFor="manual-subject" optional>
                <TextInput
                  id="manual-subject"
                  value={subject}
                  onChange={(e) => setSubject(e.target.value)}
                />
              </Field>
              <Field label={t('orders.professorLabel')} htmlFor="manual-professor" optional>
                <TextInput
                  id="manual-professor"
                  value={professor}
                  onChange={(e) => setProfessor(e.target.value)}
                />
              </Field>
            </div>
            <Field label={t('checkout.motivation')} htmlFor="manual-motivation" optional>
              <TextArea
                id="manual-motivation"
                value={motivation}
                onChange={(e) => setMotivation(e.target.value)}
              />
            </Field>
            <Field label={t('checkout.notes')} htmlFor="manual-notes" optional>
              <TextArea id="manual-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </Field>
            <Field label={t('orders.staffNotes')} htmlFor="manual-staff-notes" optional>
              <TextArea
                id="manual-staff-notes"
                value={staffNotes}
                onChange={(e) => setStaffNotes(e.target.value)}
              />
            </Field>
          </div>
        </Card>

        {/* ------------------------------------------------- state & submit */}
        <Card title={t('staff.newOrderInitialStatus')} headingLevel={2}>
          <div className="vl-stack" style={{ gap: 'var(--sp-4)' }}>
            <Field label={t('staff.newOrderInitialStatus')} htmlFor="manual-initial-status">
              <Select
                id="manual-initial-status"
                value={initialStatus}
                onChange={(e) => setInitialStatus(e.target.value as 'approved' | 'pending')}
              >
                <option value="approved">{t('staff.newOrderStatusApproved')}</option>
                <option value="pending">{t('staff.newOrderStatusPending')}</option>
              </Select>
            </Field>
            {canForce && (liveConflicts.length > 0 || conflicts !== null) ? (
              <div>
                <Switch checked={force} onChange={setForce} label={t('staff.newOrderForce')} />
                {force ? (
                  <p className="vl-subtle" style={{ marginTop: 'var(--sp-2)' }}>
                    {t('staff.newOrderForceWarning')}
                  </p>
                ) : null}
              </div>
            ) : null}
          </div>
        </Card>

        <div className="vl-stack" style={{ gap: 'var(--sp-2)', alignItems: 'flex-end' }}>
          <div className="vl-row">
            <Link to="/gestione/ordini" className="vl-btn vl-btn--ghost">
              {t('app.cancel')}
            </Link>
            <Button variant="primary" disabled={!valid} loading={create.isPending} onClick={submit}>
              {t('staff.newOrderCreate')}
            </Button>
          </div>
          {blockedByAvailability ? (
            <p className="vl-field__error" role="alert" style={{ margin: 0 }}>
              {t('liveCheck.conflictsBlock')}
            </p>
          ) : null}
        </div>
      </div>
    </>
  );
}
