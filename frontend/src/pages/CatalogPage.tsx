import { useEffect, useId, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useSettings } from '@/settings/SettingsProvider';
import { ProductCard, DateRangePicker } from '@/components/domain';
import { Button, EmptyState, Pagination, SearchInput, SkeletonGrid, Switch, Select } from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import type { ProductListResponse } from '@/types/api';

export function CatalogPage() {
  const { categorySlug } = useParams();
  const [params, setParams] = useSearchParams();
  const { get } = useSettings();
  const [filtersOpen, setFiltersOpen] = useState(false);
  const facetsId = useId();

  const q = params.get('q') ?? '';
  const brand = params.get('brand') ?? '';
  const sort = params.get('sort') ?? 'position';
  const page = Number(params.get('page') ?? '1');
  const startDate = params.get('start_date');
  const endDate = params.get('end_date');
  const includeUnavailable = params.get('include_unavailable') === 'true';
  const view = (params.get('view') ?? get<string>('ui.catalog_default_view', 'grid')) as 'grid' | 'list';
  const perPage = get<number>('ui.items_per_page', 24);

  const rangeActive = Boolean(startDate && endDate);

  const update = (patch: Record<string, string | null>, resetPage = true) => {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(patch)) {
      if (value === null || value === '') next.delete(key);
      else next.set(key, value);
    }
    if (resetPage) next.delete('page');
    setParams(next, { replace: false });
  };

  const queryKey = [
    'catalog',
    { categorySlug, q, brand, sort, page, startDate, endDate, includeUnavailable, perPage },
  ] as const;

  const query = useQuery<ProductListResponse>({
    queryKey,
    queryFn: () => {
      const base = {
        q: q || null,
        category_slug: categorySlug ?? null,
        brand: brand || null,
        sort,
        page,
        per_page: perPage,
      };
      /* Flow A — dates first: the query switches to the availability endpoint. */
      if (rangeActive) {
        return api.getAvailableProducts({
          ...base,
          start_date: startDate,
          end_date: endDate,
          include_unavailable: includeUnavailable ? 'true' : 'false',
        });
      }
      return api.getProducts(base);
    },
  });

  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.getCategories() });

  const products = query.data?.data ?? [];
  const meta = query.data?.meta;
  const facets = query.data?.filters;
  const brands = useMemo(() => facets?.brands ?? [], [facets]);

  const activeCategory = categories.data?.data.find((c) => c.slug === categorySlug);

  /* Count only the facets the sidebar owns — the search box is always visible. */
  const activeFilters = [categorySlug, brand || null, rangeActive ? 'dates' : null].filter(
    Boolean,
  ).length;
  const resultsTotal = meta?.total ?? products.length;

  /* Esc closes the mobile sheet; the body must not scroll behind it. */
  useEffect(() => {
    if (!filtersOpen) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setFiltersOpen(false);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [filtersOpen]);

  const facetPanel = (
    <div className="vl-facets__body">
      <div className="vl-facet">
        <h2>{t('catalog.dateRange')}</h2>
        <DateRangePicker
          pickupDate={startDate}
          returnDate={endDate}
          label={t('catalog.dateRange')}
          onChange={({ pickup_date, return_date }) =>
            update({ start_date: pickup_date, end_date: return_date })
          }
        />
        <p className="vl-field__hint" style={{ marginTop: 'var(--sp-2)' }}>
          {t('catalog.dateRangeHint')}
        </p>
        {rangeActive ? (
          <div className="vl-stack" style={{ marginTop: 'var(--sp-3)', gap: 'var(--sp-2)' }}>
            <Switch
              checked={includeUnavailable}
              onChange={(checked) => update({ include_unavailable: checked ? 'true' : null })}
              label={t('catalog.showUnavailable')}
            />
            <Button
              size="sm"
              variant="quiet"
              onClick={() => update({ start_date: null, end_date: null, include_unavailable: null })}
            >
              {t('catalog.clearDates')}
            </Button>
          </div>
        ) : null}
      </div>

      <div className="vl-facet">
        <h2>{t('catalog.categories')}</h2>
        <div className="vl-facet__list">
          <Link
            to={{ pathname: '/catalogo', search: params.toString() }}
            className="vl-facet__item"
            aria-current={!categorySlug ? 'true' : undefined}
          >
            {t('catalog.allCategories')}
          </Link>
          {(categories.data?.data ?? []).map((category) => (
            <Link
              key={category.id}
              to={{ pathname: `/catalogo/${category.slug}`, search: params.toString() }}
              className="vl-facet__item"
              aria-current={categorySlug === category.slug ? 'true' : undefined}
            >
              <span>{category.name}</span>
              <span className="vl-facet__count">{category.products_count}</span>
            </Link>
          ))}
        </div>
      </div>

      {brands.length > 0 ? (
        <div className="vl-facet">
          <h2>{t('catalog.brands')}</h2>
          <div className="vl-facet__list">
            <button
              type="button"
              className={`vl-facet__item${!brand ? ' vl-facet__item--active' : ''}`}
              onClick={() => update({ brand: null })}
            >
              {t('catalog.allBrands')}
            </button>
            {brands.map((item) => (
              <button
                key={item.name}
                type="button"
                className={`vl-facet__item${brand === item.name ? ' vl-facet__item--active' : ''}`}
                onClick={() => update({ brand: brand === item.name ? null : item.name })}
              >
                <span>{item.name}</span>
                <span className="vl-facet__count">{item.count}</span>
              </button>
            ))}
          </div>
        </div>
      ) : null}
    </div>
  );

  return (
    <div className="vl-container vl-page">
      <nav aria-label="breadcrumb" className="vl-crumbs">
        <Link to="/">{t('nav.home')}</Link>
        <span className="vl-crumbs__sep" aria-hidden="true">
          ›
        </span>
        {activeCategory ? (
          <>
            <Link to="/catalogo">{t('nav.catalog')}</Link>
            <span className="vl-crumbs__sep" aria-hidden="true">
              ›
            </span>
            <span aria-current="page">{activeCategory.name}</span>
          </>
        ) : (
          <span aria-current="page">{t('nav.catalog')}</span>
        )}
      </nav>

      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.catalog')}</p>
        <h1>{activeCategory ? activeCategory.name : t('catalog.title')}</h1>
        <p className="vl-lead">{activeCategory?.description ?? t('catalog.lead')}</p>
      </div>

      <div className="vl-catalog">
        <aside>
          <Button
            size="sm"
            variant="ghost"
            icon="filter"
            className="vl-filterbtn"
            aria-expanded={filtersOpen}
            aria-controls={facetsId}
            aria-haspopup="dialog"
            onClick={() => setFiltersOpen(true)}
          >
            {activeFilters > 0
              ? t('catalog.filtersCount', { n: activeFilters })
              : t('catalog.filtersOpen')}
          </Button>

          {filtersOpen ? (
            <div className="vl-facets__scrim" onClick={() => setFiltersOpen(false)} />
          ) : null}

          <div
            id={facetsId}
            className={`vl-facets${filtersOpen ? ' vl-facets--open' : ''}`}
            role={filtersOpen ? 'dialog' : undefined}
            aria-modal={filtersOpen ? true : undefined}
            aria-label={filtersOpen ? t('catalog.filtersTitle') : undefined}
          >
            <div className="vl-facets__head">
              <h2>{t('catalog.filtersTitle')}</h2>
              <Button
                size="sm"
                variant="quiet"
                aria-label={t('catalog.filtersClose')}
                onClick={() => setFiltersOpen(false)}
              >
                <Icon name="close" size={16} />
              </Button>
            </div>
            {facetPanel}
            <div className="vl-facets__apply">
              <Button variant="primary" block onClick={() => setFiltersOpen(false)}>
                {resultsTotal === 0
                  ? t('catalog.filtersApplyNone')
                  : resultsTotal === 1
                    ? t('catalog.filtersApplyOne')
                    : t('catalog.filtersApply', { n: resultsTotal })}
              </Button>
            </div>
          </div>
        </aside>

        <div>
          <div className="vl-toolbar">
            <div className="vl-toolbar__search">
              <SearchInput
                value={q}
                onChange={(value) => update({ q: value || null })}
                placeholder={t('catalog.searchPlaceholder')}
                label={t('app.search')}
              />
            </div>
            <label className="vl-sr-only" htmlFor="catalog-sort">
              {t('catalog.sort')}
            </label>
            <Select
              id="catalog-sort"
              value={sort}
              onChange={(e) => update({ sort: e.target.value })}
              style={{ width: 'auto', minWidth: 170 }}
            >
              <option value="position">{t('catalog.sortPosition')}</option>
              <option value="name">{t('catalog.sortName')}</option>
              <option value="popularity">{t('catalog.sortPopularity')}</option>
              <option value="units_available">{t('catalog.sortUnits')}</option>
            </Select>
            <div className="vl-row" style={{ gap: 'var(--sp-1)' }}>
              <Button
                size="sm"
                variant={view === 'grid' ? 'secondary' : 'quiet'}
                aria-pressed={view === 'grid'}
                aria-label={t('catalog.viewGrid')}
                onClick={() => update({ view: 'grid' }, false)}
              >
                <Icon name="grid" size={15} />
              </Button>
              <Button
                size="sm"
                variant={view === 'list' ? 'secondary' : 'quiet'}
                aria-pressed={view === 'list'}
                aria-label={t('catalog.viewList')}
                onClick={() => update({ view: 'list' }, false)}
              >
                <Icon name="list" size={15} />
              </Button>
            </div>
          </div>

          {query.isLoading ? (
            <SkeletonGrid count={8} />
          ) : query.isError ? (
            <EmptyState
              icon="alert"
              title={t('catalog.errorTitle')}
              body={t('errors.loadFailed')}
              action={
                <Button variant="primary" onClick={() => void query.refetch()}>
                  {t('app.retry')}
                </Button>
              }
            />
          ) : products.length === 0 ? (
            <EmptyState
              icon={rangeActive ? 'calendar' : 'search'}
              title={rangeActive ? t('catalog.emptyRangeTitle') : t('catalog.emptyTitle')}
              body={rangeActive ? t('catalog.emptyRangeBody') : t('catalog.emptyBody')}
              action={
                <Button
                  variant="primary"
                  onClick={() => setParams(new URLSearchParams(), { replace: true })}
                >
                  {t('app.clear')}
                </Button>
              }
            />
          ) : (
            <>
              <p className="vl-subtle" style={{ marginBottom: 'var(--sp-3)' }} role="status">
                {meta?.total === 1
                  ? t('catalog.resultsCountOne')
                  : t('catalog.resultsCount', { count: meta?.total ?? products.length })}
              </p>
              <div className={view === 'list' ? 'vl-list-products' : 'vl-grid-products'}>
                {products.map((product) => (
                  <ProductCard
                    key={product.id}
                    product={product}
                    view={view}
                    showAvailability={rangeActive}
                  />
                ))}
              </div>
              <Pagination
                page={meta?.page ?? 1}
                totalPages={meta?.total_pages ?? 1}
                onChange={(nextPage) => update({ page: String(nextPage) }, false)}
              />
            </>
          )}
        </div>
      </div>
    </div>
  );
}
