import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from './Icon';
import { Badge, Button, ProductImage } from './ui';
import { getAccessToken } from '@/api/client';
import { useEnums } from '@/hooks/useEnums';
import { t } from '@/i18n/it';
import { formatDate, formatDateTime, formatMonthLabel } from '@/lib/format';
import type {
  OrderAction,
  OrderEvent,
  OrderStatus,
  ProductSummary,
  Regulation,
  TimeSlot,
  Violation,
} from '@/types/api';

/* --------------------------------------------------------------- StatusBadge */

/** Status colour mapping is binding (SPEC §12.3) and never colour-only. */
export function StatusBadge({ status }: { status: OrderStatus }) {
  const { label } = useEnums();
  return <Badge tone={status}>{label('order_status', status)}</Badge>;
}

export function UnitStatusBadge({ status }: { status: string }) {
  const { label } = useEnums();
  return (
    <Badge tone={status} plain>
      {label('unit_status', status)}
    </Badge>
  );
}

export function ProductStatusBadge({ status }: { status: string }) {
  const { label } = useEnums();
  return (
    <Badge tone={status} plain>
      {label('product_status', status)}
    </Badge>
  );
}

/* ---------------------------------------------------------- AvailabilityBadge */

export function AvailabilityBadge({
  available,
  capacity,
}: {
  available: number | null | undefined;
  capacity?: number | null;
}) {
  if (available === null || available === undefined) return null;
  if (available <= 0) {
    return (
      <span className="vl-avail vl-avail--ko">
        <span className="vl-avail__dot" aria-hidden="true" />
        {t('cart.availabilityKo')}
      </span>
    );
  }
  const partial = typeof capacity === 'number' && capacity > 0 && available < capacity;
  return (
    <span className={`vl-avail ${partial ? 'vl-avail--partial' : 'vl-avail--ok'}`}>
      <span className="vl-avail__dot" aria-hidden="true" />
      {t('cart.availabilityOk', { n: available })}
    </span>
  );
}

/* ------------------------------------------------------------- ProductCard */

export function ProductCard({
  product,
  view = 'grid',
  showAvailability,
  footer,
}: {
  product: ProductSummary;
  view?: 'grid' | 'list';
  showAvailability?: boolean;
  footer?: ReactNode;
}) {
  const { label } = useEnums();
  return (
    <article className="vl-pcard">
      <div className="vl-pcard__media">
        <Link to={`/prodotto/${product.slug}`} tabIndex={-1} aria-hidden="true">
          <ProductImage src={product.image_url} alt={t('a11y.productImage', { name: product.name })} />
        </Link>
      </div>
      <div className="vl-pcard__body">
        <span className="vl-pcard__cat">{product.category.name}</span>
        <h3 className="vl-pcard__name">
          <Link to={`/prodotto/${product.slug}`}>{product.name}</Link>
        </h3>
        {product.brand ? <span className="vl-pcard__brand">{product.brand}</span> : null}
        {view === 'list' && product.model ? (
          <span className="vl-subtle">{product.model}</span>
        ) : null}
        <div className="vl-pcard__foot">
          {showAvailability ? (
            <AvailabilityBadge available={product.available_quantity} capacity={product.capacity} />
          ) : product.status !== 'available' ? (
            <Badge tone={product.status} plain>
              {label('product_status', product.status)}
            </Badge>
          ) : (
            <span className="vl-subtle">{t('product.unitsAvailable', { n: product.units_available })}</span>
          )}
          {product.has_required_regulations ? (
            <span title={t('product.regulationsWarning')} style={{ color: 'var(--color-warning)' }}>
              <Icon name="shield" size={15} title={t('product.regulationsWarning')} />
            </span>
          ) : null}
        </div>
        {footer}
      </div>
    </article>
  );
}

/* ------------------------------------------------------------ LimitWarnings */

export function LimitWarningList({ violations }: { violations: Violation[] }) {
  if (violations.length === 0) return null;
  const hard = violations.filter((v) => v.severity === 'hard');
  const soft = violations.filter((v) => v.severity === 'soft');
  return (
    <div className="vl-stack" data-testid="limit-warnings">
      {hard.length > 0 ? (
        <div className="vl-alert vl-alert--danger" role="alert">
          <Icon name="alert" size={18} />
          <div className="vl-alert__body">
            <div className="vl-alert__title">{t('checkout.blockedTitle')}</div>
            <ul>
              {hard.map((v) => (
                <li key={`${v.code}-${v.message}`} data-severity="hard">
                  {v.message}
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}
      {soft.length > 0 ? (
        <div className="vl-alert vl-alert--warning">
          <Icon name="alert" size={18} />
          <div className="vl-alert__body">
            <div className="vl-alert__title">{t('checkout.exceedsTitle')}</div>
            <ul>
              {soft.map((v) => (
                <li key={`${v.code}-${v.message}`} data-severity="soft">
                  {v.message}
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}
    </div>
  );
}

/* --------------------------------------------------------- OrderStatusTimeline */

export function OrderStatusTimeline({ events }: { events: OrderEvent[] }) {
  const ordered = [...events].sort(
    (a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime(),
  );
  return (
    <ol className="vl-timeline" data-testid="order-timeline">
      {ordered.map((event, index) => (
        <li
          key={event.id}
          className={`vl-timeline__item${index === ordered.length - 1 ? ' vl-timeline__item--current' : ''}`}
        >
          <span className="vl-timeline__dot" aria-hidden="true" />
          <div className="vl-timeline__head">
            <span className="vl-timeline__title">{event.action_label}</span>
            <time className="vl-timeline__time" dateTime={event.created_at}>
              {formatDateTime(event.created_at)}
            </time>
          </div>
          <div className="vl-subtle">
            {event.actor_type === 'system' ? 'Sistema' : (event.actor?.display_name ?? '—')}
          </div>
          {event.comment ? <p className="vl-timeline__comment">{event.comment}</p> : null}
        </li>
      ))}
    </ol>
  );
}

/* ------------------------------------------------------------- OrderActions */

const ACTION_VARIANT: Record<string, 'primary' | 'ghost' | 'danger' | 'secondary'> = {
  approve: 'primary',
  pickup: 'primary',
  return: 'primary',
  reject: 'danger',
  cancel: 'danger',
  mark_no_show: 'ghost',
  reopen: 'secondary',
  edit: 'ghost',
  note: 'ghost',
  submit: 'primary',
};

/** Buttons are rendered EXCLUSIVELY from Order.allowed_actions (SPEC §8.4). */
export function OrderActions({
  actions,
  onAction,
  busyAction,
  size = 'md',
}: {
  actions: OrderAction[];
  onAction: (action: OrderAction) => void;
  busyAction?: OrderAction | null;
  size?: 'sm' | 'md';
}) {
  if (actions.length === 0) return null;
  return (
    <div className="vl-row" data-testid="order-actions">
      {actions.map((action) => (
        <Button
          key={action}
          size={size}
          variant={ACTION_VARIANT[action] ?? 'ghost'}
          loading={busyAction === action}
          onClick={() => onAction(action)}
        >
          {t(`actions.${action}`)}
        </Button>
      ))}
    </div>
  );
}

/* ------------------------------------------------------------- MarkdownView */

type Block =
  | { kind: 'h'; level: 1 | 2 | 3; text: string }
  | { kind: 'p'; text: string }
  | { kind: 'ul'; items: string[] }
  | { kind: 'ol'; items: string[] }
  | { kind: 'quote'; text: string };

function parseMarkdown(source: string): Block[] {
  const blocks: Block[] = [];
  const lines = source.replace(/\r\n/g, '\n').split('\n');
  let paragraph: string[] = [];
  let list: { type: 'ul' | 'ol'; items: string[] } | null = null;

  const flushParagraph = () => {
    if (paragraph.length > 0) {
      blocks.push({ kind: 'p', text: paragraph.join(' ') });
      paragraph = [];
    }
  };
  const flushList = () => {
    if (list) {
      blocks.push(list.type === 'ul' ? { kind: 'ul', items: list.items } : { kind: 'ol', items: list.items });
      list = null;
    }
  };

  for (const raw of lines) {
    const line = raw.trimEnd();
    const heading = /^(#{1,3})\s+(.*)$/.exec(line);
    const bullet = /^[-*+]\s+(.*)$/.exec(line);
    const numbered = /^\d+[.)]\s+(.*)$/.exec(line);
    const quote = /^>\s?(.*)$/.exec(line);

    if (line.trim() === '') {
      flushParagraph();
      flushList();
    } else if (heading) {
      flushParagraph();
      flushList();
      blocks.push({ kind: 'h', level: heading[1]!.length as 1 | 2 | 3, text: heading[2] ?? '' });
    } else if (bullet) {
      flushParagraph();
      if (!list || list.type !== 'ul') {
        flushList();
        list = { type: 'ul', items: [] };
      }
      list.items.push(bullet[1] ?? '');
    } else if (numbered) {
      flushParagraph();
      if (!list || list.type !== 'ol') {
        flushList();
        list = { type: 'ol', items: [] };
      }
      list.items.push(numbered[1] ?? '');
    } else if (quote) {
      flushParagraph();
      flushList();
      blocks.push({ kind: 'quote', text: quote[1] ?? '' });
    } else {
      flushList();
      paragraph.push(line.trim());
    }
  }
  flushParagraph();
  flushList();
  return blocks;
}

/** Inline emphasis, rendered as React nodes — never dangerouslySetInnerHTML. */
function inline(text: string, keyPrefix: string): ReactNode[] {
  const nodes: ReactNode[] = [];
  const pattern = /(\*\*[^*]+\*\*|`[^`]+`|\*[^*]+\*|_[^_]+_)/g;
  let lastIndex = 0;
  let match: RegExpExecArray | null;
  let i = 0;
  while ((match = pattern.exec(text)) !== null) {
    if (match.index > lastIndex) nodes.push(text.slice(lastIndex, match.index));
    const token = match[0];
    const key = `${keyPrefix}-${i++}`;
    if (token.startsWith('**')) nodes.push(<strong key={key}>{token.slice(2, -2)}</strong>);
    else if (token.startsWith('`')) nodes.push(<code key={key}>{token.slice(1, -1)}</code>);
    else nodes.push(<em key={key}>{token.slice(1, -1)}</em>);
    lastIndex = match.index + token.length;
  }
  if (lastIndex < text.length) nodes.push(text.slice(lastIndex));
  return nodes;
}

/**
 * Minimal, safe Markdown renderer (headings, lists, quotes, emphasis).
 * HTML in the source is never interpreted — regulation bodies are
 * staff-authored but must not be able to inject scripts (SPEC §11.1).
 */
export function MarkdownView({ source }: { source: string | null | undefined }) {
  const blocks = useMemo(() => parseMarkdown(source ?? ''), [source]);
  return (
    <div className="vl-md">
      {blocks.map((block, index) => {
        const key = `b${index}`;
        switch (block.kind) {
          case 'h': {
            const Tag = (`h${block.level + 1}` as unknown) as 'h2' | 'h3' | 'h4';
            return <Tag key={key}>{inline(block.text, key)}</Tag>;
          }
          case 'ul':
            return (
              <ul key={key}>
                {block.items.map((item, i) => (
                  <li key={`${key}-${i}`}>{inline(item, `${key}-${i}`)}</li>
                ))}
              </ul>
            );
          case 'ol':
            return (
              <ol key={key}>
                {block.items.map((item, i) => (
                  <li key={`${key}-${i}`}>{inline(item, `${key}-${i}`)}</li>
                ))}
              </ol>
            );
          case 'quote':
            return <blockquote key={key}>{inline(block.text, key)}</blockquote>;
          default:
            return <p key={key}>{inline(block.text, key)}</p>;
        }
      })}
    </div>
  );
}

/* ------------------------------------------------------ RegulationAcceptBlock */

/** PDF stream URL carrying the access token as a query param (SPEC §7.10 #56). */
function pdfHref(fileUrl: string | null | undefined): string {
  if (!fileUrl) return '#';
  const token = getAccessToken();
  return `${fileUrl}${token ? `?token=${encodeURIComponent(token)}` : ''}`;
}

/**
 * The checkbox stays disabled until the body has been scrolled to the end
 * (SPEC §11.5). Short documents that do not overflow are immediately readable
 * and therefore immediately acceptable.
 */
export function RegulationAcceptBlock({
  regulation,
  checked,
  onChange,
  disabled,
}: {
  regulation: Pick<Regulation, 'id' | 'title' | 'summary' | 'version' | 'body' | 'content_type' | 'file_url' | 'slug'>;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
}) {
  const [scrolledToEnd, setScrolledToEnd] = useState(false);
  const scrollRef = useRef<HTMLDivElement>(null);
  const id = useId();

  useEffect(() => {
    const node = scrollRef.current;
    if (!node) return;
    if (node.scrollHeight <= node.clientHeight + 4) setScrolledToEnd(true);
  }, [regulation.body]);

  const canAccept = scrolledToEnd && !disabled;

  return (
    <div className="vl-reg-block" data-testid={`regulation-${regulation.id}`}>
      <div className="vl-reg-block__head">
        <div className="vl-row">
          <strong style={{ flex: 1 }}>{regulation.title}</strong>
          <Badge tone="neutral" plain>
            {t('regulations.version', { n: regulation.version })}
          </Badge>
        </div>
        {regulation.summary ? <p className="vl-subtle" style={{ margin: 0 }}>{regulation.summary}</p> : null}
      </div>
      <div
        className="vl-reg-block__scroll"
        ref={scrollRef}
        tabIndex={0}
        role="region"
        aria-label={regulation.title}
        onScroll={(e) => {
          const el = e.currentTarget;
          if (el.scrollTop + el.clientHeight >= el.scrollHeight - 8) setScrolledToEnd(true);
        }}
      >
        {regulation.content_type === 'pdf' ? (
          <p>
            {t('regulations.pdfFallback')}{' '}
            {/*
              Straight to the PDF stream in a new tab, not to the detail route:
              this block is also used inside the blocking gate, where navigating
              the shell behind the modal would leave the document unreadable.
              The `?token=` form is the one SPEC §7.10 #56 provides for embeds.
            */}
            <a
              href={pdfHref(regulation.file_url)}
              target="_blank"
              rel="noreferrer"
              onClick={(e) => {
                if (!regulation.file_url) e.preventDefault();
              }}
            >
              {t('regulations.downloadPdf')}
            </a>
          </p>
        ) : (
          <MarkdownView source={regulation.body} />
        )}
      </div>
      <div className="vl-reg-block__foot">
        <label className={`vl-check${canAccept ? '' : ' vl-check--disabled'}`} htmlFor={id}>
          <input
            id={id}
            type="checkbox"
            checked={checked}
            disabled={!canAccept}
            onChange={(e) => onChange(e.target.checked)}
          />
          <span>{t('checkout.regulationAccept')}</span>
        </label>
        {!scrolledToEnd ? (
          <p className="vl-field__hint" style={{ marginTop: 'var(--sp-2)' }}>
            {t('checkout.regulationScrollHint')}
          </p>
        ) : null}
      </div>
    </div>
  );
}

/* ----------------------------------------------------------- DateRangePicker */

/** Lives in its own module now; re-exported so call sites keep one import. */
export { DateRangePicker } from './DateRangePicker';
export type { DateRangePickerProps } from './DateRangePicker';

/* ------------------------------------------------------------ TimeSlotPicker */

export function TimeSlotPicker({
  slots,
  value,
  onChange,
  label,
  emptyHint,
}: {
  slots: TimeSlot[];
  value: string | null;
  onChange: (value: string) => void;
  label: string;
  emptyHint?: string;
}) {
  return (
    <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
      <legend className="vl-field__label" style={{ marginBottom: 'var(--sp-2)' }}>
        {label}
      </legend>
      {slots.length === 0 ? (
        <p className="vl-field__hint">{emptyHint ?? t('cart.datesMissing')}</p>
      ) : (
        <div className="vl-chips">
          {slots.map((slot) => (
            <button
              key={slot.start}
              type="button"
              className="vl-chip"
              aria-pressed={value === slot.start}
              onClick={() => onChange(slot.start)}
            >
              {slot.start}–{slot.end}
            </button>
          ))}
        </div>
      )}
    </fieldset>
  );
}

/* ------------------------------------------------------- AvailabilityCalendar */

export type HeatState = 'ok' | 'partial' | 'ko' | 'closed';

export interface HeatDay {
  date: string;
  state: HeatState;
  title?: string;
  value?: string;
}

const DOW_LABELS = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
const STATE_LABEL: Record<HeatState, string> = {
  ok: t('availabilityFinder.legendAvailable'),
  partial: t('availabilityFinder.legendPartial'),
  ko: t('availabilityFinder.legendUnavailable'),
  closed: t('availabilityFinder.legendClosed'),
};

/** Month heat-map with distinct fill patterns — never colour alone (SPEC §12.3). */
export function AvailabilityCalendar({
  days,
  onSelect,
}: {
  days: HeatDay[];
  onSelect?: (date: string) => void;
}) {
  const months = useMemo(() => {
    const grouped = new Map<string, HeatDay[]>();
    for (const day of days) {
      const key = day.date.slice(0, 7);
      const bucket = grouped.get(key);
      if (bucket) bucket.push(day);
      else grouped.set(key, [day]);
    }
    return Array.from(grouped.entries());
  }, [days]);

  return (
    <div className="vl-stack" data-testid="availability-heatmap">
      {months.map(([month, monthDays]) => {
        const first = monthDays[0];
        if (!first) return null;
        // Monday-first offset from JS getDay() (Sunday = 0)
        const jsDay = new Date(`${first.date}T00:00:00`).getDay();
        const offset = (jsDay + 6) % 7;
        return (
          <div key={month}>
            <div className="vl-heat__month">{formatMonthLabel(`${month}-01`)}</div>
            <div className="vl-heat" role="grid" aria-label={formatMonthLabel(`${month}-01`)}>
              {DOW_LABELS.map((dow) => (
                <div key={dow} className="vl-heat__dow" role="columnheader">
                  {dow}
                </div>
              ))}
              {Array.from({ length: offset }, (_, i) => (
                <div key={`pad-${i}`} className="vl-heat__cell vl-heat__cell--empty" />
              ))}
              {monthDays.map((day) => {
                const dayNumber = Number(day.date.slice(8, 10));
                const aria = t('a11y.calendarDay', {
                  date: formatDate(day.date),
                  state: STATE_LABEL[day.state],
                });
                const className = `vl-heat__cell vl-heat__cell--${day.state}`;
                return onSelect && day.state !== 'closed' ? (
                  <button
                    key={day.date}
                    type="button"
                    className={className}
                    title={day.title ?? aria}
                    aria-label={aria}
                    data-state={day.state}
                    onClick={() => onSelect(day.date)}
                  >
                    {dayNumber}
                  </button>
                ) : (
                  <div
                    key={day.date}
                    className={className}
                    title={day.title ?? aria}
                    aria-label={aria}
                    data-state={day.state}
                    role="gridcell"
                  >
                    {dayNumber}
                  </div>
                );
              })}
            </div>
          </div>
        );
      })}
      <div className="vl-heat-legend">
        <span>
          <i style={{ background: 'var(--color-highlight-050)', borderColor: 'var(--color-highlight)' }} />
          {t('availabilityFinder.legendAvailable')}
        </span>
        <span>
          <i style={{ background: 'var(--color-accent-050)', borderColor: 'var(--color-accent)' }} />
          {t('availabilityFinder.legendPartial')}
        </span>
        <span>
          <i style={{ background: 'var(--color-danger-050)', borderColor: 'var(--color-danger)' }} />
          {t('availabilityFinder.legendUnavailable')}
        </span>
        <span>
          <i
            style={{
              background:
                'repeating-linear-gradient(45deg,var(--color-surface-sunken),var(--color-surface-sunken) 3px,var(--color-line) 3px,var(--color-line) 4px)',
            }}
          />
          {t('availabilityFinder.legendClosed')}
        </span>
      </div>
    </div>
  );
}
