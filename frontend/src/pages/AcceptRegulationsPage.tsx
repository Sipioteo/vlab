import { useState } from 'react';
import { useQueries, useQuery, useMutation } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { RegulationAcceptBlock } from '@/components/domain';
import { Alert, Button, Skeleton } from '@/components/ui';
import { t } from '@/i18n/it';

/**
 * Interstitial rendered instead of any other route while blocking global
 * regulations are pending (SPEC §11.4 "Regulation gate").
 */
export function AcceptRegulationsPage() {
  const { pendingRegulations, setPendingRegulations, refresh } = useAuth();
  const { push, pushError } = useToast();
  const [accepted, setAccepted] = useState<Record<number, boolean>>({});

  const pendingQuery = useQuery({
    queryKey: ['pending-regulations'],
    queryFn: api.getPendingRegulations,
    initialData: pendingRegulations.length > 0 ? { data: pendingRegulations, meta: null } : undefined,
  });

  const pending = pendingQuery.data?.data ?? [];

  const details = useQueries({
    queries: pending.map((reg) => ({
      queryKey: ['regulation', reg.id],
      queryFn: () => api.getRegulation(reg.id),
    })),
  });

  const acceptAll = useMutation({
    mutationFn: async () => {
      let latest = pending;
      for (const reg of pending) {
        const res = await api.acceptRegulation(reg.id, reg.version);
        latest = res.pending_regulations;
      }
      return latest;
    },
    onSuccess: async (latest) => {
      setPendingRegulations(latest);
      push(t('regulations.accepted2'), 'success');
      await refresh();
    },
    onError: pushError,
  });

  const allChecked = pending.length > 0 && pending.every((reg) => accepted[reg.id]);

  return (
    <div className="vl-container vl-page" style={{ maxWidth: 780 }}>
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.regulations')}</p>
        <h1>{t('regulations.acceptTitle')}</h1>
        <p className="vl-lead">{t('regulations.acceptLead')}</p>
      </div>

      {pendingQuery.isLoading ? (
        <Skeleton height={320} radius={6} />
      ) : pending.length === 0 ? (
        <Alert level="success" icon="check">
          {t('regulations.accepted2')}
        </Alert>
      ) : (
        <div className="vl-stack">
          {pending.map((reg, index) => {
            const detail = details[index]?.data;
            return (
              <RegulationAcceptBlock
                key={reg.id}
                regulation={{
                  id: reg.id,
                  slug: reg.slug,
                  title: reg.title,
                  summary: reg.summary ?? detail?.summary ?? null,
                  version: reg.version,
                  body: detail?.body ?? null,
                  content_type: reg.content_type,
                  file_url: reg.file_url ?? null,
                }}
                checked={Boolean(accepted[reg.id])}
                onChange={(value) => setAccepted((prev) => ({ ...prev, [reg.id]: value }))}
              />
            );
          })}

          <Button
            variant="primary"
            size="lg"
            disabled={!allChecked}
            loading={acceptAll.isPending}
            onClick={() => acceptAll.mutate()}
          >
            {t('regulations.acceptAll')}
          </Button>
        </div>
      )}
    </div>
  );
}
