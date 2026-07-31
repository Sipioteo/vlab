import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { Badge, Card, EmptyState, SkeletonList } from '@/components/ui';
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
  const query = useQuery({
    queryKey: ['regulations'],
    queryFn: () => api.getRegulations({ per_page: 100 }),
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
                  {grouped[scope]!.map((reg) => (
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
                          {reg.acceptance?.accepted ? (
                            <Badge tone="returned">
                              {t('regulations.accepted', {
                                date: formatDate(reg.acceptance.accepted_at.slice(0, 10)),
                              })}
                            </Badge>
                          ) : reg.requires_acceptance ? (
                            <Badge tone="pending">{t('regulations.notAccepted')}</Badge>
                          ) : null}
                          <Link to={`/regolamento/${reg.slug}`} className="vl-btn vl-btn--ghost vl-btn--sm">
                            {t('regulations.read')}
                            <Icon name="chevron-right" size={14} />
                          </Link>
                        </div>
                      </div>
                    </Card>
                  ))}
                </div>
              </section>
            ) : null,
          )}
        </div>
      )}
    </div>
  );
}
