import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { useEnums } from '@/hooks/useEnums';
import { useToast } from '@/components/Toast';
import { UnitStatusBadge } from '@/components/domain';
import {
  Button,
  Card,
  Field,
  ProductImage,
  Select,
  Skeleton,
  Switch,
  Tabs,
  TextArea,
  TextInput,
} from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate, formatDateTime } from '@/lib/format';
import type { ProductSummary } from '@/types/api';

interface FormState {
  name: string;
  category_id: string;
  brand: string;
  model: string;
  description: string;
  image_url: string;
  status: string;
  loan_mode: string;
  requires_training: boolean;
  is_featured: boolean;
  max_loan_days: string;
  min_loan_days: string;
  replacement_value_note: string;
  position: string;
  initial_units: string;
}

const EMPTY: FormState = {
  name: '',
  category_id: '',
  brand: '',
  model: '',
  description: '',
  image_url: '',
  status: 'available',
  loan_mode: 'takeaway',
  requires_training: false,
  is_featured: false,
  max_loan_days: '',
  min_loan_days: '',
  replacement_value_note: '',
  position: '0',
  initial_units: '1',
};

export function ProductFormPage() {
  const { id } = useParams();
  const isEdit = Boolean(id);
  const productId = Number(id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { list } = useEnums();
  const { push, pushError } = useToast();

  const [tab, setTab] = useState('data');
  const [form, setForm] = useState<FormState>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [newLog, setNewLog] = useState({ title: '', type: 'note', severity: 'info', body: '' });
  const [subs, setSubs] = useState<ProductSummary[]>([]);
  const [subQuery, setSubQuery] = useState('');

  const categories = useQuery({ queryKey: ['categories'], queryFn: () => api.getCategories() });

  const productQuery = useQuery({
    queryKey: ['admin-product', productId],
    queryFn: () => api.getProduct(productId),
    enabled: isEdit && Number.isFinite(productId),
  });

  const units = useQuery({
    queryKey: ['product-units', productId],
    queryFn: () => api.getProductUnits(productId),
    enabled: isEdit && Number.isFinite(productId),
  });

  const logs = useQuery({
    queryKey: ['product-logs', productId],
    queryFn: () => api.getProductLogs(productId, { per_page: 20 }),
    enabled: isEdit && Number.isFinite(productId),
  });

  useEffect(() => {
    const product = productQuery.data;
    if (!product) return;
    setForm({
      name: product.name,
      category_id: String(product.category.id),
      brand: product.brand ?? '',
      model: product.model ?? '',
      description: product.description ?? '',
      image_url: product.image_url ?? '',
      status: product.status,
      loan_mode: product.loan_mode,
      requires_training: product.requires_training,
      is_featured: product.is_featured,
      max_loan_days: product.max_loan_days === null ? '' : String(product.max_loan_days),
      min_loan_days: product.min_loan_days === null ? '' : String(product.min_loan_days),
      replacement_value_note: product.replacement_value_note ?? '',
      position: String(product.position),
      initial_units: '1',
    });
    setSubs((product.substitutes ?? []).map((s) => s.product));
  }, [productQuery.data]);

  const subSearch = useQuery({
    queryKey: ['substitute-search', productId, subQuery],
    queryFn: () => api.getProducts({ q: subQuery, per_page: 8 }),
    enabled: tab === 'substitutes' && subQuery.trim().length >= 2,
  });

  const saveSubs = useMutation({
    mutationFn: () =>
      api.setSubstitutes(
        productId,
        subs.map((s, i) => ({ product_id: s.id, priority: i + 1 })),
      ),
    onSuccess: () => {
      push(t('staff.substitutesSaved'), 'success');
      void queryClient.invalidateQueries({ queryKey: ['admin-product', productId] });
    },
    onError: pushError,
  });

  const moveSub = (index: number, delta: number) => {
    setSubs((list) => {
      const target = index + delta;
      if (target < 0 || target >= list.length) return list;
      const next = [...list];
      const [moved] = next.splice(index, 1);
      next.splice(target, 0, moved!);
      return next;
    });
  };

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = {
        name: form.name,
        category_id: Number(form.category_id),
        brand: form.brand || null,
        model: form.model || null,
        description: form.description || null,
        image_url: form.image_url || null,
        status: form.status,
        loan_mode: form.loan_mode,
        requires_training: form.requires_training,
        is_featured: form.is_featured,
        max_loan_days: form.max_loan_days === '' ? null : Number(form.max_loan_days),
        min_loan_days: form.min_loan_days === '' ? null : Number(form.min_loan_days),
        replacement_value_note: form.replacement_value_note || null,
        position: Number(form.position) || 0,
      };
      if (!isEdit) payload['initial_units'] = Number(form.initial_units) || 1;
      return isEdit ? api.updateProduct(productId, payload) : api.createProduct(payload);
    },
    onSuccess: (product) => {
      push(t('app.saved'), 'success');
      void queryClient.invalidateQueries({ queryKey: ['admin-products'] });
      if (!isEdit) navigate(`/gestione/prodotti/${product.id}`);
    },
    onError: (error) => {
      if (error instanceof ApiError && error.code === 'validation_failed') {
        const mapped: Record<string, string> = {};
        for (const [field, messages] of Object.entries(error.fieldErrors)) {
          if (messages[0]) mapped[field] = messages[0];
        }
        setErrors(mapped);
      }
      pushError(error);
    },
  });

  const addUnit = useMutation({
    mutationFn: () => api.createProductUnits(productId, { count: 1 }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['product-units', productId] });
      push(t('app.saved'), 'success');
    },
    onError: pushError,
  });

  const addLog = useMutation({
    mutationFn: () =>
      api.createProductLog(productId, {
        title: newLog.title,
        type: newLog.type,
        severity: newLog.severity,
        body: newLog.body || null,
        is_public: true,
      }),
    onSuccess: () => {
      setNewLog({ title: '', type: 'note', severity: 'info', body: '' });
      void queryClient.invalidateQueries({ queryKey: ['product-logs', productId] });
      push(t('staff.logCreated'), 'success');
    },
    onError: pushError,
  });

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    const nextErrors: Record<string, string> = {};
    if (form.name.trim().length < 2) nextErrors['name'] = t('checkout.fieldRequired');
    if (!form.category_id) nextErrors['category_id'] = t('checkout.fieldRequired');
    setErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;
    save.mutate();
  }

  if (isEdit && productQuery.isLoading) {
    return <Skeleton height={340} radius={6} />;
  }

  const tabs = isEdit
    ? [
        { id: 'data', label: t('staff.tabData') },
        { id: 'images', label: t('staff.tabImages') },
        { id: 'units', label: t('staff.tabUnits') },
        { id: 'substitutes', label: t('staff.tabSubstitutes') },
        { id: 'logs', label: t('staff.tabLogs') },
      ]
    : [{ id: 'data', label: t('staff.tabData') }];

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.products')}</p>
        <h1>{isEdit ? form.name || t('app.edit') : t('staff.newProduct')}</h1>
      </div>

      <Tabs tabs={tabs} active={tab} onChange={setTab} label={t('staff.products')} />

      {tab === 'data' ? (
        <form onSubmit={onSubmit} noValidate id={`panel-data`} role="tabpanel" aria-labelledby="tab-data">
          <Card title={t('staff.tabData')} headingLevel={2}>
            <div className="vl-stack">
              <div className="vl-form-grid vl-form-grid--2">
                <Field label={t('staff.productName')} htmlFor="pf-name" error={errors['name']}>
                  <TextInput
                    id="pf-name"
                    name="name"
                    value={form.name}
                    onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                  />
                </Field>
                <Field label={t('product.category')} htmlFor="pf-category" error={errors['category_id']}>
                  <Select
                    id="pf-category"
                    name="category_id"
                    value={form.category_id}
                    onChange={(e) => setForm((f) => ({ ...f, category_id: e.target.value }))}
                  >
                    <option value="">—</option>
                    {(categories.data?.data ?? []).map((category) => (
                      <option key={category.id} value={category.id}>
                        {category.name}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label={t('product.brand')} htmlFor="pf-brand" optional>
                  <TextInput
                    id="pf-brand"
                    value={form.brand}
                    onChange={(e) => setForm((f) => ({ ...f, brand: e.target.value }))}
                  />
                </Field>
                <Field label="Modello" htmlFor="pf-model" optional>
                  <TextInput
                    id="pf-model"
                    value={form.model}
                    onChange={(e) => setForm((f) => ({ ...f, model: e.target.value }))}
                  />
                </Field>
              </div>

              <Field label={t('product.description')} htmlFor="pf-description" optional>
                <TextArea
                  id="pf-description"
                  value={form.description}
                  onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                />
              </Field>

              <div className="vl-form-grid vl-form-grid--3">
                <Field label={t('orders.status')} htmlFor="pf-status">
                  <Select
                    id="pf-status"
                    value={form.status}
                    onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}
                  >
                    {list('product_status').map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Modalità di prestito" htmlFor="pf-loanmode">
                  <Select
                    id="pf-loanmode"
                    value={form.loan_mode}
                    onChange={(e) => setForm((f) => ({ ...f, loan_mode: e.target.value }))}
                  >
                    {list('loan_mode').map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label={t('product.maxLoanDays', { n: '' })} htmlFor="pf-maxdays" optional>
                  <TextInput
                    id="pf-maxdays"
                    type="number"
                    min={1}
                    value={form.max_loan_days}
                    onChange={(e) => setForm((f) => ({ ...f, max_loan_days: e.target.value }))}
                  />
                </Field>
              </div>

              <div className="vl-row">
                <Switch
                  checked={form.requires_training}
                  onChange={(checked) => setForm((f) => ({ ...f, requires_training: checked }))}
                  label={t('product.trainingRequired')}
                />
                <Switch
                  checked={form.is_featured}
                  onChange={(checked) => setForm((f) => ({ ...f, is_featured: checked }))}
                  label={t('home.featuredTitle')}
                />
              </div>

              {!isEdit ? (
                <Field label={t('staff.initialUnits')} htmlFor="pf-units">
                  <TextInput
                    id="pf-units"
                    name="initial_units"
                    type="number"
                    min={1}
                    value={form.initial_units}
                    onChange={(e) => setForm((f) => ({ ...f, initial_units: e.target.value }))}
                  />
                </Field>
              ) : null}
            </div>
          </Card>
          <div style={{ marginTop: 'var(--sp-4)' }}>
            <Button type="submit" variant="primary" loading={save.isPending}>
              {t('app.save')}
            </Button>
          </div>
        </form>
      ) : null}

      {tab === 'images' ? (
        <Card title={t('staff.tabImages')} headingLevel={2}>
          <div className="vl-stack">
            <Field label="URL immagine" htmlFor="pf-image" hint="Immagine ospitata su host esterno.">
              <TextInput
                id="pf-image"
                value={form.image_url}
                onChange={(e) => setForm((f) => ({ ...f, image_url: e.target.value }))}
              />
            </Field>
            <div style={{ maxWidth: 260 }}>
              <ProductImage src={form.image_url || null} alt={form.name} />
            </div>
            <div>
              <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
                {t('app.save')}
              </Button>
            </div>
          </div>
        </Card>
      ) : null}

      {tab === 'units' ? (
        <Card
          title={t('staff.tabUnits')}
          headingLevel={2}
          actions={
            <Button size="sm" variant="ghost" loading={addUnit.isPending} onClick={() => addUnit.mutate()}>
              <Icon name="plus" size={14} />
              {t('staff.addUnit')}
            </Button>
          }
        >
          <div className="vl-table-wrap">
            <table className="vl-table">
              <thead>
                <tr>
                  <th scope="col">{t('staff.unitLabel')}</th>
                  <th scope="col">{t('staff.unitSerial')}</th>
                  <th scope="col">{t('staff.unitAsset')}</th>
                  <th scope="col">{t('staff.unitStatus')}</th>
                  <th scope="col">{t('staff.unitLocation')}</th>
                </tr>
              </thead>
              <tbody>
                {(units.data?.data ?? []).map((unit) => (
                  <tr key={unit.id}>
                    <td className="vl-mono">{unit.label}</td>
                    <td className="vl-mono">{unit.serial_number ?? '—'}</td>
                    <td className="vl-mono">{unit.asset_code ?? '—'}</td>
                    <td>
                      <UnitStatusBadge status={unit.status} />
                    </td>
                    <td>{unit.location ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      ) : null}

      {tab === 'substitutes' ? (
        <Card title={t('staff.tabSubstitutes')} headingLevel={2}>
          <div className="vl-stack">
            <p className="vl-subtle" style={{ margin: 0 }}>
              {t('staff.substitutesLead')}
            </p>

            {subs.length === 0 ? (
              <p className="vl-subtle">{t('staff.substitutesEmpty')}</p>
            ) : (
              <div className="vl-table-wrap">
                <table className="vl-table">
                  <thead>
                    <tr>
                      <th scope="col">{t('staff.substitutePriority')}</th>
                      <th scope="col">{t('staff.productName')}</th>
                      <th scope="col">{t('product.brand')}</th>
                      <th scope="col" aria-label={t('app.edit')} />
                    </tr>
                  </thead>
                  <tbody>
                    {subs.map((sub, index) => (
                      <tr key={sub.id}>
                        <td className="vl-mono">{index + 1}</td>
                        <td>{sub.name}</td>
                        <td>{sub.brand ?? '—'}</td>
                        <td>
                          <div className="vl-row" style={{ justifyContent: 'flex-end' }}>
                            <Button
                              size="sm"
                              variant="quiet"
                              disabled={index === 0}
                              aria-label={`${t('staff.substituteMoveUp')} — ${sub.name}`}
                              onClick={() => moveSub(index, -1)}
                            >
                              <Icon name="chevron-up" size={14} />
                            </Button>
                            <Button
                              size="sm"
                              variant="quiet"
                              disabled={index === subs.length - 1}
                              aria-label={`${t('staff.substituteMoveDown')} — ${sub.name}`}
                              onClick={() => moveSub(index, 1)}
                            >
                              <Icon name="chevron-down" size={14} />
                            </Button>
                            <Button
                              size="sm"
                              variant="quiet"
                              aria-label={`${t('staff.substituteRemove')} — ${sub.name}`}
                              onClick={() => setSubs((list) => list.filter((s) => s.id !== sub.id))}
                            >
                              <Icon name="trash" size={14} />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <Field label={t('staff.substituteSearch')} htmlFor="pf-sub-search">
              <TextInput
                id="pf-sub-search"
                value={subQuery}
                onChange={(e) => setSubQuery(e.target.value)}
              />
            </Field>
            {subQuery.trim().length >= 2 ? (
              <ul className="vl-stack" style={{ listStyle: 'none', margin: 0, padding: 0, gap: 'var(--sp-2)' }}>
                {(subSearch.data?.data ?? [])
                  .filter(
                    (candidate) =>
                      candidate.id !== productId && !subs.some((s) => s.id === candidate.id),
                  )
                  .map((candidate) => (
                    <li key={candidate.id} className="vl-row">
                      <span>
                        {candidate.name}
                        {candidate.brand ? <span className="vl-subtle"> · {candidate.brand}</span> : null}
                      </span>
                      <span className="vl-spacer" />
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`${t('staff.substituteAdd')} — ${candidate.name}`}
                        onClick={() => setSubs((list) => [...list, candidate])}
                      >
                        <Icon name="plus" size={14} />
                        {t('staff.substituteAdd')}
                      </Button>
                    </li>
                  ))}
              </ul>
            ) : null}

            <div>
              <Button variant="primary" loading={saveSubs.isPending} onClick={() => saveSubs.mutate()}>
                {t('app.save')}
              </Button>
            </div>
          </div>
        </Card>
      ) : null}

      {tab === 'logs' ? (
        <div className="vl-stack">
          <Card title={t('staff.newLog')} headingLevel={2}>
            <div className="vl-stack">
              <div className="vl-form-grid vl-form-grid--3">
                <Field label={t('staff.logTitle')} htmlFor="log-title">
                  <TextInput
                    id="log-title"
                    value={newLog.title}
                    onChange={(e) => setNewLog((l) => ({ ...l, title: e.target.value }))}
                  />
                </Field>
                <Field label={t('staff.logType')} htmlFor="log-type">
                  <Select
                    id="log-type"
                    value={newLog.type}
                    onChange={(e) => setNewLog((l) => ({ ...l, type: e.target.value }))}
                  >
                    {list('log_type').map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label={t('staff.logSeverity')} htmlFor="log-severity">
                  <Select
                    id="log-severity"
                    value={newLog.severity}
                    onChange={(e) => setNewLog((l) => ({ ...l, severity: e.target.value }))}
                  >
                    {list('log_severity').map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </Field>
              </div>
              <Field label={t('staff.logBody')} htmlFor="log-body" optional>
                <TextArea
                  id="log-body"
                  value={newLog.body}
                  onChange={(e) => setNewLog((l) => ({ ...l, body: e.target.value }))}
                />
              </Field>
              <div>
                <Button
                  variant="primary"
                  disabled={newLog.title.trim().length === 0}
                  loading={addLog.isPending}
                  onClick={() => addLog.mutate()}
                >
                  {t('app.create')}
                </Button>
              </div>
            </div>
          </Card>

          <Card title={t('staff.logsTitle')} headingLevel={2}>
            <ol className="vl-timeline">
              {(logs.data?.data ?? []).map((log) => (
                <li key={log.id} className="vl-timeline__item">
                  <span className="vl-timeline__dot" aria-hidden="true" />
                  <div className="vl-timeline__head">
                    <span className="vl-timeline__title">{log.title}</span>
                    <span className="vl-subtle">{log.type_label}</span>
                    <time className="vl-timeline__time">{formatDateTime(log.occurred_at)}</time>
                  </div>
                  {log.body ? <p className="vl-timeline__comment">{log.body}</p> : null}
                  {log.unit_label ? (
                    <span className="vl-subtle vl-mono">
                      {t('staff.unitLabel')} {log.unit_label} · {formatDate(log.occurred_at.slice(0, 10))}
                    </span>
                  ) : null}
                </li>
              ))}
            </ol>
          </Card>
        </div>
      ) : null}
    </>
  );
}
