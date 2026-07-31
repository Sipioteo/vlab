import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useSettings } from '@/settings/SettingsProvider';
import { ProductCard } from '@/components/domain';
import { Icon } from '@/components/Icon';
import { Skeleton } from '@/components/ui';
import { t } from '@/i18n/it';

/**
 * Public home. Structured the way polito.it structures a landing page: a
 * cinematic dark hero, then alternating full-bleed white / off-white / navy
 * bands, each closing on a single orange call to action.
 */
export function HomePage() {
  const { get } = useSettings();
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.getCategories() });
  const featured = useQuery({
    queryKey: ['products', { featured: true }],
    queryFn: () => api.getProducts({ featured: true, per_page: 8 }),
  });
  const fallbackFeatured = useQuery({
    queryKey: ['products', { fallbackFeatured: true }],
    queryFn: () => api.getProducts({ per_page: 8 }),
    enabled: featured.isSuccess && featured.data.data.length === 0,
  });
  const catalogCount = useQuery({
    queryKey: ['products', 'count'],
    queryFn: () => api.getProducts({ per_page: 1 }),
  });

  const heroImage = get<string>('ui.hero_image_url', '');
  const totalProducts = catalogCount.data?.meta?.total ?? null;
  const categoryCount = categories.data?.data.length ?? null;
  const featuredProducts =
    featured.data && featured.data.data.length > 0 ? featured.data.data : fallbackFeatured.data?.data;

  return (
    <>
      <section className="vl-hero">
        {heroImage ? <img className="vl-hero__bg" src={heroImage} alt="" aria-hidden="true" /> : null}
        <div className="vl-container">
          <div className="vl-hero__inner">
            <p className="vl-eyebrow vl-eyebrow--on-dark">{t('home.heroKicker')}</p>
            <h1>{t('home.heroTitle')}</h1>
            <p className="vl-hero__lead">{t('home.heroLead')}</p>
            <div className="vl-hero__cta">
              <Link to="/catalogo" className="vl-btn vl-btn--primary vl-btn--lg">
                {t('home.ctaCatalog')}
                <Icon name="chevron-right" size={16} />
              </Link>
              <Link to="/disponibilita" className="vl-btn vl-btn--on-dark vl-btn--lg">
                {t('home.ctaAvailability')}
              </Link>
            </div>
            <div className="vl-hero__meta">
              <div className="vl-hero__metaitem">
                <span className="vl-hero__metavalue">{categoryCount ?? '—'}</span>
                <span className="vl-hero__metalabel">{t('home.categoriesTitle')}</span>
              </div>
              <div className="vl-hero__metaitem">
                <span className="vl-hero__metavalue">{totalProducts ?? '—'}</span>
                <span className="vl-hero__metalabel">{t('catalog.title')}</span>
              </div>
              <div className="vl-hero__metaitem">
                <span className="vl-hero__metavalue">{get<number>('booking.max_loan_days', 7)}</span>
                <span className="vl-hero__metalabel">giorni di prestito</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Band 1 — white: the catalogue, by kind of kit. */}
      <section className="vl-band">
        <div className="vl-container">
          <div className="vl-band__head">
            <div className="vl-band__headtext">
              <p className="vl-eyebrow">{t('home.categoriesTitle')}</p>
              <h2>{t('home.categoriesLead')}</h2>
            </div>
          </div>
          <div className="vl-tilegrid">
            {categories.isLoading
              ? Array.from({ length: 8 }, (_, i) => <Skeleton key={i} height={74} radius={3} />)
              : categories.data?.data.map((category) => (
                  <Link key={category.id} to={`/catalogo/${category.slug}`} className="vl-tile">
                    <Icon name="box" size={22} className="vl-tile__icon" />
                    <span className="vl-tile__text">
                      <span className="vl-tile__name">{category.name}</span>
                      <span className="vl-tile__count">
                        {category.products_count} {t('cart.items')}
                      </span>
                    </span>
                    <Icon name="chevron-right" size={16} className="vl-tile__chev" />
                  </Link>
                ))}
          </div>
        </div>
      </section>

      {/* Band 2 — off-white: featured kit. */}
      <section className="vl-band vl-band--alt">
        <div className="vl-container">
          <div className="vl-band__head">
            <div className="vl-band__headtext">
              <p className="vl-eyebrow">{t('home.featuredTitle')}</p>
              <h2>{t('home.featuredLead')}</h2>
            </div>
            <Link to="/catalogo" className="vl-btn vl-btn--ghost">
              {t('home.ctaCatalog')}
              <Icon name="chevron-right" size={14} />
            </Link>
          </div>
          <div className="vl-grid-products">
            {featured.isLoading || fallbackFeatured.isLoading
              ? Array.from({ length: 4 }, (_, i) => <Skeleton key={i} height={300} radius={3} />)
              : featuredProducts?.map((product) => (
                  <ProductCard key={product.id} product={product} />
                ))}
          </div>
        </div>
      </section>

      {/* Band 3 — navy: how it works, closing on the single orange CTA. */}
      <section className="vl-band vl-band--navy">
        <div className="vl-container">
          <div className="vl-band__head">
            <div className="vl-band__headtext">
              <p className="vl-eyebrow vl-eyebrow--on-dark">{t('home.howTitle')}</p>
              <h2>{t('home.howTitle')}</h2>
            </div>
          </div>
          <div className="vl-steps">
            <article className="vl-step">
              <h3>{t('home.step1Title')}</h3>
              <p>{t('home.step1Body')}</p>
            </article>
            <article className="vl-step">
              <h3>{t('home.step2Title')}</h3>
              <p>{t('home.step2Body')}</p>
            </article>
            <article className="vl-step">
              <h3>{t('home.step3Title')}</h3>
              <p>{t('home.step3Body')}</p>
            </article>
          </div>
          <div className="vl-band__cta">
            <Link to="/catalogo" className="vl-btn vl-btn--primary vl-btn--lg">
              {t('home.ctaCatalog')}
              <Icon name="chevron-right" size={16} />
            </Link>
          </div>
        </div>
      </section>
    </>
  );
}
