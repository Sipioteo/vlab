import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useToast } from '@/components/Toast';
import {
  Alert,
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  Field,
  Modal,
  SkeletonList,
  Switch,
  TextInput,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate, todayIso } from '@/lib/format';
import type { Closure } from '@/types/api';

interface EditState {
  id?: number;
  title: string;
  description: string;
  start_date: string;
  end_date: string;
  blocks_pickup: boolean;
  blocks_return: boolean;
  is_recurring_yearly: boolean;
}

export function AdminClosuresPage() {
  const queryClient = useQueryClient();
  const { push, pushError } = useToast();
  const [editing, setEditing] = useState<EditState | null>(null);
  const [toDelete, setToDelete] = useState<Closure | null>(null);
  const [affected, setAffected] = useState<Closure['affected_orders']>(undefined);

  const query = useQuery({
    queryKey: ['closures'],
    queryFn: () => api.getClosures({ include_past: 'true', per_page: 50 }),
  });

  const save = useMutation({
    mutationFn: (state: EditState) => {
      const payload = {
        title: state.title,
        description: state.description || null,
        start_date: state.start_date,
        end_date: state.end_date,
        blocks_pickup: state.blocks_pickup,
        blocks_return: state.blocks_return,
        is_recurring_yearly: state.is_recurring_yearly,
      };
      return state.id ? api.updateClosure(state.id, payload) : api.createClosure(payload);
    },
    onSuccess: (closure) => {
      push(t('app.saved'), 'success');
      setEditing(null);
      setAffected(closure.affected_orders);
      void queryClient.invalidateQueries({ queryKey: ['closures'] });
    },
    onError: pushError,
  });

  const remove = useMutation({
    mutationFn: (id: number) => api.deleteClosure(id),
    onSuccess: () => {
      setToDelete(null);
      void queryClient.invalidateQueries({ queryKey: ['closures'] });
    },
    onError: pushError,
  });

  const closures = query.data?.data ?? [];

  return (
    <>
      <div className="vl-page-head">
        <div className="vl-row">
          <div style={{ flex: 1 }}>
            <p className="vl-eyebrow">{t('staff.area')}</p>
            <h1>{t('staff.closuresTitle')}</h1>
            <p className="vl-lead">{t('staff.closuresLead')}</p>
          </div>
          <Button
            variant="primary"
            onClick={() =>
              setEditing({
                title: '',
                description: '',
                start_date: todayIso(),
                end_date: todayIso(),
                blocks_pickup: true,
                blocks_return: true,
                is_recurring_yearly: false,
              })
            }
          >
            <Icon name="plus" size={16} />
            {t('staff.newClosure')}
          </Button>
        </div>
      </div>

      {affected && affected.length > 0 ? (
        <div style={{ marginBottom: 'var(--sp-4)' }}>
          <Alert level="warning" icon="alert">
            {t('staff.closureAffected', { n: affected.length })}
            <ul>
              {affected.map((order) => (
                <li key={order.id} className="vl-mono">
                  {order.code} · {formatDate(order.pickup_date)}
                </li>
              ))}
            </ul>
          </Alert>
        </div>
      ) : null}

      {query.isLoading ? (
        <SkeletonList rows={4} height={48} />
      ) : closures.length === 0 ? (
        <EmptyState icon="calendar" title={t('staff.closuresTitle')} body={t('staff.closuresLead')} />
      ) : (
        <Card headingLevel={2}>
          <div className="vl-table-wrap">
            <table className="vl-table">
              <caption className="vl-sr-only">{t('staff.closuresTitle')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('staff.closureTitle')}</th>
                  <th scope="col">{t('staff.closureStart')}</th>
                  <th scope="col">{t('staff.closureEnd')}</th>
                  <th scope="col">{t('orders.pickup')}</th>
                  <th scope="col">{t('orders.return')}</th>
                  <th scope="col">
                    <span className="vl-sr-only">{t('app.edit')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {closures.map((closure) => (
                  <tr key={closure.id}>
                    <td>{closure.title}</td>
                    <td>{formatDate(closure.start_date)}</td>
                    <td>{formatDate(closure.end_date)}</td>
                    <td>{closure.blocks_pickup ? t('app.no') : t('app.yes')}</td>
                    <td>{closure.blocks_return ? t('app.no') : t('app.yes')}</td>
                    <td>
                      <div className="vl-row" style={{ gap: 'var(--sp-1)', flexWrap: 'nowrap' }}>
                        <Button
                          size="sm"
                          variant="quiet"
                          aria-label={`${t('app.edit')} ${closure.title}`}
                          onClick={() =>
                            setEditing({
                              id: closure.id,
                              title: closure.title,
                              description: closure.description ?? '',
                              start_date: closure.start_date,
                              end_date: closure.end_date,
                              blocks_pickup: closure.blocks_pickup,
                              blocks_return: closure.blocks_return,
                              is_recurring_yearly: closure.is_recurring_yearly,
                            })
                          }
                        >
                          <Icon name="edit" size={15} />
                        </Button>
                        <Button
                          size="sm"
                          variant="quiet"
                          aria-label={`${t('app.delete')} ${closure.title}`}
                          onClick={() => setToDelete(closure)}
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
        title={editing?.id ? t('app.edit') : t('staff.newClosure')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              {t('app.cancel')}
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              disabled={!editing?.title}
              onClick={() => editing && save.mutate(editing)}
            >
              {t('app.save')}
            </Button>
          </>
        }
      >
        {editing ? (
          <div className="vl-stack">
            <Field label={t('staff.closureTitle')} htmlFor="cl-title">
              <TextInput
                id="cl-title"
                value={editing.title}
                onChange={(e) => setEditing((prev) => (prev ? { ...prev, title: e.target.value } : prev))}
              />
            </Field>
            <div className="vl-form-grid vl-form-grid--2">
              <Field label={t('staff.closureStart')} htmlFor="cl-start">
                <TextInput
                  id="cl-start"
                  type="date"
                  value={editing.start_date}
                  onChange={(e) =>
                    setEditing((prev) => (prev ? { ...prev, start_date: e.target.value } : prev))
                  }
                />
              </Field>
              <Field label={t('staff.closureEnd')} htmlFor="cl-end">
                <TextInput
                  id="cl-end"
                  type="date"
                  value={editing.end_date}
                  onChange={(e) => setEditing((prev) => (prev ? { ...prev, end_date: e.target.value } : prev))}
                />
              </Field>
            </div>
            <Switch
              checked={editing.blocks_pickup}
              onChange={(checked) =>
                setEditing((prev) => (prev ? { ...prev, blocks_pickup: checked } : prev))
              }
              label={t('staff.closureBlocksPickup')}
            />
            <Switch
              checked={editing.blocks_return}
              onChange={(checked) =>
                setEditing((prev) => (prev ? { ...prev, blocks_return: checked } : prev))
              }
              label={t('staff.closureBlocksReturn')}
            />
            <Switch
              checked={editing.is_recurring_yearly}
              onChange={(checked) =>
                setEditing((prev) => (prev ? { ...prev, is_recurring_yearly: checked } : prev))
              }
              label={t('staff.closureRecurring')}
            />
          </div>
        ) : null}
      </Modal>

      <ConfirmDialog
        open={toDelete !== null}
        title={t('app.delete')}
        body={toDelete?.title}
        danger
        loading={remove.isPending}
        onCancel={() => setToDelete(null)}
        onConfirm={() => toDelete && remove.mutate(toDelete.id)}
      />
    </>
  );
}
