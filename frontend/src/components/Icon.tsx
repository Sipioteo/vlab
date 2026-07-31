/**
 * Hand-rolled inline SVG icon set (24x24 stroke grid).
 * No icon package, no external asset requests.
 */

export type IconName =
  | 'search'
  | 'cart'
  | 'user'
  | 'menu'
  | 'close'
  | 'chevron-left'
  | 'chevron-right'
  | 'chevron-down'
  | 'chevron-up'
  | 'calendar'
  | 'clock'
  | 'check'
  | 'alert'
  | 'info'
  | 'box'
  | 'camera'
  | 'grid'
  | 'list'
  | 'trash'
  | 'edit'
  | 'plus'
  | 'minus'
  | 'settings'
  | 'chart'
  | 'users'
  | 'file'
  | 'shield'
  | 'logout'
  | 'bell'
  | 'external'
  | 'filter'
  | 'clipboard'
  | 'image'
  | 'vr'
  | 'arrow-right'
  | 'refresh'
  | 'lock'
  | 'mail'
  | 'map-pin';

const PATHS: Record<IconName, string> = {
  search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14ZM20 20l-4.2-4.2',
  cart: 'M3 4h2l2.4 10.4a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 8H6M9 20h.01M17 20h.01',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4 21c0-3.3 3.6-6 8-6s8 2.7 8 6',
  menu: 'M4 6h16M4 12h16M4 18h16',
  close: 'M6 6l12 12M18 6L6 18',
  'chevron-left': 'M15 5l-7 7 7 7',
  'chevron-right': 'M9 5l7 7-7 7',
  'chevron-down': 'M5 9l7 7 7-7',
  'chevron-up': 'M5 15l7-7 7 7',
  calendar: 'M4 6h16v14H4zM4 10h16M9 3v4M15 3v4',
  clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 7v5l3 2',
  mail: 'M3 6h18v12H3zM3 7l9 6 9-6',
  'map-pin': 'M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11ZM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
  check: 'M4 12.5l5 5L20 6.5',
  alert: 'M12 4l9 16H3l9-16ZM12 10v4M12 17h.01',
  info: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 11v5M12 8h.01',
  box: 'M3 8l9-4 9 4v8l-9 4-9-4V8ZM3 8l9 4 9-4M12 12v8',
  camera: 'M3 7h11v10H3zM14 11l7-4v10l-7-4M7 7V5h4v2',
  grid: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
  list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
  trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6',
  edit: 'M4 20h4l10-10-4-4L4 16v4ZM14 6l4 4',
  plus: 'M12 5v14M5 12h14',
  minus: 'M5 12h14',
  settings:
    'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-2.7-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3.6 15H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.1-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 9 4.6V4a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z',
  chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  users: 'M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM2 21c0-3.3 3.1-6 7-6s7 2.7 7 6M17 11a3 3 0 1 0 0-6M18 21c0-2.2-.7-3.5-1.6-4.4',
  file: 'M6 3h8l4 4v14H6zM14 3v4h4',
  shield: 'M12 3l8 3v6c0 5-3.4 8.3-8 9.5C7.4 20.3 4 17 4 12V6l8-3ZM9 12l2 2 4-4',
  logout: 'M15 4h4v16h-4M11 16l4-4-4-4M15 12H3',
  bell: 'M18 15V10a6 6 0 1 0-12 0v5l-2 3h16l-2-3ZM10 21h4',
  external: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
  filter: 'M3 5h18l-7 8v6l-4 2v-8L3 5Z',
  clipboard: 'M9 4h6v3H9zM7 5H5v16h14V5h-2M9 12h6M9 16h4',
  image: 'M4 5h16v14H4zM4 16l4.5-5 3.5 4 3-2.5L20 17',
  vr: 'M3 8h18v7a2 2 0 0 1-2 2h-3l-3-3-3 3H5a2 2 0 0 1-2-2V8ZM8 12h.01M16 12h.01',
  'arrow-right': 'M4 12h16M14 6l6 6-6 6',
  refresh: 'M20 11a8 8 0 1 0-2 6M20 5v6h-6',
  lock: 'M6 11h12v9H6zM9 11V8a3 3 0 1 1 6 0v3',
};

export interface IconProps {
  name: IconName;
  size?: number;
  className?: string;
  strokeWidth?: number;
  'aria-hidden'?: boolean;
  title?: string;
}

export function Icon({ name, size = 18, className, strokeWidth = 1.7, title }: IconProps) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden={title ? undefined : true}
      role={title ? 'img' : undefined}
      focusable="false"
      style={{ flex: 'none' }}
    >
      {title ? <title>{title}</title> : null}
      <path d={PATHS[name]} />
    </svg>
  );
}
