import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { usePermission } from '@/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { DateRangePicker } from '@/components/domain';
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
import { todayIso } from '@/lib/format';
import type { Order, OrderOverbookedProduct, User } from '@/types/api';

interface ItemRow {
  product_id: number;
  name: string;
  quantity: number;
}

/**
 * /gestione/ordini/nuovo — staff registers a loan on behalf of a student
 * (`orders.create_manual`): the walk-in at the counter, a phone booking, an
 * after-the-fact correction. Mirrors the admin full-edit panel's look; the
 * availability force override stays admin-only (`orders.edit_full`).
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
  const [pickupTime, setPickupTime] = useState('');
  const [returnTime, setReturnTime] = useState('');
  const [subject, setSubject] = useState('');
  const [professor, setProfessor] = useState('');
  const [motivation, setMotivation] = useState('');
  const [notes, setNotes] = useState('');
  const [staffNotes, setStaffNotes] = useState('');
  const [initialStatus, setInitialStatus] = useState<'approved' | 'pending'>('approved');
  const [force, setForce] = useState(false);
  const [conflicts, setConflicts] = useState<OrderOverbookedProduct[] | null>(null);

  const userResults = useQuery({
    queryKey: ['manual-user-search', userSearch],
    queryFn: () => api.getUsers({ q: userSearch, per_page: 8 }),
    enabled: userSearch.trim().length >= 2,
  });

  const productResults = useQuery({
    queryKey: ['manual-product-search', productSearch],
    queryFn: () => api.getProducts({ q: productSearch, per_page: 8 }),
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

  const valid =
    selectedUser !== null &&
    items.length > 0 &&
    pickupDate !== null &&
    returnDate !== null &&
    pickupTime !== '' &&
    returnTime !== '';

  function submit() {
    if (!valid || selectedUser === null) return;
    const body: Record<string, unknown> = {
      user_id: selectedUser.id,
      items: items.map(({ product_id, quantity }) => ({ product_id, quantity })),
      start_date: pickupDate,
      end_date: returnDate,
      pickup_time: pickupTime,
      return_time: returnTime,
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
        {conflicts !== null ? (
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

        {/* ---------------------------------------------------- dates & times */}
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
            <div className="vl-form-grid vl-form-grid--2">
              <Field label={t('orders.pickup')} htmlFor="manual-pickup-time">
                <TextInput
                  id="manual-pickup-time"
                  type="time"
                  value={pickupTime}
                  onChange={(e) => setPickupTime(e.target.value)}
                />
              </Field>
              <Field label={t('orders.return')} htmlFor="manual-return-time">
                <TextInput
                  id="manual-return-time"
                  type="time"
                  value={returnTime}
                  onChange={(e) => setReturnTime(e.target.value)}
                />
              </Field>
            </div>
          </div>
        </Card>

        {/* ------------------------------------------------------------ items */}
        <Card title={t('staff.editItems')} headingLevel={2}>
          <div className="vl-stack" style={{ gap: 'var(--sp-3)' }}>
            {items.map((item) => (
              <div key={item.product_id} className="vl-row" style={{ justifyContent: 'space-between' }}>
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
                    <Button
                      key={p.id}
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
            {canForce ? (
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

        <div className="vl-row" style={{ justifyContent: 'flex-end' }}>
          <Link to="/gestione/ordini" className="vl-btn vl-btn--ghost">
            {t('app.cancel')}
          </Link>
          <Button variant="primary" disabled={!valid} loading={create.isPending} onClick={submit}>
            {t('staff.newOrderCreate')}
          </Button>
        </div>
      </div>
    </>
  );
}
