import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router';
import { useAuth, useIsStaff } from '@/auth/AuthProvider';
import { useSettings } from '@/settings/SettingsProvider';
import { useCartBadge } from '@/hooks/useCart';
import { useApprovedNotifications } from '@/hooks/useNotifications';
import { Icon } from './Icon';
import { Logo } from './Logo';
import { Badge } from './ui';
import { t } from '@/i18n/it';
import { initials } from '@/lib/format';

interface NavItem {
  to: string;
  label: string;
  end?: boolean;
}

interface WeeklyHours {
  weekday: number;
  closed?: boolean;
  open?: string | null;
  close?: string | null;
}

const DAY_LABELS = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];

/** Today's opening line for the utility bar, straight from `hours.weekly`. */
function todayHours(weekly: WeeklyHours[]): string | null {
  const today = weekly.find((d) => d.weekday === new Date().getDay());
  if (!today) return null;
  if (today.closed || !today.open || !today.close) return t('header.closedToday');
  return t('header.openToday', { from: today.open, to: today.close });
}

/** Collapses identical consecutive weekdays into "Lun–Ven 09:00–17:00" rows. */
function groupHours(weekly: WeeklyHours[]): { days: string; hours: string }[] {
  const ordered = [1, 2, 3, 4, 5, 6, 0]
    .map((wd) => weekly.find((d) => d.weekday === wd))
    .filter((d): d is WeeklyHours => Boolean(d));
  const rows: { days: string; hours: string }[] = [];
  for (const day of ordered) {
    const hours =
      day.closed || !day.open || !day.close
        ? t('footer.hoursClosed')
        : `${day.open}–${day.close}`;
    const label = (DAY_LABELS[day.weekday] ?? '').slice(0, 3);
    const last = rows[rows.length - 1];
    if (last && last.hours === hours) {
      const [first] = last.days.split('–');
      last.days = `${first}–${label}`;
    } else {
      rows.push({ days: label, hours });
    }
  }
  return rows;
}

export function AppShell({ children }: { children: React.ReactNode }) {
  const { user, isAuthenticated, permissions, logout } = useAuth();
  const isStaff = useIsStaff();
  const { settings, get } = useSettings();
  const location = useLocation();
  const navigate = useNavigate();
  const cartCount = useCartBadge();
  const notifications = useApprovedNotifications();
  const [menuOpen, setMenuOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const userMenuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setMenuOpen(false);
    setUserMenuOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    if (!userMenuOpen) return;
    const handler = (event: MouseEvent) => {
      if (!userMenuRef.current?.contains(event.target as Node)) setUserMenuOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [userMenuOpen]);

  const items: NavItem[] = [
    { to: '/catalogo', label: t('nav.catalog') },
    { to: '/disponibilita', label: t('nav.availability') },
    { to: '/regolamento', label: t('nav.regulations') },
  ];
  if (isAuthenticated) items.push({ to: '/ordini', label: t('nav.myOrders') });
  if (isStaff) items.push({ to: '/gestione', label: t('nav.staff') });

  const labName = get<string>('lab.name', 'Visionary Lab');
  const labEmail = get<string>('lab.email', '');
  const labPhone = get<string>('lab.phone', '');
  const labRoom = get<string>('lab.room', '');
  const labAddress = get<string>('lab.address', '');
  const labDepartment = get<string>('lab.department', t('footer.institutionNote'));
  const website = get<string>('lab.website_url', 'https://www.polito.it');
  const bannerEnabled = get<boolean>('ui.banner_enabled', false);
  const bannerMessage = get<string>('ui.banner_message_it', '');
  const bannerLevel = get<string>('ui.banner_level', 'info');
  const showBanner = Boolean(bannerEnabled && bannerMessage);

  const weekly = useMemo(() => {
    const raw = settings['hours.weekly'];
    return Array.isArray(raw) ? (raw as WeeklyHours[]) : [];
  }, [settings]);
  const openingLine = useMemo(() => todayHours(weekly), [weekly]);
  const hourRows = useMemo(() => groupHours(weekly), [weekly]);

  return (
    <div className="vl-app" data-banner={showBanner ? 'on' : undefined}>
      <a className="vl-skip-link" href="#main">
        {t('app.skipToContent')}
      </a>

      {showBanner ? (
        <div className={`vl-banner vl-banner--${bannerLevel}`} role="status">
          {bannerMessage}
        </div>
      ) : null}

      <header className={`vl-header${showBanner ? ' vl-header--offset' : ''}`}>
        {/* Thin navy utility strip: where to find the lab, when it's open, who you are. */}
        <div className="vl-header__utility">
          <div
            className="vl-container vl-header__utilityinner"
            role="toolbar"
            aria-label={t('header.utilityBar')}
          >
            {openingLine ? (
              <span className="vl-header__util">
                <Icon name="clock" size={13} />
                <span>{openingLine}</span>
              </span>
            ) : null}
            {labRoom || labAddress ? (
              <>
                <span
                  className="vl-header__utilsep vl-header__utilsep--hide-sm"
                  aria-hidden="true"
                />
                <span className="vl-header__util vl-header__util--hide-sm">
                  <Icon name="map-pin" size={13} />
                  <span>{labRoom || labAddress}</span>
                </span>
              </>
            ) : null}
            {labEmail ? (
              <>
                <span
                  className="vl-header__utilsep vl-header__utilsep--hide-sm"
                  aria-hidden="true"
                />
                <a className="vl-header__util vl-header__util--hide-sm" href={`mailto:${labEmail}`}>
                  <Icon name="mail" size={13} />
                  <span>{labEmail}</span>
                </a>
              </>
            ) : null}

            <span className="vl-spacer" />

            {isAuthenticated && user ? (
              <Badge tone="role" plain>
                {user.role_label}
              </Badge>
            ) : (
              <span className="vl-header__inst">{t('footer.institution')}</span>
            )}
          </div>
        </div>

        {/* White main bar: mark, uppercase nav, one orange call to action. */}
        <div className="vl-header__main">
          <div className="vl-container vl-header__inner">
            <Link to="/" className="vl-brand">
              <Logo className="vl-brand__logo" variant="mark" tone="light-bg" size={38} />
              <span className="vl-brand__text">
                <span className="vl-brand__name">{labName}</span>
                <span className="vl-brand__sub">Politecnico di Torino</span>
              </span>
            </Link>

            <nav className="vl-nav" aria-label={t('nav.menu')}>
              {items.map((item) => (
                <NavLink key={item.to} to={item.to} className="vl-nav__link">
                  {item.label}
                </NavLink>
              ))}
            </nav>

            <div className="vl-header__actions">
              {isAuthenticated ? (
                <Link
                  to="/ordini"
                  className="vl-iconbtn"
                  aria-label={`${t('nav.notifications')}${notifications.count > 0 ? ` (${notifications.count})` : ''}`}
                  onClick={notifications.markSeen}
                >
                  <Icon name="bell" size={19} />
                  {notifications.count > 0 ? (
                    <span className="vl-iconbtn__badge">{notifications.count}</span>
                  ) : null}
                </Link>
              ) : null}

              {permissions['orders.create'] ? (
                <Link
                  to="/carrello"
                  className="vl-iconbtn"
                  aria-label={t('a11y.cartCount', { n: cartCount })}
                  data-testid="cart-badge"
                >
                  <Icon name="cart" size={19} />
                  {cartCount > 0 ? <span className="vl-iconbtn__badge">{cartCount}</span> : null}
                </Link>
              ) : null}

              {isAuthenticated && user ? (
                <div className="vl-usermenu" ref={userMenuRef}>
                  <button
                    type="button"
                    className="vl-usermenu__trigger"
                    aria-haspopup="menu"
                    aria-expanded={userMenuOpen}
                    aria-label={t('nav.userMenu')}
                    onClick={() => setUserMenuOpen((open) => !open)}
                  >
                    <span className="vl-avatar" aria-hidden="true">
                      {initials(user.display_name)}
                    </span>
                    <span className="vl-usermenu__name">{user.display_name}</span>
                    <Icon name="chevron-down" size={14} />
                  </button>
                  {userMenuOpen ? (
                    <div className="vl-usermenu__panel" role="menu">
                      <div className="vl-usermenu__head">
                        <div style={{ fontWeight: 600 }}>{user.display_name}</div>
                        <div className="vl-subtle">{user.email}</div>
                        <div style={{ marginTop: 'var(--sp-2)' }}>
                          <Badge tone="accent" plain>
                            {user.role_label}
                          </Badge>
                        </div>
                      </div>
                      <Link to="/profilo" className="vl-usermenu__item" role="menuitem">
                        <Icon name="user" size={16} />
                        {t('nav.profile')}
                      </Link>
                      <Link to="/ordini" className="vl-usermenu__item" role="menuitem">
                        <Icon name="clipboard" size={16} />
                        {t('nav.myOrders')}
                      </Link>
                      {isStaff ? (
                        <Link to="/gestione" className="vl-usermenu__item" role="menuitem">
                          <Icon name="settings" size={16} />
                          {t('nav.staff')}
                        </Link>
                      ) : null}
                      <button
                        type="button"
                        className="vl-usermenu__item"
                        role="menuitem"
                        onClick={() => {
                          void logout().then(() => navigate('/'));
                        }}
                      >
                        <Icon name="logout" size={16} />
                        {t('nav.logout')}
                      </button>
                    </div>
                  ) : null}
                </div>
              ) : (
                <Link to="/login" className="vl-btn vl-btn--primary">
                  {t('nav.login')}
                </Link>
              )}

              <button
                type="button"
                className="vl-iconbtn vl-menutoggle"
                aria-expanded={menuOpen}
                aria-label={menuOpen ? t('nav.closeMenu') : t('nav.openMenu')}
                onClick={() => setMenuOpen((open) => !open)}
              >
                <Icon name={menuOpen ? 'close' : 'menu'} size={20} />
              </button>
            </div>
          </div>
        </div>
      </header>

      {menuOpen ? (
        <nav className="vl-mobilenav" aria-label={t('nav.menu')}>
          {items.map((item) => (
            <NavLink key={item.to} to={item.to} className="vl-mobilenav__link">
              {item.label}
            </NavLink>
          ))}
          {isAuthenticated ? (
            <NavLink to="/profilo" className="vl-mobilenav__link">
              {t('nav.profile')}
            </NavLink>
          ) : null}
        </nav>
      ) : null}

      <main id="main" className="vl-main">
        {children}
      </main>

      <footer className="vl-footer">
        <div className="vl-footer__top">
          <div className="vl-container">
            <div className="vl-footer__grid">
              <div>
                <Link to="/" className="vl-footer__brand">
                  <Logo variant="mark" tone="light-bg" size={36} />
                  <span className="vl-brand__text">
                    <span className="vl-brand__name">{labName}</span>
                    <span className="vl-brand__sub">Politecnico di Torino</span>
                  </span>
                </Link>
                <p className="vl-subtle" style={{ maxWidth: '34ch' }}>
                  {labDepartment}
                </p>
              </div>
              <div>
                <h2>{t('footer.contacts')}</h2>
                <ul>
                  {labRoom ? <li>{labRoom}</li> : null}
                  {labAddress ? <li>{labAddress}</li> : null}
                  {labEmail ? (
                    <li>
                      <a href={`mailto:${labEmail}`}>{labEmail}</a>
                    </li>
                  ) : null}
                  {labPhone ? <li>{labPhone}</li> : null}
                </ul>
              </div>
              <div>
                <h2>{t('footer.hours')}</h2>
                {hourRows.length > 0 ? (
                  <dl className="vl-footer__hours">
                    {hourRows.map((row) => (
                      <div key={row.days} style={{ display: 'contents' }}>
                        <dt>{row.days}</dt>
                        <dd>{row.hours}</dd>
                      </div>
                    ))}
                  </dl>
                ) : (
                  <p className="vl-subtle">{t('footer.hoursNone')}</p>
                )}
              </div>
              <div>
                <h2>{t('footer.links')}</h2>
                <ul>
                  <li>
                    <Link to="/catalogo">{t('nav.catalog')}</Link>
                  </li>
                  <li>
                    <Link to="/disponibilita">{t('nav.availability')}</Link>
                  </li>
                  <li>
                    <Link to="/regolamento">{t('nav.regulations')}</Link>
                  </li>
                  <li>
                    <a href={website} rel="noreferrer">
                      www.polito.it
                    </a>
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        {/* Navy institutional line — the polito.it subfooter. */}
        <div className="vl-footer__sub">
          <div className="vl-container vl-footer__subinner">
            <div className="vl-footer__legal">
              <span>{labName}</span>
              <span>{labDepartment}</span>
              {labAddress ? <span>{labAddress}</span> : null}
              {labEmail ? (
                <span>
                  <a href={`mailto:${labEmail}`}>{labEmail}</a>
                </span>
              ) : null}
            </div>
            <div className="vl-footer__legal">
              <span>{get<string>('ui.footer_note_it', t('footer.note'))}</span>
              {settings['lab.support_note_it'] ? (
                <span>{String(settings['lab.support_note_it'])}</span>
              ) : null}
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}
