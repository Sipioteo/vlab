import { useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useToast } from '@/components/Toast';
import { ProductStatusBadge } from '@/components/domain';
import {
  Button,
  ConfirmDialog,
  EmptyState,
  Pagination,
  SearchInput,
  Select,
  SkeletonList,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import type { ProductSummary } from '@/types/api';

export function AdminProductsPage() {
  const [params, setParams] = useSearchParams();
  const queryClient = useQueryClient();
  const { push, pushError } = useToast();
  const [toDelete, setToDelete] = useState<ProductSummary | null>(null);

  const q = params.get('q') ?? '';
  const categoryId = params.get('category_id') ?? '';
  const page = Number(params.get('page') ?? '1');

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    for (const [key, value] of Object.entries(patch)) {
      if (!value) next.delete(key);
      else next.set(key, value);
    }
    if (!('page' in patch)) next.delete('page');
    setParams(next);
  };

  const query = useQuery({
    queryKey: ['admin-products', { q, categoryId, page }],
    queryFn: () =>
      api.getProducts({ q: q || null, category_id: categoryId || null, page, per_page: 25, sort: 'name' }),
  });
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.getCategories() });

  const remove = useMutation({
    mutationFn: (id: number) => api.deleteProduct(id),
    onSuccess: () => {
      push(t('staff.productDeleted'), 'success');
      setToDelete(null);
      void queryClient.invalidateQueries({ queryKey: ['admin-products'] });
    },
    onError: pushError,
  });

  const products = query.data?.data ?? [];

  return (
    <>
      <div className="vl-page-head">
        <div className="vl-row">
          <div style={{ flex: 1 }}>
            <p className="vl-eyebrow">{t('staff.area')}</p>
            <h1>{t('staff.productsTitle')}</h1>
            <p className="vl-lead">{t('staff.productsLead')}</p>
          </div>
          <Link to="/gestione/prodotti/nuovo" className="vl-btn vl-btn--primary">
            <Icon name="plus" size={16} />
            {t('staff.newProduct')}
          </Link>
        </div>
      </div>

      <div className="vl-toolbar" style={{ position: 'static' }}>
        <div className="vl-toolbar__search">
          <SearchInput
            value={q}
            onChange={(value) => update({ q: value || null })}
            label={t('app.search')}
            placeholder={t('catalog.searchPlaceholder')}
          />
        </div>
        <label className="vl-sr-only" htmlFor="admin-cat">
          {t('catalog.categories')}
        </label>
        <Select
          id="admin-cat"
          value={categoryId}
          onChange={(e) => update({ category_id: e.target.value || null })}
          style={{ width: 'auto', minWidth: 180 }}
        >
          <option value="">{t('catalog.allCategories')}</option>
          {(categories.data?.data ?? []).map((category) => (
            <option key={category.id} value={category.id}>
              {category.name}
            </option>
          ))}
        </Select>
      </div>

      {query.isLoading ? (
        <SkeletonList rows={8} height={44} />
      ) : products.length === 0 ? (
        <EmptyState
          icon="box"
          title={t('catalog.emptyTitle')}
          body={t('catalog.emptyBody')}
          action={
            <Link to="/gestione/prodotti/nuovo" className="vl-btn vl-btn--primary">
              {t('staff.newProduct')}
            </Link>
          }
        />
      ) : (
        <>
          <div className="vl-table-wrap">
            <table className="vl-table">
              <caption className="vl-sr-only">{t('staff.productsTitle')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('product.model')}</th>
                  <th scope="col">{t('product.category')}</th>
                  <th scope="col">{t('staff.tabUnits')}</th>
                  <th scope="col">{t('orders.status')}</th>
                  <th scope="col">
                    <span className="vl-sr-only">{t('app.edit')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {products.map((product) => (
                  <tr key={product.id}>
                    <td>
                      <Link to={`/gestione/prodotti/${product.id}`}>{product.name}</Link>
                      <div className="vl-subtle">{product.brand}</div>
                    </td>
                    <td>{product.category.name}</td>
                    <td>
                      {product.units_available}/{product.units_total}
                    </td>
                    <td>
                      <ProductStatusBadge status={product.status} />
                    </td>
                    <td>
                      <div className="vl-row" style={{ gap: 'var(--sp-1)', flexWrap: 'nowrap' }}>
                        <Link
                          to={`/gestione/prodotti/${product.id}`}
                          className="vl-btn vl-btn--quiet vl-btn--sm"
                          aria-label={`${t('app.edit')} ${product.name}`}
                        >
                          <Icon name="edit" size={15} />
                        </Link>
                        <Button
                          size="sm"
                          variant="quiet"
                          aria-label={`${t('app.delete')} ${product.name}`}
                          onClick={() => setToDelete(product)}
                        >
                          <Icon name="trash" size={15} />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination
            page={query.data?.meta?.page ?? 1}
            totalPages={query.data?.meta?.total_pages ?? 1}
            onChange={(next) => update({ page: String(next) })}
          />
        </>
      )}

      <ConfirmDialog
        open={toDelete !== null}
        title={t('app.delete')}
        body={toDelete ? t('staff.deleteProductConfirm', { name: toDelete.name }) : ''}
        danger
        loading={remove.isPending}
        onCancel={() => setToDelete(null)}
        onConfirm={() => toDelete && remove.mutate(toDelete.id)}
      />
    </>
  );
}
