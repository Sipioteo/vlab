import { Link } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/it';

function ErrorScreen({
  code,
  title,
  body,
  icon,
}: {
  code: string;
  title: string;
  body: string;
  icon: 'alert' | 'lock';
}) {
  return (
    <div className="vl-container vl-page">
      <div
        style={{
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          textAlign: 'center',
          gap: 'var(--sp-4)',
          padding: 'var(--sp-8) 0',
        }}
      >
        <span style={{ color: 'var(--color-line-strong)' }}>
          <Icon name={icon} size={44} strokeWidth={1.2} />
        </span>
        <p className="vl-eyebrow" style={{ margin: 0 }}>
          {code}
        </p>
        <h1>{title}</h1>
        <p className="vl-lead">{body}</p>
        <Link to="/" className="vl-btn vl-btn--primary">
          {t('errors.goHome')}
        </Link>
      </div>
    </div>
  );
}

export function NotFoundPage() {
  return (
    <ErrorScreen code="404" title={t('errors.notFoundTitle')} body={t('errors.notFoundBody')} icon="alert" />
  );
}

export function ForbiddenPage() {
  return (
    <ErrorScreen code="403" title={t('errors.forbiddenTitle')} body={t('errors.forbiddenBody')} icon="lock" />
  );
}
