import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useEnums } from '@/hooks/useEnums';
import { Badge, Button, Card, EmptyState, Pagination, SearchInput, Select, SkeletonList } from '@/components/ui';
import { t } from '@/i18n/it';
import { formatDateTime } from '@/lib/format';

export function ProductLogsPage() {
  const [params, setParams] = useSearchParams();
  const { list, label } = useEnums();

  const q = params.get('q') ?? '';
  const type = params.get('type') ?? '';
  const severity = params.get('severity') ?? '';
  const unresolved = params.get('unresolved') === 'true';
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
    queryKey: ['logs', { q, type, severity, unresolved, page }],
    queryFn: () =>
      api.getAllLogs({
        q: q || null,
        type: type || null,
        severity: severity || null,
        unresolved: unresolved ? 'true' : null,
        page,
        per_page: 25,
      }),
  });

  const logs = query.data?.data ?? [];
  const summary = query.data?.summary;

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.area')}</p>
        <h1>{t('staff.logsTitle')}</h1>
        <p className="vl-lead">{t('staff.logsLead')}</p>
      </div>

      {summary ? (
        <div className="vl-chips" style={{ marginBottom: 'var(--sp-4)' }}>
          {Object.entries(summary).map(([key, value]) => (
            <span key={key} className="vl-chip">
              {key === 'unresolved' ? t('staff.logUnresolved') : label('log_type', key)}
              <span className="vl-chip__count">{value}</span>
            </span>
          ))}
        </div>
      ) : null}

      <div className="vl-toolbar" style={{ position: 'static' }}>
        <div className="vl-toolbar__search">
          <SearchInput
            value={q}
            onChange={(value) => update({ q: value || null })}
            label={t('app.search')}
            placeholder={t('staff.logTitle')}
          />
        </div>
        <label className="vl-sr-only" htmlFor="logs-type">
          {t('staff.logType')}
        </label>
        <Select
          id="logs-type"
          value={type}
          onChange={(e) => update({ type: e.target.value || null })}
          style={{ width: 'auto', minWidth: 150 }}
        >
          <option value="">{t('app.all')}</option>
          {list('log_type').map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
        <label className="vl-sr-only" htmlFor="logs-severity">
          {t('staff.logSeverity')}
        </label>
        <Select
          id="logs-severity"
          value={severity}
          onChange={(e) => update({ severity: e.target.value || null })}
          style={{ width: 'auto', minWidth: 150 }}
        >
          <option value="">{t('app.all')}</option>
          {list('log_severity').map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
        <Button
          size="sm"
          variant={unresolved ? 'secondary' : 'ghost'}
          aria-pressed={unresolved}
          onClick={() => update({ unresolved: unresolved ? null : 'true' })}
        >
          {t('staff.logUnresolved')}
        </Button>
      </div>

      {query.isLoading ? (
        <SkeletonList rows={6} height={56} />
      ) : logs.length === 0 ? (
        <EmptyState icon="file" title={t('product.logsEmpty')} />
      ) : (
        <>
          <Card headingLevel={2}>
            <ol className="vl-timeline">
              {logs.map((log) => (
                <li key={log.id} className="vl-timeline__item">
                  <span className="vl-timeline__dot" aria-hidden="true" />
                  <div className="vl-timeline__head">
                    <span className="vl-timeline__title">{log.title}</span>
                    <Badge
                      tone={
                        log.severity === 'critical'
                          ? 'overdue'
                          : log.severity === 'warning'
                            ? 'pending'
                            : 'neutral'
                      }
                      plain
                    >
                      {log.type_label}
                    </Badge>
                    {!log.is_public ? <Badge tone="neutral" plain>{t('staff.area')}</Badge> : null}
                    <time className="vl-timeline__time">{formatDateTime(log.occurred_at)}</time>
                  </div>
                  {log.body ? <p className="vl-timeline__comment">{log.body}</p> : null}
                  <span className="vl-subtle">
                    {log.unit_label ? `${t('staff.unitLabel')} ${log.unit_label} · ` : ''}
                    {log.user?.display_name ?? ''}
                  </span>
                </li>
              ))}
            </ol>
          </Card>
          <Pagination
            page={query.data?.meta?.page ?? 1}
            totalPages={query.data?.meta?.total_pages ?? 1}
            onChange={(next) => update({ page: String(next) })}
          />
        </>
      )}
    </>
  );
}
