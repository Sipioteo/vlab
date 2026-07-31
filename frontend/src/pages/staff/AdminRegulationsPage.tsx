import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { MarkdownView } from '@/components/domain';
import {
  Badge,
  Button,
  Card,
  ConfirmDialog,
  Field,
  Modal,
  Select,
  SkeletonList,
  Switch,
  TextArea,
  TextInput,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate } from '@/lib/format';
import type { Regulation, RegulationScope } from '@/types/api';

interface EditState {
  id?: number;
  title: string;
  summary: string;
  scope: RegulationScope;
  body: string;
  requires_acceptance: boolean;
  is_active: boolean;
  targets: { target_type: 'category' | 'product'; target_id: number }[];
}

const EMPTY: EditState = {
  title: '',
  summary: '',
  scope: 'global',
  body: '',
  requires_acceptance: true,
  is_active: true,
  targets: [],
};

export function AdminRegulationsPage() {
  const queryClient = useQueryClient();
  const { permissions } = useAuth();
  const { push, pushError } = useToast();
  const [editing, setEditing] = useState<EditState | null>(null);
  const [preview, setPreview] = useState(false);
  const [publishing, setPublishing] = useState<Regulation | null>(null);
  const [bumpVersion, setBumpVersion] = useState(true);

  const query = useQuery({
    queryKey: ['regulations', 'admin'],
    queryFn: () => api.getRegulations({ per_page: 100 }),
  });
  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.getCategories() });

  const save = useMutation({
    mutationFn: (state: EditState) => {
      const payload = {
        title: state.title,
        summary: state.summary || null,
        scope: state.scope,
        content_type: 'markdown',
        body: state.body,
        requires_acceptance: state.requires_acceptance,
        is_active: state.is_active,
        targets: state.scope === 'global' ? [] : state.targets,
      };
      return state.id ? api.updateRegulation(state.id, payload) : api.createRegulation(payload);
    },
    onSuccess: () => {
      push(t('app.saved'), 'success');
      setEditing(null);
      void queryClient.invalidateQueries({ queryKey: ['regulations'] });
    },
    onError: pushError,
  });

  const publish = useMutation({
    mutationFn: (regulation: Regulation) =>
      api.publishRegulation(regulation.id, { bump_version: bumpVersion }),
    onSuccess: () => {
      push(t('staff.regulationPublished2'), 'success');
      setPublishing(null);
      void queryClient.invalidateQueries({ queryKey: ['regulations'] });
    },
    onError: pushError,
  });

  async function openEdit(regulation: Regulation) {
    const detail = await api.getRegulation(regulation.id);
    setEditing({
      id: detail.id,
      title: detail.title,
      summary: detail.summary ?? '',
      scope: detail.scope,
      body: detail.body ?? '',
      requires_acceptance: detail.requires_acceptance,
      is_active: detail.is_active,
      targets: detail.targets.map((target) => ({
        target_type: target.target_type,
        target_id: target.target_id,
      })),
    });
  }

  return (
    <>
      <div className="vl-page-head">
        <div className="vl-row">
          <div style={{ flex: 1 }}>
            <p className="vl-eyebrow">{t('staff.area')}</p>
            <h1>{t('staff.regulationsTitle')}</h1>
            <p className="vl-lead">{t('staff.regulationsLead')}</p>
          </div>
          <Button variant="primary" onClick={() => setEditing({ ...EMPTY })}>
            <Icon name="plus" size={16} />
            {t('staff.newRegulation')}
          </Button>
        </div>
      </div>

      {query.isLoading ? (
        <SkeletonList rows={4} height={60} />
      ) : (
        <div className="vl-stack">
          {(query.data?.data ?? []).map((regulation) => (
            <Card key={regulation.id} headingLevel={2}>
              <div className="vl-row">
                <div style={{ flex: 1, minWidth: 0 }}>
                  <h2 style={{ fontSize: 'var(--fs-lg)' }}>{regulation.title}</h2>
                  <p className="vl-subtle" style={{ margin: 'var(--sp-1) 0 0' }}>
                    {regulation.summary}
                  </p>
                </div>
                <Badge tone="neutral" plain>
                  {t('regulations.version', { n: regulation.version })}
                </Badge>
                {regulation.published_at ? (
                  <Badge tone="returned" plain>
                    {t('staff.regulationPublished')} {formatDate(regulation.published_at.slice(0, 10))}
                  </Badge>
                ) : (
                  <Badge tone="draft" plain>
                    {t('staff.regulationDraft')}
                  </Badge>
                )}
                {regulation.requires_acceptance ? (
                  <Badge tone="pending" plain>
                    {t('staff.regulationRequiresAcceptance')}
                  </Badge>
                ) : null}
                <Button size="sm" variant="ghost" onClick={() => void openEdit(regulation)}>
                  {t('app.edit')}
                </Button>
                <Button size="sm" variant="primary" onClick={() => setPublishing(regulation)}>
                  {t('staff.regulationPublish')}
                </Button>
              </div>
              {typeof regulation.acceptances_count === 'number' ? (
                <p className="vl-subtle" style={{ marginTop: 'var(--sp-3)' }}>
                  {t('staff.regulationAcceptances')}: {regulation.acceptances_count}
                </p>
              ) : null}
            </Card>
          ))}
        </div>
      )}

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        wide
        title={editing?.id ? t('app.edit') : t('staff.newRegulation')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setPreview((p) => !p)}>
              {t('staff.regulationPreview')}
            </Button>
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
            <Field label={t('staff.logTitle')} htmlFor="reg-title">
              <TextInput
                id="reg-title"
                value={editing.title}
                onChange={(e) => setEditing((prev) => (prev ? { ...prev, title: e.target.value } : prev))}
              />
            </Field>
            <Field label="Sommario" htmlFor="reg-summary" optional>
              <TextInput
                id="reg-summary"
                value={editing.summary}
                onChange={(e) => setEditing((prev) => (prev ? { ...prev, summary: e.target.value } : prev))}
              />
            </Field>
            <Field label={t('staff.regulationScope')} htmlFor="reg-scope">
              <Select
                id="reg-scope"
                value={editing.scope}
                onChange={(e) =>
                  setEditing((prev) =>
                    prev ? { ...prev, scope: e.target.value as RegulationScope, targets: [] } : prev,
                  )
                }
              >
                <option value="global">{t('regulations.scopeGlobal')}</option>
                <option value="category">{t('regulations.scopeCategory')}</option>
                <option value="product">{t('regulations.scopeProduct')}</option>
              </Select>
            </Field>
            {editing.scope === 'category' ? (
              <Field label={t('staff.regulationTargets')} htmlFor="reg-target">
                <Select
                  id="reg-target"
                  value={String(editing.targets[0]?.target_id ?? '')}
                  onChange={(e) =>
                    setEditing((prev) =>
                      prev
                        ? {
                            ...prev,
                            targets: e.target.value
                              ? [{ target_type: 'category', target_id: Number(e.target.value) }]
                              : [],
                          }
                        : prev,
                    )
                  }
                >
                  <option value="">—</option>
                  {(categories.data?.data ?? []).map((category) => (
                    <option key={category.id} value={category.id}>
                      {category.name}
                    </option>
                  ))}
                </Select>
              </Field>
            ) : null}
            <Field label={t('staff.regulationBody')} htmlFor="reg-body">
              <TextArea
                id="reg-body"
                style={{ minHeight: 220, fontFamily: 'var(--font-mono)' }}
                value={editing.body}
                onChange={(e) => setEditing((prev) => (prev ? { ...prev, body: e.target.value } : prev))}
              />
            </Field>
            {preview ? (
              <Card title={t('staff.regulationPreview')} headingLevel={3}>
                <MarkdownView source={editing.body} />
              </Card>
            ) : null}
            <div className="vl-row">
              <Switch
                checked={editing.requires_acceptance}
                onChange={(checked) =>
                  setEditing((prev) => (prev ? { ...prev, requires_acceptance: checked } : prev))
                }
                label={t('staff.regulationRequiresAcceptance')}
              />
              <Switch
                checked={editing.is_active}
                onChange={(checked) => setEditing((prev) => (prev ? { ...prev, is_active: checked } : prev))}
                label={t('staff.userActive')}
              />
            </div>
          </div>
        ) : null}
      </Modal>

      <ConfirmDialog
        open={publishing !== null}
        title={t('staff.regulationPublish')}
        confirmLabel={t('staff.regulationPublish')}
        loading={publish.isPending}
        onCancel={() => setPublishing(null)}
        onConfirm={() => publishing && publish.mutate(publishing)}
      >
        <Switch
          checked={bumpVersion}
          onChange={setBumpVersion}
          label={t('staff.regulationBumpVersion')}
        />
        {!permissions['regulations.delete'] ? null : null}
      </ConfirmDialog>
    </>
  );
}
