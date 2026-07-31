import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useToast } from '@/components/Toast';
import { Button, Card, ConfirmDialog, Field, Modal, SkeletonList, Switch, TextInput } from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import type { Category } from '@/types/api';

export function AdminCategoriesPage() {
  const queryClient = useQueryClient();
  const { push, pushError } = useToast();
  const [editing, setEditing] = useState<Partial<Category> | null>(null);
  const [toDelete, setToDelete] = useState<Category | null>(null);

  const query = useQuery({
    queryKey: ['categories', 'admin'],
    queryFn: () => api.getCategories({ include_inactive: 'true' }),
  });

  const save = useMutation({
    mutationFn: (payload: Partial<Category>) =>
      payload.id ? api.updateCategory(payload.id, payload) : api.createCategory(payload),
    onSuccess: () => {
      push(t('app.saved'), 'success');
      setEditing(null);
      void queryClient.invalidateQueries({ queryKey: ['categories'] });
    },
    onError: pushError,
  });

  const remove = useMutation({
    mutationFn: (id: number) => api.deleteCategory(id),
    onSuccess: () => {
      setToDelete(null);
      void queryClient.invalidateQueries({ queryKey: ['categories'] });
    },
    onError: pushError,
  });

  return (
    <>
      <div className="vl-page-head">
        <div className="vl-row">
          <div style={{ flex: 1 }}>
            <p className="vl-eyebrow">{t('staff.area')}</p>
            <h1>{t('staff.categoriesTitle')}</h1>
            <p className="vl-lead">{t('staff.categoriesLead')}</p>
          </div>
          <Button
            variant="primary"
            onClick={() => setEditing({ name: '', position: 0, is_active: true })}
          >
            <Icon name="plus" size={16} />
            {t('staff.newCategory')}
          </Button>
        </div>
      </div>

      {query.isLoading ? (
        <SkeletonList rows={6} height={44} />
      ) : (
        <Card headingLevel={2}>
          <div className="vl-table-wrap">
            <table className="vl-table">
              <caption className="vl-sr-only">{t('staff.categoriesTitle')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('product.category')}</th>
                  <th scope="col">Slug</th>
                  <th scope="col">{t('staff.products')}</th>
                  <th scope="col">Posizione</th>
                  <th scope="col">
                    <span className="vl-sr-only">{t('app.edit')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {(query.data?.data ?? []).map((category) => (
                  <tr key={category.id}>
                    <td>{category.name}</td>
                    <td className="vl-mono">{category.slug}</td>
                    <td>{category.products_count}</td>
                    <td>{category.position}</td>
                    <td>
                      <div className="vl-row" style={{ gap: 'var(--sp-1)', flexWrap: 'nowrap' }}>
                        <Button
                          size="sm"
                          variant="quiet"
                          aria-label={`${t('app.edit')} ${category.name}`}
                          onClick={() => setEditing(category)}
                        >
                          <Icon name="edit" size={15} />
                        </Button>
                        <Button
                          size="sm"
                          variant="quiet"
                          aria-label={`${t('app.delete')} ${category.name}`}
                          onClick={() => setToDelete(category)}
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
        </Card>
      )}

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.id ? t('app.edit') : t('staff.newCategory')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              disabled={!editing?.name}
              onClick={() => editing && save.mutate(editing)}
            >
              {t('app.save')}
            </Button>
          </>
        }
      >
        {editing ? (
          <div className="vl-stack">
            <Field label={t('product.category')} htmlFor="cat-name">
              <TextInput
                id="cat-name"
                value={editing.name ?? ''}
                onChange={(e) => setEditing((prev) => ({ ...prev, name: e.target.value }))}
              />
            </Field>
            <Field label="Posizione" htmlFor="cat-position">
              <TextInput
                id="cat-position"
                type="number"
                value={String(editing.position ?? 0)}
                onChange={(e) => setEditing((prev) => ({ ...prev, position: Number(e.target.value) }))}
              />
            </Field>
            <Switch
              checked={editing.is_active !== false}
              onChange={(checked) => setEditing((prev) => ({ ...prev, is_active: checked }))}
              label={t('staff.userActive')}
            />
          </div>
        ) : null}
      </Modal>

      <ConfirmDialog
        open={toDelete !== null}
        title={t('app.delete')}
        body={toDelete?.name}
        danger
        loading={remove.isPending}
        onCancel={() => setToDelete(null)}
        onConfirm={() => toDelete && remove.mutate(toDelete.id)}
      />
    </>
  );
}
