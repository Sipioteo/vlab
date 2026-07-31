import { Logo } from './Logo';
import { t } from '@/i18n/it';

/** Full-page boot splash shown while the stored refresh token is exchanged. */
export function Splash() {
  return (
    <div
      style={{
        minHeight: '60vh',
        display: 'grid',
        placeItems: 'center',
        gap: 'var(--sp-4)',
        padding: 'var(--sp-6)',
      }}
      role="status"
      aria-live="polite"
    >
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 'var(--sp-3)' }}>
        <Logo variant="mark" tone="light-bg" size={44} />
        <span className="vl-eyebrow" style={{ margin: 0 }}>
          {t('app.loading')}
        </span>
      </div>
    </div>
  );
}
