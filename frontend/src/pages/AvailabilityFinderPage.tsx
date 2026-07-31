import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';
import { useCartMutations, useCartQuery } from '@/hooks/useCart';
import { useToast } from '@/components/Toast';
import { AvailabilityCalendar, DateRangePicker, type HeatDay } from '@/components/domain';
import {
  Button,
  Card,
  EmptyState,
  Field,
  ProductImage,
  QuantityStepper,
  SearchInput,
  Skeleton,
  TextInput,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { addDaysIso, formatDate, todayIso } from '@/lib/format';
import type { AvailabilityDatesResponse, ProductSummary } from '@/types/api';

interface Selected {
  product: ProductSummary;
  quantity: number;
}

export function AvailabilityFinderPage() {
  const navigate = useNavigate();
  const { permissions } = useAuth();
  const { setDates } = useCartMutations();
  const { push, pushError } = useToast();
  const cart = useCartQuery(permissions['orders.create']);

  const [selected, setSelected] = useState<Selected[]>([]);
  const [seededFromCart, setSeededFromCart] = useState(false);
  const [search, setSearch] = useState('');
  const [duration, setDuration] = useState(3);
  const [from, setFrom] = useState(todayIso());
  const [to, setTo] = useState(addDaysIso(todayIso(), 60));
  const [result, setResult] = useState<AvailabilityDatesResponse | null>(null);

  /* Seed the scratch list from the cart once, for logged-in students. */
  useEffect(() => {
    if (seededFromCart || !cart.data || cart.data.items.length === 0) return;
    setSelected(cart.data.items.map((item) => ({ product: item.product, quantity: item.quantity })));
    setSeededFromCart(true);
  }, [cart.data, seededFromCart]);

  const searchQuery = useQuery({
    queryKey: ['product-search', search],
    queryFn: () => api.getProducts({ q: search, per_page: 6 }),
    enabled: search.trim().length >= 2,
  });

  const datesMutation = useMutation({
    mutationFn: () =>
      api.postAvailabilityDates({
        items: selected.map((s) => ({ product_id: s.product.id, quantity: s.quantity })),
        from,
        to,
        duration_days: duration,
      }),
    onSuccess: setResult,
    onError: pushError,
  });

  const heatDays: HeatDay[] = useMemo(
    () =>
      (result?.days ?? []).map((day) => {
        const insufficient = day.per_product.filter((p) => !p.sufficient).length;
        const state: HeatDay['state'] = !day.is_open
          ? 'closed'
          : day.all_available
            ? 'ok'
            : insufficient === day.per_product.length
              ? 'ko'
              : 'partial';
        return { date: day.date, state };
      }),
    [result],
  );

  const productNames = useMemo(() => {
    const map = new Map<number, string>();
    for (const item of selected) map.set(item.product.id, item.product.name);
    return map;
  }, [selected]);

  function addProduct(product: ProductSummary) {
    setSelected((prev) =>
      prev.some((s) => s.product.id === product.id)
        ? prev
        : [...prev, { product, quantity: 1 }],
    );
    setSearch('');
  }

  async function chooseWindow(pickupDate: string, returnDate: string) {
    if (!permissions['orders.create']) {
      navigate(`/login?next=${encodeURIComponent('/disponibilita')}`);
      return;
    }
    try {
      await setDates.mutateAsync({ pickup_date: pickupDate, return_date: returnDate });
      push(t('cart.dates'), 'success');
      navigate('/carrello');
    } catch (error) {
      pushError(error);
    }
  }

  const windows = result?.windows ?? [];
  const availableWindows = windows.filter((w) => w.all_available);
  const blockedWindows = windows.filter((w) => !w.all_available).slice(0, 5);

  return (
    <div className="vl-container vl-page">
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.availability')}</p>
        <h1>{t('availabilityFinder.title')}</h1>
        <p className="vl-lead">{t('availabilityFinder.lead')}</p>
      </div>

      <div className="vl-split">
        <div className="vl-stack">
          <Card title={t('availabilityFinder.selection')} headingLevel={2}>
            {selected.length === 0 ? (
              <p className="vl-subtle">{t('availabilityFinder.selectionEmpty')}</p>
            ) : (
              <ul className="vl-stack" style={{ gap: 'var(--sp-3)' }}>
                {selected.map((item) => (
                  <li
                    key={item.product.id}
                    className="vl-row"
                    style={{ borderBottom: '1px solid var(--color-line)', paddingBottom: 'var(--sp-3)' }}
                  >
                    <div style={{ width: 52, flex: 'none' }}>
                      <ProductImage src={item.product.image_url} alt="" />
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>{item.product.name}</div>
                      <div className="vl-subtle">{item.product.category.name}</div>
                    </div>
                    <QuantityStepper
                      value={item.quantity}
                      label={t('product.quantity')}
                      onChange={(quantity) =>
                        setSelected((prev) =>
                          prev.map((s) => (s.product.id === item.product.id ? { ...s, quantity } : s)),
                        )
                      }
                    />
                    <Button
                      size="sm"
                      variant="quiet"
                      aria-label={t('availabilityFinder.removeFromSelection')}
                      onClick={() =>
                        setSelected((prev) => prev.filter((s) => s.product.id !== item.product.id))
                      }
                    >
                      <Icon name="trash" size={15} />
                    </Button>
                  </li>
                ))}
              </ul>
            )}

            <div style={{ marginTop: 'var(--sp-4)' }}>
              <SearchInput
                value={search}
                onChange={setSearch}
                label={t('availabilityFinder.addProduct')}
                placeholder={t('availabilityFinder.searchProduct')}
              />
              {searchQuery.data && search.trim().length >= 2 ? (
                <ul className="vl-stack" style={{ gap: 'var(--sp-1)', marginTop: 'var(--sp-2)' }}>
                  {searchQuery.data.data.map((product) => (
                    <li key={product.id}>
                      <button
                        type="button"
                        className="vl-facet__item"
                        onClick={() => addProduct(product)}
                      >
                        <span>{product.name}</span>
                        <Icon name="plus" size={14} />
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          </Card>

          {result ? (
            <Card title={t('availabilityFinder.heatmapTitle')} headingLevel={2}>
              <AvailabilityCalendar days={heatDays} />
            </Card>
          ) : null}
        </div>

        <div className="vl-stack">
          <Card title={t('availabilityFinder.searchTitle')} headingLevel={2}>
            <div className="vl-stack">
              <Field label={t('availabilityFinder.duration')} htmlFor="finder-duration">
                <TextInput
                  id="finder-duration"
                  type="number"
                  min={1}
                  max={60}
                  value={duration}
                  onChange={(e) => setDuration(Math.max(1, Number(e.target.value) || 1))}
                />
              </Field>
              <div className="vl-field">
                <span className="vl-field__label">{t('availabilityFinder.horizon')}</span>
                <DateRangePicker
                  pickupDate={from}
                  returnDate={to}
                  minDate={todayIso()}
                  maxDate={addDaysIso(todayIso(), 365)}
                  label={t('availabilityFinder.horizon')}
                  respectClosures={false}
                  onChange={({ pickup_date, return_date }) => {
                    if (pickup_date && return_date) {
                      setFrom(pickup_date);
                      setTo(return_date);
                    } else {
                      setFrom(todayIso());
                      setTo(addDaysIso(todayIso(), 60));
                    }
                  }}
                />
              </div>
              <Button
                variant="primary"
                block
                disabled={selected.length === 0}
                loading={datesMutation.isPending}
                onClick={() => datesMutation.mutate()}
              >
                {datesMutation.isPending
                  ? t('availabilityFinder.calculating')
                  : t('availabilityFinder.submit')}
              </Button>
            </div>
          </Card>

          {datesMutation.isPending ? <Skeleton height={160} radius={6} /> : null}

          {result ? (
            <Card title={t('availabilityFinder.windowsTitle')} headingLevel={2}>
              {result.first_available_window ? (
                <div className="vl-alert vl-alert--vr" style={{ marginBottom: 'var(--sp-4)' }}>
                  <Icon name="check" size={18} />
                  <div className="vl-alert__body">
                    <div className="vl-alert__title">{t('availabilityFinder.firstWindow')}</div>
                    {formatDate(result.first_available_window.pickup_date)} →{' '}
                    {formatDate(result.first_available_window.return_date)}
                  </div>
                </div>
              ) : null}

              {availableWindows.length === 0 ? (
                <EmptyState
                  icon="calendar"
                  title={t('availabilityFinder.windowsEmpty')}
                  body={t('availabilityFinder.windowsEmptyBody')}
                />
              ) : (
                <ul className="vl-stack" style={{ gap: 'var(--sp-2)' }}>
                  {availableWindows.slice(0, 12).map((w) => (
                    <li
                      key={`${w.pickup_date}-${w.return_date}`}
                      className="vl-row"
                      style={{
                        border: '1px solid var(--color-line)',
                        borderRadius: 'var(--radius-sm)',
                        padding: 'var(--sp-3)',
                      }}
                    >
                      <div style={{ flex: 1 }}>
                        <div style={{ fontWeight: 600, fontSize: 'var(--fs-sm)' }}>
                          {formatDate(w.pickup_date)} → {formatDate(w.return_date)}
                        </div>
                        <div className="vl-subtle">{w.days} giorni</div>
                      </div>
                      <Button
                        size="sm"
                        variant="primary"
                        onClick={() => void chooseWindow(w.pickup_date, w.return_date)}
                      >
                        {t('availabilityFinder.chooseWindow')}
                      </Button>
                    </li>
                  ))}
                </ul>
              )}

              {blockedWindows.length > 0 ? (
                <div style={{ marginTop: 'var(--sp-4)' }}>
                  <p className="vl-eyebrow">{t('availabilityFinder.legendUnavailable')}</p>
                  <ul className="vl-stack" style={{ gap: 'var(--sp-2)' }}>
                    {blockedWindows.map((w) => (
                      <li key={`b-${w.pickup_date}`} className="vl-subtle">
                        {formatDate(w.pickup_date)} → {formatDate(w.return_date)} ·{' '}
                        {t('availabilityFinder.blockedBy', {
                          products: w.blocking_product_ids
                            .map((id) => productNames.get(id) ?? `#${id}`)
                            .join(', '),
                        })}
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}

              {result.unavailable_products.length > 0 ? (
                <div style={{ marginTop: 'var(--sp-4)' }}>
                  <p className="vl-eyebrow">{t('availabilityFinder.unavailableProducts')}</p>
                  <ul>
                    {result.unavailable_products.map((item) => (
                      <li key={item.product_id} className="vl-subtle">
                        {productNames.get(item.product_id) ?? `#${item.product_id}`}
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
            </Card>
          ) : null}
        </div>
      </div>
    </div>
  );
}
