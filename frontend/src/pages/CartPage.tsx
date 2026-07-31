import { Link, useNavigate } from 'react-router';
import { useState } from 'react';
import { useCartMutations, useCartQuery } from '@/hooks/useCart';
import { useToast } from '@/components/Toast';
import { useSettings } from '@/settings/SettingsProvider';
import { AvailabilityBadge, DateRangePicker, LimitWarningList, TimeSlotPicker } from '@/components/domain';
import {
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  ProductImage,
  QuantityStepper,
  Skeleton,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { addDaysIso, formatDate, inclusiveDays, todayIso } from '@/lib/format';
import type { SuggestedSubstitute } from '@/types/api';

export function CartPage() {
  const navigate = useNavigate();
  const cart = useCartQuery();
  const { patchItem, removeItem, swapItem, setDates, empty } = useCartMutations();
  const { push, pushError } = useToast();
  const { get } = useSettings();
  const [confirmEmpty, setConfirmEmpty] = useState(false);

  const minAdvance = get<number>('booking.min_advance_days', 1);
  const maxAdvance = get<number>('booking.max_advance_days', 90);
  const maxQty = get<number>('booking.max_quantity_per_product_per_order', 2);

  if (cart.isLoading) {
    return (
      <div className="vl-container vl-page">
        <h1>{t('cart.title')}</h1>
        <div style={{ marginTop: 'var(--sp-5)' }}>
          <Skeleton height={220} radius={6} />
        </div>
      </div>
    );
  }

  const data = cart.data;
  const check = data?.check ?? null;
  const duration = inclusiveDays(data?.pickup_date ?? null, data?.return_date ?? null);

  const suggestionsFor = (productId: number): SuggestedSubstitute[] =>
    check?.availability.find((entry) => entry.product_id === productId)?.suggested_substitutes ?? [];

  const swapTo = (itemId: number, substitute: SuggestedSubstitute) =>
    swapItem.mutate(
      { itemId, productId: substitute.product_id },
      {
        onSuccess: () => push(t('cart.substituteDone', { name: substitute.name }), 'success'),
        onError: pushError,
      },
    );

  if (!data || data.items.length === 0) {
    return (
      <div className="vl-container vl-page">
        <div className="vl-page-head">
          <p className="vl-eyebrow">{t('nav.cart')}</p>
          <h1>{t('cart.title')}</h1>
        </div>
        <EmptyState
          icon="cart"
          title={t('cart.empty')}
          body={t('cart.emptyBody')}
          action={
            <Link to="/catalogo" className="vl-btn vl-btn--primary">
              {t('cart.goToCatalog')}
            </Link>
          }
        />
      </div>
    );
  }

  return (
    <div className="vl-container vl-page">
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.cart')}</p>
        <h1>{t('cart.title')}</h1>
        <p className="vl-lead">{t('cart.lead')}</p>
      </div>

      <div className="vl-cart">
        <div className="vl-stack">
          <Card
            title={`${data.items_count} ${data.items_count === 1 ? t('cart.item') : t('cart.items')}`}
            headingLevel={2}
            actions={
              <Button size="sm" variant="quiet" onClick={() => setConfirmEmpty(true)}>
                {t('cart.emptyCartAction')}
              </Button>
            }
          >
            <div style={{ margin: 'calc(-1 * var(--sp-5))' }}>
              {data.items.map((item) => (
                <div key={item.id} className="vl-cartitem">
                  <div className="vl-cartitem__media">
                    <Link to={`/prodotto/${item.product.slug}`} tabIndex={-1} aria-hidden="true">
                      <ProductImage src={item.product.image_url} alt="" />
                    </Link>
                  </div>
                  <div className="vl-cartitem__body">
                    <div>
                      <span className="vl-pcard__cat">{item.product.category.name}</span>
                      <h3 style={{ fontSize: 'var(--fs-md)', margin: '2px 0' }}>
                        <Link to={`/prodotto/${item.product.slug}`}>{item.product.name}</Link>
                      </h3>
                      {item.product.brand ? <span className="vl-subtle">{item.product.brand}</span> : null}
                    </div>
                    <div className="vl-row">
                      <QuantityStepper
                        value={item.quantity}
                        max={maxQty}
                        label={`${t('cart.quantity')} — ${item.product.name}`}
                        onChange={(quantity) =>
                          patchItem.mutate(
                            { itemId: item.id, quantity },
                            { onError: pushError },
                          )
                        }
                      />
                      {item.available_quantity !== null ? (
                        <AvailabilityBadge available={item.available_quantity} />
                      ) : (
                        <span className="vl-subtle">{t('cart.datesMissing')}</span>
                      )}
                      <span className="vl-spacer" />
                      <Button
                        size="sm"
                        variant="quiet"
                        onClick={() =>
                          removeItem.mutate(item.id, {
                            onSuccess: () => push(t('cart.removed'), 'success'),
                            onError: pushError,
                          })
                        }
                        aria-label={`${t('cart.remove')} — ${item.product.name}`}
                      >
                        <Icon name="trash" size={15} />
                        {t('cart.remove')}
                      </Button>
                    </div>
                    {item.sufficient === false
                      ? (() => {
                          const suggestions = suggestionsFor(item.product_id);
                          const top = suggestions[0];
                          const others = suggestions.slice(1);
                          return (
                            <div
                              className="vl-substitute"
                              data-testid={`substitute-${item.product_id}`}
                            >
                              <p style={{ margin: 0 }}>
                                <strong>{t('cart.availabilityKo')}.</strong>{' '}
                                {top ? t('cart.substituteIntro') : t('cart.noSubstitutes')}
                              </p>
                              {top ? (
                                <div className="vl-substitute__top">
                                  <div className="vl-substitute__thumb">
                                    <ProductImage src={top.image_url} alt="" />
                                  </div>
                                  <div style={{ minWidth: 0 }}>
                                    <div className="vl-substitute__name">
                                      <Link to={`/prodotto/${top.slug}`}>{top.name}</Link>
                                    </div>
                                    <span className="vl-subtle">
                                      {t('cart.substituteAvailable', { n: top.available_quantity })}
                                    </span>
                                  </div>
                                  <span className="vl-spacer" />
                                  {/* Orange stays on the page's one real CTA
                                      ("Prosegui"); the swap is an outline. */}
                                  <Button
                                    size="sm"
                                    variant="outline-accent"
                                    loading={swapItem.isPending}
                                    onClick={() => swapTo(item.id, top)}
                                  >
                                    {t('cart.substituteWith', { name: top.name })}
                                  </Button>
                                </div>
                              ) : null}
                              {others.length > 0 ? (
                                <details>
                                  <summary>{t('cart.otherAlternatives')}</summary>
                                  {others.map((alt) => (
                                    <div key={alt.product_id} className="vl-substitute__alt">
                                      <div className="vl-substitute__thumb">
                                        <ProductImage src={alt.image_url} alt="" />
                                      </div>
                                      <div style={{ minWidth: 0 }}>
                                        <div className="vl-substitute__name">
                                          <Link to={`/prodotto/${alt.slug}`}>{alt.name}</Link>
                                        </div>
                                        <span className="vl-subtle">
                                          {t('cart.substituteAvailable', {
                                            n: alt.available_quantity,
                                          })}
                                        </span>
                                      </div>
                                      <span className="vl-spacer" />
                                      <Button
                                        size="sm"
                                        variant="ghost"
                                        loading={swapItem.isPending}
                                        onClick={() => swapTo(item.id, alt)}
                                      >
                                        {t('cart.substituteWith', { name: alt.name })}
                                      </Button>
                                    </div>
                                  ))}
                                </details>
                              ) : null}
                            </div>
                          );
                        })()
                      : null}
                  </div>
                </div>
              ))}
            </div>
          </Card>

          <Card title={t('cart.dates')} headingLevel={2}>
            <DateRangePicker
              pickupDate={data.pickup_date}
              returnDate={data.return_date}
              minDate={addDaysIso(todayIso(), minAdvance)}
              maxDate={addDaysIso(todayIso(), maxAdvance)}
              onChange={({ pickup_date, return_date }) =>
                setDates.mutate({ pickup_date, return_date }, { onError: pushError })
              }
            />
            {duration ? (
              <p className="vl-subtle" style={{ marginTop: 'var(--sp-3)' }}>
                {t('cart.duration', { n: duration })}
              </p>
            ) : null}

            {check ? (
              <div className="vl-form-grid vl-form-grid--2" style={{ marginTop: 'var(--sp-4)' }}>
                <TimeSlotPicker
                  slots={check.pickup_slots}
                  value={data.pickup_time}
                  label={t('cart.pickupTime')}
                  onChange={(value) => setDates.mutate({ pickup_time: value }, { onError: pushError })}
                />
                <TimeSlotPicker
                  slots={check.return_slots}
                  value={data.return_time}
                  label={t('cart.returnTime')}
                  onChange={(value) => setDates.mutate({ return_time: value }, { onError: pushError })}
                />
              </div>
            ) : null}
          </Card>

          {check ? <LimitWarningList violations={check.violations} /> : null}
        </div>

        <aside className="vl-cart__aside">
          <Card title={t('checkout.summary')} headingLevel={2}>
            <dl className="vl-stack" style={{ gap: 'var(--sp-2)', margin: 0, fontSize: 'var(--fs-sm)' }}>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('cart.items')}</dt>
                <dd style={{ margin: 0 }}>{data.items_count}</dd>
              </div>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('cart.pickupDate')}</dt>
                <dd style={{ margin: 0 }}>{formatDate(data.pickup_date)}</dd>
              </div>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('cart.returnDate')}</dt>
                <dd style={{ margin: 0 }}>{formatDate(data.return_date)}</dd>
              </div>
            </dl>
            <div style={{ marginTop: 'var(--sp-4)' }}>
              <Button
                variant="primary"
                block
                size="lg"
                disabled={!data.pickup_date || !data.return_date}
                onClick={() => navigate('/carrello/checkout')}
              >
                {t('cart.checkout')}
                <Icon name="arrow-right" size={16} />
              </Button>
            </div>
          </Card>

          {check ? (
            <Card title={t('cart.quota')} headingLevel={2}>
              <dl className="vl-stack" style={{ gap: 'var(--sp-3)', margin: 0, fontSize: 'var(--fs-sm)' }}>
                <div>
                  <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                    <dt className="vl-subtle">{t('cart.quotaMonth')}</dt>
                    <dd style={{ margin: 0 }}>
                      {check.quota.orders_this_month}
                      {check.quota.max_orders_per_month === null
                        ? ` / ${t('app.infinite')}`
                        : ` / ${check.quota.max_orders_per_month}`}
                    </dd>
                  </div>
                </div>
                <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                  <dt className="vl-subtle">{t('cart.quotaActive')}</dt>
                  <dd style={{ margin: 0 }}>
                    {check.quota.active_orders}
                    {check.quota.max_active_orders === null
                      ? ` / ${t('app.infinite')}`
                      : ` / ${check.quota.max_active_orders}`}
                  </dd>
                </div>
              </dl>
            </Card>
          ) : null}
        </aside>
      </div>

      <ConfirmDialog
        open={confirmEmpty}
        title={t('cart.emptyCartAction')}
        body={t('cart.emptyCartConfirm')}
        danger
        loading={empty.isPending}
        onCancel={() => setConfirmEmpty(false)}
        onConfirm={() =>
          empty.mutate(undefined, {
            onSuccess: () => setConfirmEmpty(false),
            onError: pushError,
          })
        }
      />
    </div>
  );
}
