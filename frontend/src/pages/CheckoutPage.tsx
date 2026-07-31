import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQueries, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { useCartQuery, useCartMutations, CART_KEY } from '@/hooks/useCart';
import { useSettings } from '@/settings/SettingsProvider';
import { useToast } from '@/components/Toast';
import {
  DateRangePicker,
  LimitWarningList,
  RegulationAcceptBlock,
  TimeSlotPicker,
} from '@/components/domain';
import {
  Alert,
  Button,
  Card,
  ConfirmDialog,
  Field,
  Skeleton,
  TextArea,
  TextInput,
} from '@/components/ui';
import { t } from '@/i18n/it';
import { addDaysIso, formatDate, inclusiveDays, todayIso } from '@/lib/format';
import type { AvailabilityCheckResponse } from '@/types/api';

interface FormState {
  subject: string;
  motivation: string;
  professor: string;
  notes: string;
  pickup_time: string;
  return_time: string;
}

export function CheckoutPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const cart = useCartQuery();
  const { setDates } = useCartMutations();
  const { get } = useSettings();
  const { pushError } = useToast();

  const requireMotivation = get<boolean>('booking.require_motivation', true);
  const motivationMin = get<number>('booking.motivation_min_length', 20);
  const requireProfessor = get<boolean>('booking.require_professor', false);
  const minAdvance = get<number>('booking.min_advance_days', 1);
  const maxAdvance = get<number>('booking.max_advance_days', 90);

  const [form, setForm] = useState<FormState>({
    subject: '',
    motivation: '',
    professor: '',
    notes: '',
    pickup_time: '',
    return_time: '',
  });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [accepted, setAccepted] = useState<Record<number, boolean>>({});
  const [check, setCheck] = useState<AvailabilityCheckResponse | null>(null);
  const [checking, setChecking] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [availabilityError, setAvailabilityError] = useState<
    { product_id: number; requested: number; available: number }[] | null
  >(null);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const cartData = cart.data;

  /* Seed the time fields from the cart once loaded. */
  useEffect(() => {
    if (!cartData) return;
    setForm((prev) => ({
      ...prev,
      pickup_time: prev.pickup_time || (cartData.pickup_time ?? ''),
      return_time: prev.return_time || (cartData.return_time ?? ''),
    }));
  }, [cartData]);

  /* Continuous pre-flight validation, debounced 400 ms (SPEC §11.6). */
  useEffect(() => {
    if (!cartData || !cartData.pickup_date || !cartData.return_date) return;
    if (debounceRef.current) clearTimeout(debounceRef.current);
    setChecking(true);
    debounceRef.current = setTimeout(() => {
      api
        .postAvailabilityCheck({
          items: cartData.items.map((item) => ({
            product_id: item.product_id,
            quantity: item.quantity,
          })),
          pickup_date: cartData.pickup_date,
          pickup_time: form.pickup_time || cartData.pickup_time,
          return_date: cartData.return_date,
          return_time: form.return_time || cartData.return_time,
        })
        .then(setCheck)
        .catch(pushError)
        .finally(() => setChecking(false));
    }, 400);
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [cartData, form.pickup_time, form.return_time, pushError]);

  const requiredRegulations = useMemo(
    () => (check?.required_regulations ?? []).filter((reg) => !reg.accepted),
    [check],
  );

  const regulationDetails = useQueries({
    queries: requiredRegulations.map((reg) => ({
      queryKey: ['regulation', reg.id],
      queryFn: () => api.getRegulation(reg.id),
    })),
  });

  const allRegulationsAccepted = requiredRegulations.every((reg) => accepted[reg.id]);

  const createOrder = useMutation({
    mutationFn: (acknowledge: boolean) =>
      api.createOrder({
        from_cart: true,
        pickup_date: cartData?.pickup_date,
        pickup_time: form.pickup_time || cartData?.pickup_time,
        return_date: cartData?.return_date,
        return_time: form.return_time || cartData?.return_time,
        subject: form.subject,
        motivation: form.motivation || null,
        professor: form.professor || null,
        notes: form.notes || null,
        accepted_regulation_ids: requiredRegulations.filter((r) => accepted[r.id]).map((r) => r.id),
        acknowledge_exceeds_limits: acknowledge,
      }),
    onSuccess: (order) => {
      void queryClient.invalidateQueries({ queryKey: CART_KEY });
      void queryClient.invalidateQueries({ queryKey: ['orders'] });
      navigate(`/ordini/${order.id}`, { state: { justCreated: true } });
    },
    onError: (error) => {
      setConfirmOpen(false);
      if (error instanceof ApiError) {
        if (error.code === 'validation_failed') {
          const mapped: Record<string, string> = {};
          for (const [field, messages] of Object.entries(error.fieldErrors)) {
            if (messages[0]) mapped[field] = messages[0];
          }
          setFieldErrors(mapped);
        } else if (error.code === 'insufficient_availability') {
          const products = (error.details?.['products'] ?? []) as {
            product_id: number;
            requested: number;
            available: number;
          }[];
          setAvailabilityError(products);
        }
      }
      pushError(error);
    },
  });

  function validate(): boolean {
    const errors: Record<string, string> = {};
    if (!form.subject.trim()) errors['subject'] = t('checkout.fieldRequired');
    if (requireMotivation && form.motivation.trim().length < motivationMin) {
      errors['motivation'] = t('checkout.motivationTooShort', { n: motivationMin });
    }
    if (requireProfessor && !form.professor.trim()) errors['professor'] = t('checkout.fieldRequired');
    if (!allRegulationsAccepted) errors['regulations'] = t('checkout.regulationsPending');
    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    setAvailabilityError(null);
    if (!validate()) return;
    if (check?.exceeds_limits) {
      setConfirmOpen(true);
      return;
    }
    createOrder.mutate(false);
  }

  if (cart.isLoading) {
    return (
      <div className="vl-container vl-page">
        <h1>{t('checkout.title')}</h1>
        <Skeleton height={280} radius={6} />
      </div>
    );
  }

  if (!cartData || cartData.items.length === 0) {
    return (
      <div className="vl-container vl-page">
        <h1>{t('checkout.title')}</h1>
        <p className="vl-lead">{t('cart.emptyBody')}</p>
        <Link to="/catalogo" className="vl-btn vl-btn--primary">
          {t('cart.goToCatalog')}
        </Link>
      </div>
    );
  }

  const canSubmit = check ? check.can_submit : true;
  const duration = inclusiveDays(cartData.pickup_date, cartData.return_date);

  return (
    <div className="vl-container vl-page">
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.cart')}</p>
        <h1>{t('checkout.title')}</h1>
        <p className="vl-lead">{t('checkout.lead')}</p>
      </div>

      <form onSubmit={onSubmit} noValidate>
        <div className="vl-cart">
          <div className="vl-stack">
            {availabilityError ? (
              <Alert level="danger" icon="alert" title={t('checkout.insufficientTitle')}>
                <p>{t('checkout.insufficientBody')}</p>
                <ul>
                  {availabilityError.map((entry) => {
                    const item = cartData.items.find((i) => i.product_id === entry.product_id);
                    return (
                      <li key={entry.product_id}>
                        {item?.product.name ?? `#${entry.product_id}`}: {entry.available}/{entry.requested}
                      </li>
                    );
                  })}
                </ul>
                <Link to="/carrello" className="vl-btn vl-btn--ghost vl-btn--sm">
                  {t('checkout.recheckDates')}
                </Link>
              </Alert>
            ) : null}

            <Card title={t('orders.infoTitle')} headingLevel={2}>
              <div className="vl-stack">
                <Field label={t('checkout.subject')} htmlFor="co-subject" error={fieldErrors['subject']}>
                  <TextInput
                    id="co-subject"
                    name="subject"
                    value={form.subject}
                    aria-invalid={fieldErrors['subject'] ? true : undefined}
                    aria-describedby={fieldErrors['subject'] ? 'co-subject-error' : undefined}
                    placeholder={t('checkout.subjectPlaceholder')}
                    onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))}
                  />
                </Field>

                <Field
                  label={t('checkout.motivation')}
                  htmlFor="co-motivation"
                  hint={requireMotivation ? t('checkout.motivationHint', { n: motivationMin }) : undefined}
                  error={fieldErrors['motivation']}
                  optional={!requireMotivation}
                >
                  <TextArea
                    id="co-motivation"
                    name="motivation"
                    value={form.motivation}
                    aria-invalid={fieldErrors['motivation'] ? true : undefined}
                    aria-describedby={
                      fieldErrors['motivation'] ? 'co-motivation-error' : 'co-motivation-hint'
                    }
                    placeholder={t('checkout.motivationPlaceholder')}
                    onChange={(e) => setForm((f) => ({ ...f, motivation: e.target.value }))}
                  />
                </Field>

                <Field
                  label={t('checkout.professor')}
                  htmlFor="co-professor"
                  error={fieldErrors['professor']}
                  optional={!requireProfessor}
                >
                  <TextInput
                    id="co-professor"
                    name="professor"
                    value={form.professor}
                    placeholder={t('checkout.professorPlaceholder')}
                    onChange={(e) => setForm((f) => ({ ...f, professor: e.target.value }))}
                  />
                </Field>

                <Field label={t('checkout.notes')} htmlFor="co-notes" optional error={fieldErrors['notes']}>
                  <TextArea
                    id="co-notes"
                    name="notes"
                    value={form.notes}
                    placeholder={t('checkout.notesPlaceholder')}
                    onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
                  />
                </Field>
              </div>
            </Card>

            <Card title={t('cart.dates')} headingLevel={2}>
              <DateRangePicker
                pickupDate={cartData.pickup_date}
                returnDate={cartData.return_date}
                minDate={addDaysIso(todayIso(), minAdvance)}
                maxDate={addDaysIso(todayIso(), maxAdvance)}
                onChange={({ pickup_date, return_date }) =>
                  setDates.mutate({ pickup_date, return_date }, { onError: pushError })
                }
              />
              <p className="vl-subtle" style={{ marginTop: 'var(--sp-3)' }}>
                {formatDate(cartData.pickup_date)} → {formatDate(cartData.return_date)}
                {duration ? ` · ${t('cart.duration', { n: duration })}` : ''}
              </p>
              <div className="vl-form-grid vl-form-grid--2" style={{ marginTop: 'var(--sp-4)' }}>
                <TimeSlotPicker
                  slots={check?.pickup_slots ?? []}
                  value={form.pickup_time || cartData.pickup_time}
                  label={t('cart.pickupTime')}
                  onChange={(value) => setForm((f) => ({ ...f, pickup_time: value }))}
                />
                <TimeSlotPicker
                  slots={check?.return_slots ?? []}
                  value={form.return_time || cartData.return_time}
                  label={t('cart.returnTime')}
                  onChange={(value) => setForm((f) => ({ ...f, return_time: value }))}
                />
              </div>
            </Card>

            {requiredRegulations.length > 0 ? (
              <Card title={t('checkout.regulations')} headingLevel={2}>
                <div className="vl-stack">
                  {requiredRegulations.map((reg, index) => {
                    const detail = regulationDetails[index]?.data;
                    return (
                      <RegulationAcceptBlock
                        key={reg.id}
                        regulation={{
                          id: reg.id,
                          slug: reg.slug,
                          title: reg.title,
                          summary: detail?.summary ?? null,
                          version: reg.version,
                          body: detail?.body ?? null,
                          content_type: detail?.content_type ?? 'markdown',
                          file_url: detail?.file_url ?? null,
                        }}
                        checked={Boolean(accepted[reg.id])}
                        onChange={(value) => setAccepted((prev) => ({ ...prev, [reg.id]: value }))}
                      />
                    );
                  })}
                  {fieldErrors['regulations'] ? (
                    <span className="vl-field__error" role="alert">
                      {fieldErrors['regulations']}
                    </span>
                  ) : null}
                </div>
              </Card>
            ) : null}

            {check ? <LimitWarningList violations={check.violations} /> : null}
          </div>

          <aside className="vl-cart__aside">
            <Card title={t('checkout.summary')} headingLevel={2}>
              <ul className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)' }}>
                {cartData.items.map((item) => (
                  <li key={item.id} className="vl-row" style={{ justifyContent: 'space-between' }}>
                    <span style={{ flex: 1, minWidth: 0 }}>{item.product.name}</span>
                    <strong>×{item.quantity}</strong>
                  </li>
                ))}
              </ul>
              <div style={{ marginTop: 'var(--sp-5)' }}>
                <Button
                  type="submit"
                  variant="primary"
                  size="lg"
                  block
                  disabled={!canSubmit || checking}
                  loading={createOrder.isPending}
                >
                  {check?.exceeds_limits ? t('checkout.submitAnyway') : t('checkout.submit')}
                </Button>
                {!canSubmit ? (
                  <p className="vl-field__error" style={{ marginTop: 'var(--sp-2)' }} role="alert">
                    {t('checkout.blockedTitle')}
                  </p>
                ) : null}
              </div>
            </Card>
          </aside>
        </div>
      </form>

      <ConfirmDialog
        open={confirmOpen}
        title={t('checkout.exceedsTitle')}
        body={t('checkout.exceedsBody')}
        confirmLabel={t('checkout.exceedsConfirm')}
        loading={createOrder.isPending}
        onCancel={() => setConfirmOpen(false)}
        onConfirm={() => createOrder.mutate(true)}
      >
        {check ? <LimitWarningList violations={check.violations} /> : null}
      </ConfirmDialog>
    </div>
  );
}
