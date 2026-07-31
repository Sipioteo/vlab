import type { ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuth } from '@/auth/AuthProvider';
import { Icon, type IconName } from './Icon';
import { t } from '@/i18n/it';
import type { PermissionKey } from '@/types/api';

interface RailItem {
  to: string;
  label: string;
  icon: IconName;
  permission: PermissionKey;
  end?: boolean;
}

const RAIL: RailItem[] = [
  { to: '/gestione', label: t('staff.dashboard'), icon: 'chart', permission: 'orders.manage', end: true },
  { to: '/gestione/ordini', label: t('staff.ordersQueue'), icon: 'clipboard', permission: 'orders.manage' },
  { to: '/gestione/calendario', label: t('staff.calendar'), icon: 'calendar', permission: 'orders.manage' },
  { to: '/gestione/prodotti', label: t('staff.products'), icon: 'box', permission: 'products.manage' },
  { to: '/gestione/categorie', label: t('staff.categories'), icon: 'grid', permission: 'products.manage' },
  { to: '/gestione/registro', label: t('staff.logs'), icon: 'file', permission: 'logs.create' },
  { to: '/gestione/regolamenti', label: t('staff.regulations'), icon: 'shield', permission: 'regulations.manage' },
  { to: '/gestione/chiusure', label: t('staff.closures'), icon: 'calendar', permission: 'closures.manage' },
  { to: '/gestione/statistiche', label: t('staff.stats'), icon: 'chart', permission: 'stats.view_limited' },
  { to: '/gestione/utenti', label: t('staff.users'), icon: 'users', permission: 'users.view' },
  { to: '/gestione/impostazioni', label: t('staff.settings'), icon: 'settings', permission: 'settings.view' },
  { to: '/gestione/audit', label: t('staff.audit'), icon: 'lock', permission: 'audit.view' },
];

/**
 * Staff shell: dark rail on lg+, horizontal tab strip below.
 * Entries appear only when the corresponding permission is true — a borsista
 * therefore never sees "Attrezzature", "Categorie" or "Regolamenti".
 */
export function StaffLayout({ children }: { children: ReactNode }) {
  const { permissions } = useAuth();
  const items = RAIL.filter((item) => permissions[item.permission]);

  return (
    <div className="vl-staff">
      <nav className="vl-staff__rail" aria-label={t('staff.area')}>
        <div className="vl-staff__railtitle">{t('staff.area')}</div>
        {items.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} className="vl-staff__link">
            <Icon name={item.icon} size={16} />
            {item.label}
          </NavLink>
        ))}
      </nav>
      <nav className="vl-staff__tabs" aria-label={t('staff.area')}>
        {items.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} className="vl-staff__tab">
            {item.label}
          </NavLink>
        ))}
      </nav>
      <div className="vl-staff__content">{children}</div>
    </div>
  );
}
