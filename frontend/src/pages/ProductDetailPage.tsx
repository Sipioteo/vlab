import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';
import { useCartMutations } from '@/hooks/useCart';
import { useToast } from '@/components/Toast';
import { useEnums } from '@/hooks/useEnums';
import {
  AvailabilityCalendar,
  MarkdownView,
  ProductCard,
  ProductStatusBadge,
  type HeatDay,
} from '@/components/domain';
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  ProductImage,
  QuantityStepper,
  Skeleton,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { addDaysIso, formatDate, formatDateTime, todayIso } from '@/lib/format';

export function ProductDetailPage() {
  const { slug = '' } = useParams();
  const { isAuthenticated, permissions } = useAuth();
  const { addItem } = useCartMutations();
  const { push, pushError } = useToast();
  const { label } = useEnums();
  const [quantity, setQuantity] = useState(1);
  const [activeImage, setActiveImage] = useState(0);

  const productQuery = useQuery({
    queryKey: ['product', slug],
    queryFn: () => api.getProduct(slug),
  });
  const product = productQuery.data;

  const availability = useQuery({
    queryKey: ['product-availability', product?.id],
    queryFn: () =>
      api.getProductAvailability(product!.id, todayIso(), addDaysIso(todayIso(), 44)),
    enabled: Boolean(product?.id),
  });

  const logs = useQuery({
    queryKey: ['product-logs', product?.id],
    queryFn: () => api.getProductLogs(product!.id, { per_page: 8 }),
    enabled: Boolean(product?.id),
  });

  if (productQuery.isLoading) {
    return (
      <div className="vl-container vl-page">
        <Skeleton height={28} width="40%" />
        <div className="vl-pdp" style={{ marginTop: 'var(--sp-5)' }}>
          <Skeleton height={340} radius={6} />
          <Skeleton height={280} radius={6} />
        </div>
      </div>
    );
  }

  if (productQuery.isError || !product) {
    return (
      <div className="vl-container vl-page">
        <h1>{t('product.notFound')}</h1>
        <div style={{ marginTop: 'var(--sp-5)' }}>
          <EmptyState
            icon="alert"
            title={t('product.notFound')}
            body={t('errors.loadFailed')}
            action={
              <Link to="/catalogo" className="vl-btn vl-btn--primary">
                {t('product.backToCatalog')}
              </Link>
            }
          />
        </div>
      </div>
    );
  }

  /* The API omits empty collections as null — never trust the length blindly. */
  const gallery = product.images ?? [];
  const specs = product.specs ?? [];
  const recommended = product.recommended_products ?? [];
  const substitutes = product.substitutes ?? [];
  const regulations = product.regulations ?? [];
  const images = gallery.length > 0
    ? gallery
    : product.image_url
      ? [{ id: 0, url: product.image_url, alt: product.name, position: 0 }]
      : [];
  const current = images[Math.min(activeImage, images.length - 1)];
  const bookable = product.status === 'available';
  const maxQuantity = Math.max(1, product.units_total || 1);

  const heatDays: HeatDay[] = (availability.data?.days ?? []).map((day) => {
    const capacity = availability.data?.capacity ?? 0;
    const state: HeatDay['state'] = !day.is_open
      ? 'closed'
      : day.available <= 0
        ? 'ko'
        : capacity > 0 && day.available < capacity
          ? 'partial'
          : 'ok';
    return { date: day.date, state, title: `${formatDate(day.date)} · ${day.available}` , value: String(day.available) };
  });

  async function addToCart() {
    try {
      await addItem.mutateAsync({ product_id: product!.id, quantity });
      push(t('product.added'), 'success');
    } catch (error) {
      pushError(error);
    }
  }

  const addBlock = (
    <>
      {permissions['orders.create'] ? (
        <>
          <div className="vl-row">
            <span className="vl-field__label" id="qty-label">
              {t('product.quantity')}
            </span>
            <QuantityStepper
              value={quantity}
              onChange={setQuantity}
              max={maxQuantity}
              label={t('product.quantity')}
              disabled={!bookable}
            />
          </div>
          <Button
            variant="primary"
            size="lg"
            block
            disabled={!bookable}
            loading={addItem.isPending}
            onClick={() => void addToCart()}
          >
            <Icon name="cart" size={17} />
            {bookable
              ? t('product.addToCart')
              : t('product.notAvailableStatus', { status: label('product_status', product.status) })}
          </Button>
        </>
      ) : isAuthenticated ? null : (
        <Link to={`/login?next=${encodeURIComponent(`/prodotto/${product.slug}`)}`} className="vl-btn vl-btn--primary vl-btn--lg vl-btn--block">
          {t('nav.login')}
        </Link>
      )}
    </>
  );

  return (
    <div className="vl-container vl-page">
      <nav aria-label="breadcrumb" className="vl-crumbs">
        <Link to="/">{t('nav.home')}</Link>
        <span className="vl-crumbs__sep" aria-hidden="true">
          ›
        </span>
        <Link to="/catalogo">{t('nav.catalog')}</Link>
        <span className="vl-crumbs__sep" aria-hidden="true">
          ›
        </span>
        <Link to={`/catalogo/${product.category.slug}`}>{product.category.name}</Link>
      </nav>

      <div className="vl-pdp">
        <div>
          <div className="vl-pdp__gallery">
            <div className="vl-pdp__main">
              <ProductImage src={current?.url ?? null} alt={t('a11y.productImage', { name: product.name })} />
            </div>
            {images.length > 1 ? (
              <div className="vl-pdp__thumbs">
                {images.map((image, index) => (
                  <button
                    key={image.id}
                    type="button"
                    className="vl-pdp__thumb"
                    aria-pressed={index === activeImage}
                    aria-label={image.alt ?? product.name}
                    onClick={() => setActiveImage(index)}
                  >
                    <ProductImage src={image.url} alt={image.alt ?? ''} />
                  </button>
                ))}
              </div>
            ) : null}
          </div>

          <div className="vl-stack" style={{ marginTop: 'var(--sp-6)' }}>
            {product.description ? (
              <Card title={t('product.description')} headingLevel={2}>
                <MarkdownView source={product.description} />
              </Card>
            ) : null}

            {specs.length > 0 ? (
              <Card title={t('product.specs')} headingLevel={2}>
                <table className="vl-specs">
                  <tbody>
                    {specs.map((spec) => (
                      <tr key={spec.label}>
                        <th scope="row">{spec.label}</th>
                        <td>{spec.value}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Card>
            ) : null}

            <Card title={t('product.availability')} headingLevel={2}>
              <p className="vl-subtle" style={{ marginBottom: 'var(--sp-4)' }}>
                {t('product.availabilityLead')}
              </p>
              {availability.isLoading ? (
                <Skeleton height={180} radius={6} />
              ) : (
                <AvailabilityCalendar days={heatDays} />
              )}
            </Card>

            {recommended.length > 0 ? (
              <section>
                <p className="vl-eyebrow">{t('product.recommended')}</p>
                <h2 style={{ marginBottom: 'var(--sp-4)' }}>{t('product.recommended')}</h2>
                <div className="vl-grid-products">
                  {recommended.map((rec) => (
                    <ProductCard
                      key={rec.product.id}
                      product={rec.product}
                      footer={
                        permissions['orders.create'] ? (
                          <Button
                            size="sm"
                            variant="ghost"
                            block
                            onClick={() => {
                              addItem.mutate(
                                { product_id: rec.product.id, quantity: 1 },
                                {
                                  onSuccess: () => push(t('product.added'), 'success'),
                                  onError: pushError,
                                },
                              );
                            }}
                          >
                            {t('product.addToCart')}
                          </Button>
                        ) : undefined
                      }
                    />
                  ))}
                </div>
              </section>
            ) : null}

            {substitutes.length > 0 ? (
              <section>
                <p className="vl-eyebrow">{t('product.alternatives')}</p>
                <h2 style={{ marginBottom: 'var(--sp-2)' }}>{t('product.alternatives')}</h2>
                <p className="vl-subtle" style={{ marginBottom: 'var(--sp-4)' }}>
                  {t('product.alternativesLead')}
                </p>
                <div className="vl-grid-products">
                  {substitutes.map((sub) => (
                    <ProductCard key={sub.product.id} product={sub.product} />
                  ))}
                </div>
              </section>
            ) : null}

            <Card title={t('product.logs')} headingLevel={2}>
              {logs.isLoading ? (
                <Skeleton height={80} />
              ) : (logs.data?.data ?? []).length === 0 ? (
                <p className="vl-subtle">{t('product.logsEmpty')}</p>
              ) : (
                <ol className="vl-timeline">
                  {(logs.data?.data ?? []).map((log) => (
                    <li key={log.id} className="vl-timeline__item">
                      <span className="vl-timeline__dot" aria-hidden="true" />
                      <div className="vl-timeline__head">
                        <span className="vl-timeline__title">{log.title}</span>
                        <Badge tone={log.severity === 'critical' ? 'overdue' : log.severity === 'warning' ? 'pending' : 'neutral'} plain>
                          {log.type_label}
                        </Badge>
                        <time className="vl-timeline__time" dateTime={log.occurred_at}>
                          {formatDateTime(log.occurred_at)}
                        </time>
                      </div>
                      {log.body ? <p className="vl-timeline__comment">{log.body}</p> : null}
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          </div>
        </div>

        <div className="vl-pdp__aside">
          <div className="vl-pdp__buybox">
            <div>
              <p className="vl-eyebrow" style={{ marginBottom: 'var(--sp-2)' }}>
                {product.category.name}
              </p>
              <h1 style={{ fontSize: 'var(--fs-2xl)' }}>{product.name}</h1>
              {product.brand ? (
                <p className="vl-muted" style={{ marginTop: 'var(--sp-2)', marginBottom: 0 }}>
                  {product.brand}
                  {product.model ? ` · ${product.model}` : ''}
                </p>
              ) : null}
            </div>

            <div className="vl-row">
              <ProductStatusBadge status={product.status} />
              <Badge tone="available" plain>
                {t('product.unitsAvailable', { n: product.units_available })}
              </Badge>
              {product.loan_mode === 'on_site_only' ? (
                <Badge tone="pending" plain>
                  {t('product.onSiteOnly')}
                </Badge>
              ) : null}
              {product.requires_training ? (
                <Badge tone="neutral" plain>
                  {t('product.trainingRequired')}
                </Badge>
              ) : null}
            </div>

            {product.has_required_regulations || regulations.length > 0 ? (
              <Alert level="vr" icon="shield" title={t('product.regulationsWarning')}>
                {t('product.regulationsWarningBody')}
                <ul style={{ marginTop: 'var(--sp-2)' }}>
                  {regulations.map((reg) => (
                    <li key={reg.id}>
                      <Link to={`/regolamento/${reg.slug}`}>{reg.title}</Link>
                    </li>
                  ))}
                </ul>
              </Alert>
            ) : null}

            {addBlock}

            <dl className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)', margin: 0 }}>
              <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                <dt className="vl-subtle">{t('product.unitsTotal', { total: product.units_total })}</dt>
                <dd style={{ margin: 0 }}>{product.units_total}</dd>
              </div>
              {product.max_loan_days ? (
                <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                  <dt className="vl-subtle">{t('product.maxLoanDays', { n: product.max_loan_days })}</dt>
                  <dd style={{ margin: 0 }}>{product.max_loan_days}</dd>
                </div>
              ) : null}
              {product.replacement_value_note ? (
                <div className="vl-row" style={{ justifyContent: 'space-between' }}>
                  <dt className="vl-subtle">{t('product.replacementValue')}</dt>
                  <dd style={{ margin: 0 }}>{product.replacement_value_note}</dd>
                </div>
              ) : null}
            </dl>
          </div>
        </div>
      </div>

      {permissions['orders.create'] ? (
        <div className="vl-stickybar">
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontWeight: 600, fontSize: 'var(--fs-sm)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {product.name}
            </div>
            <div className="vl-subtle">{t('product.unitsAvailable', { n: product.units_available })}</div>
          </div>
          <Button variant="primary" disabled={!bookable} onClick={() => void addToCart()}>
            {t('product.addToCart')}
          </Button>
        </div>
      ) : null}
    </div>
  );
}
