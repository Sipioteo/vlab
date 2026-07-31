import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { getAccessToken } from '@/api/client';
import { MarkdownView } from '@/components/domain';
import { Badge, Card, Skeleton } from '@/components/ui';
import { t } from '@/i18n/it';
import { formatDate } from '@/lib/format';

export function RegulationDetailPage() {
  const { slug = '' } = useParams();
  const query = useQuery({
    queryKey: ['regulation', slug],
    queryFn: () => api.getRegulation(slug),
  });

  if (query.isLoading) {
    return (
      <div className="vl-container vl-page">
        <Skeleton height={30} width="50%" />
        <div style={{ marginTop: 'var(--sp-5)' }}>
          <Skeleton height={320} radius={6} />
        </div>
      </div>
    );
  }

  const regulation = query.data;
  if (query.isError || !regulation) {
    return (
      <div className="vl-container vl-page">
        <h1>{t('errors.notFoundTitle')}</h1>
        <Link to="/regolamento" className="vl-btn vl-btn--ghost" style={{ marginTop: 'var(--sp-4)' }}>
          {t('nav.regulations')}
        </Link>
      </div>
    );
  }

  const token = getAccessToken();
  const pdfUrl = regulation.file_url
    ? `${regulation.file_url}${token ? `?token=${encodeURIComponent(token)}` : ''}`
    : null;

  return (
    <div className="vl-container vl-page">
      <div className="vl-page-head">
        <p className="vl-eyebrow">{t('nav.regulations')}</p>
        <h1>{regulation.title}</h1>
        <div className="vl-row" style={{ marginTop: 'var(--sp-3)' }}>
          <Badge tone="neutral" plain>
            {t('regulations.version', { n: regulation.version })}
          </Badge>
          {regulation.published_at ? (
            <span className="vl-subtle">{formatDate(regulation.published_at.slice(0, 10))}</span>
          ) : (
            <Badge tone="draft">{t('staff.regulationDraft')}</Badge>
          )}
          {regulation.acceptance?.accepted ? (
            <Badge tone="returned">
              {t('regulations.accepted', {
                date: formatDate(regulation.acceptance.accepted_at.slice(0, 10)),
              })}
            </Badge>
          ) : null}
        </div>
      </div>

      <Card headingLevel={2}>
        {regulation.content_type === 'pdf' && pdfUrl ? (
          <div className="vl-stack">
            <object data={pdfUrl} type="application/pdf" width="100%" height="640">
              <p>
                {t('regulations.pdfFallback')}{' '}
                <a href={pdfUrl} rel="noreferrer">
                  {t('regulations.downloadPdf')}
                </a>
              </p>
            </object>
          </div>
        ) : (
          <MarkdownView source={regulation.body} />
        )}
      </Card>
    </div>
  );
}
