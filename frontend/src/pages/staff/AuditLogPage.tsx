import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { Card, EmptyState, Pagination, SearchInput, SkeletonList } from '@/components/ui';
import { t } from '@/i18n/it';
import { formatDateTime } from '@/lib/format';

export function AuditLogPage() {
  const [params, setParams] = useSearchParams();
  const action = params.get('action') ?? '';
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
    queryKey: ['audit-logs', { action, page }],
    queryFn: () => api.getAuditLogs({ action: action || null, page, per_page: 30 }),
  });

  const entries = query.data?.data ?? [];

  return (
    <>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('staff.area')}</p>
        <h1>{t('staff.auditTitle')}</h1>
        <p className="vl-lead">{t('staff.auditLead')}</p>
      </div>

      <div className="vl-toolbar" style={{ position: 'static' }}>
        <div className="vl-toolbar__search">
          <SearchInput
            value={action}
            onChange={(value) => update({ action: value || null })}
            label={t('app.search')}
            placeholder="settings.update"
          />
        </div>
      </div>

      {query.isLoading ? (
        <SkeletonList rows={8} height={40} />
      ) : entries.length === 0 ? (
        <EmptyState icon="lock" title={t('staff.auditTitle')} body={t('staff.auditLead')} />
      ) : (
        <>
          <Card headingLevel={2}>
            <div className="vl-table-wrap">
              <table className="vl-table">
                <caption className="vl-sr-only">{t('staff.auditTitle')}</caption>
                <thead>
                  <tr>
                    <th scope="col">Azione</th>
                    <th scope="col">Entità</th>
                    <th scope="col">{t('staff.student')}</th>
                    <th scope="col">Data</th>
                  </tr>
                </thead>
                <tbody>
                  {entries.map((entry) => (
                    <tr key={entry.id}>
                      <td className="vl-mono">{entry.action}</td>
                      <td className="vl-mono">
                        {entry.entity_type} #{entry.entity_id}
                      </td>
                      <td>{entry.user?.display_name ?? '—'}</td>
                      <td>{formatDateTime(entry.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
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
