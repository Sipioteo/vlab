import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { Badge, Button, Card, EmptyState, SkeletonList } from '@/components/ui';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';
import { formatDate } from '@/lib/format';
import type { Regulation, RegulationScope } from '@/types/api';

const SCOPE_TITLES: Record<RegulationScope, string> = {
  global: t('regulations.scopeGlobal'),
  category: t('regulations.scopeCategory'),
  product: t('regulations.scopeProduct'),
};

export function RegulationsPage() {
  const queryClient = useQueryClient();
  const { isAuthenticated, setPendingRegulations, refresh } = useAuth();
  const { push, pushError } = useToast();

  const query = useQuery({
    queryKey: ['regulations'],
    queryFn: () => api.getRegulations({ per_page: 100 }),
  });

  /**
   * Manual acceptance from the index. The blocking gate covers the mandatory
   * global ones, but there must always be a way to accept a document on
   * purpose — including the non-blocking scoped ones.
   */
  const accept = useMutation({
    mutationFn: (reg: Regulation) => api.acceptRegulation(reg.id, reg.version),
    onSuccess: async (res) => {
      setPendingRegulations(res.pending_regulations);
      push(t('regulations.accepted2'), 'success');
      await queryClient.invalidateQueries({ queryKey: ['regulations'] });
      await refresh();
    },
    onError: pushError,
  });

  const grouped = (query.data?.data ?? []).reduce<Record<string, Regulation[]>>((acc, reg) => {
    const bucket = acc[reg.scope] ?? [];
    bucket.push(reg);
    acc[reg.scope] = bucket;
    return acc;
  }, {});

  return (
    <div className="vl-container vl-page">
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.regulations')}</p>
        <h1>{t('regulations.title')}</h1>
        <p className="vl-lead">{t('regulations.lead')}</p>
      </div>

      {query.isLoading ? (
        <SkeletonList rows={3} height={80} />
      ) : (query.data?.data ?? []).length === 0 ? (
        <EmptyState icon="shield" title={t('regulations.empty')} />
      ) : (
        <div className="vl-stack" style={{ gap: 'var(--sp-6)' }}>
          {(['global', 'category', 'product'] as RegulationScope[]).map((scope) =>
            grouped[scope] && grouped[scope]!.length > 0 ? (
              <section key={scope}>
                <h2 style={{ marginBottom: 'var(--sp-4)' }}>{SCOPE_TITLES[scope]}</h2>
                <div className="vl-stack">
                  {grouped[scope]!.map((reg) => {
                    const isAccepted = Boolean(reg.acceptance?.accepted);
                    const needsAcceptance = isAuthenticated && reg.requires_acceptance && !isAccepted;
                    return (
                      <Card key={reg.id} headingLevel={3}>
                        <div className="vl-row">
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <h3 style={{ fontSize: 'var(--fs-lg)' }}>
                              <Link to={`/regolamento/${reg.slug}`}>{reg.title}</Link>
                            </h3>
                            {reg.summary ? (
                              <p className="vl-subtle" style={{ margin: 'var(--sp-2) 0 0' }}>
                                {reg.summary}
                              </p>
                            ) : null}
                          </div>
                          <div className="vl-row" style={{ gap: 'var(--sp-2)' }}>
                            <Badge tone="neutral" plain>
                              {t('regulations.version', { n: reg.version })}
                            </Badge>
                            {isAccepted ? (
                              <Badge tone="returned">
                                {t('regulations.accepted', {
                                  date: formatDate(reg.acceptance!.accepted_at.slice(0, 10)),
                                })}
                              </Badge>
                            ) : reg.requires_acceptance ? (
                              <Badge tone="pending">{t('regulations.toAccept')}</Badge>
                            ) : null}
                            {needsAcceptance ? (
                              <Button
                                variant="primary"
                                size="sm"
                                loading={accept.isPending && accept.variables?.id === reg.id}
                                onClick={() => accept.mutate(reg)}
                                aria-label={`${t('regulations.acceptNow')} — ${reg.title}`}
                              >
                                {t('regulations.acceptNow')}
                              </Button>
                            ) : null}
                            <Link
                              to={`/regolamento/${reg.slug}`}
                              className="vl-btn vl-btn--ghost vl-btn--sm"
                            >
                              {t('regulations.read')}
                              <Icon name="chevron-right" size={14} />
                            </Link>
                          </div>
                        </div>
                      </Card>
                    );
                  })}
                </div>
              </section>
            ) : null,
          )}
        </div>
      )}
    </div>
  );
}
