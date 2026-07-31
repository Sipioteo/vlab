import {
  forwardRef,
  useCallback,
  useEffect,
  useId,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react';
import { createPortal } from 'react-dom';
import { Icon, type IconName } from './Icon';
import { t } from '@/i18n/it';

/* -------------------------------------------------------------------- Button */

type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'ghost'
  | 'outline-accent'
  | 'danger'
  | 'quiet'
  | 'on-dark';
type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  block?: boolean;
  icon?: IconName;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'ghost', size = 'md', loading, block, icon, children, className, disabled, ...rest },
  ref,
) {
  const classes = [
    'vl-btn',
    `vl-btn--${variant}`,
    size !== 'md' ? `vl-btn--${size}` : '',
    block ? 'vl-btn--block' : '',
    !children ? 'vl-btn--icon' : '',
    className ?? '',
  ]
    .filter(Boolean)
    .join(' ');
  return (
    <button ref={ref} className={classes} disabled={disabled || loading} {...rest}>
      {loading ? <span className="vl-spinner" aria-hidden="true" /> : icon ? <Icon name={icon} size={16} /> : null}
      {children}
    </button>
  );
});

/* ---------------------------------------------------------------------- Card */

export function Card({
  title,
  actions,
  children,
  footer,
  className,
  headingLevel = 2,
}: {
  title?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  className?: string;
  headingLevel?: 2 | 3;
}) {
  const Heading = headingLevel === 2 ? 'h2' : 'h3';
  return (
    <section className={`vl-card ${className ?? ''}`}>
      {title ? (
        <div className="vl-card__head">
          <Heading style={{ flex: 1 }}>{title}</Heading>
          {actions}
        </div>
      ) : null}
      <div className="vl-card__body">{children}</div>
      {footer ? <div className="vl-card__foot">{footer}</div> : null}
    </section>
  );
}

/* --------------------------------------------------------------------- Badge */

export function Badge({
  tone = 'neutral',
  children,
  plain,
}: {
  tone?: string;
  children: ReactNode;
  plain?: boolean;
}) {
  return (
    <span className={`vl-badge vl-badge--${tone}${plain ? ' vl-badge--plain' : ''}`}>{children}</span>
  );
}

/* --------------------------------------------------------------------- Alert */

export function Alert({
  level = 'info',
  title,
  children,
  icon = 'info',
}: {
  level?: 'info' | 'warning' | 'danger' | 'success' | 'vr';
  title?: ReactNode;
  children?: ReactNode;
  icon?: IconName;
}) {
  return (
    <div className={`vl-alert vl-alert--${level}`} role={level === 'danger' ? 'alert' : undefined}>
      <Icon name={icon} size={18} />
      <div className="vl-alert__body">
        {title ? <div className="vl-alert__title">{title}</div> : null}
        {children}
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------- EmptyState */

export function EmptyState({
  icon = 'box',
  title,
  body,
  action,
}: {
  icon?: IconName;
  title: string;
  body?: string;
  action?: ReactNode;
}) {
  return (
    <div className="vl-empty">
      <span className="vl-empty__icon">
        <Icon name={icon} size={34} strokeWidth={1.3} />
      </span>
      <h3>{title}</h3>
      {body ? <p>{body}</p> : null}
      {action}
    </div>
  );
}

/* ------------------------------------------------------------------ Skeleton */

export function Skeleton({ height = 16, width, radius }: { height?: number | string; width?: number | string; radius?: number }) {
  return (
    <span
      className="vl-skeleton"
      style={{ display: 'block', height, width: width ?? '100%', borderRadius: radius }}
      aria-hidden="true"
    />
  );
}

export function SkeletonGrid({ count = 8 }: { count?: number }) {
  return (
    <div className="vl-grid-products" aria-busy="true" aria-label={t('app.loading')}>
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className="vl-pcard">
          <Skeleton height={150} radius={0} />
          <div className="vl-pcard__body">
            <Skeleton height={10} width="40%" />
            <Skeleton height={14} />
            <Skeleton height={14} width="70%" />
          </div>
        </div>
      ))}
    </div>
  );
}

export function SkeletonList({ rows = 5, height = 64 }: { rows?: number; height?: number }) {
  return (
    <div className="vl-stack" aria-busy="true" aria-label={t('app.loading')}>
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} height={height} radius={6} />
      ))}
    </div>
  );
}

/* ---------------------------------------------------------------- Pagination */

export function Pagination({
  page,
  totalPages,
  onChange,
}: {
  page: number;
  totalPages: number;
  onChange: (page: number) => void;
}) {
  if (totalPages <= 1) return null;
  const go = (next: number) => {
    onChange(next);
    if (typeof window !== 'undefined' && typeof window.scrollTo === 'function') {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };
  return (
    <nav className="vl-pagination" aria-label={t('pagination.label', { page, total: totalPages })}>
      <Button size="sm" onClick={() => go(page - 1)} disabled={page <= 1} aria-label={t('a11y.prevPage')}>
        <Icon name="chevron-left" size={14} />
        {t('pagination.prev')}
      </Button>
      <span className="vl-pagination__label">{t('pagination.label', { page, total: totalPages })}</span>
      <Button
        size="sm"
        onClick={() => go(page + 1)}
        disabled={page >= totalPages}
        aria-label={t('a11y.nextPage')}
      >
        {t('pagination.next')}
        <Icon name="chevron-right" size={14} />
      </Button>
    </nav>
  );
}

/* --------------------------------------------------------------- form fields */

export function Field({
  label,
  htmlFor,
  hint,
  error,
  optional,
  children,
}: {
  label: string;
  htmlFor: string;
  hint?: string;
  error?: string;
  optional?: boolean;
  children: ReactNode;
}) {
  return (
    <div className="vl-field">
      <label className="vl-field__label" htmlFor={htmlFor}>
        {label}
        {optional ? <span className="vl-field__optional">({t('app.optional')})</span> : null}
      </label>
      {children}
      {hint && !error ? (
        <span className="vl-field__hint" id={`${htmlFor}-hint`}>
          {hint}
        </span>
      ) : null}
      {error ? (
        <span className="vl-field__error" id={`${htmlFor}-error`} role="alert">
          {error}
        </span>
      ) : null}
    </div>
  );
}

export const TextInput = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
  function TextInput(props, ref) {
    return <input ref={ref} className="vl-input" {...props} />;
  },
);

export const TextArea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(
  function TextArea(props, ref) {
    return <textarea ref={ref} className="vl-textarea" {...props} />;
  },
);

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
  function Select(props, ref) {
    return <select ref={ref} className="vl-select" {...props} />;
  },
);

export function Switch({
  checked,
  onChange,
  label,
  disabled,
  id,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label: string;
  disabled?: boolean;
  id?: string;
}) {
  const generated = useId();
  const inputId = id ?? generated;
  return (
    <label className="vl-switch" htmlFor={inputId}>
      <input
        id={inputId}
        type="checkbox"
        role="switch"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
      />
      <span className="vl-switch__track" aria-hidden="true" />
      <span>{label}</span>
    </label>
  );
}

/* -------------------------------------------------------------- SearchInput */

export function SearchInput({
  value,
  onChange,
  placeholder,
  label,
  delay = 300,
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  label: string;
  delay?: number;
}) {
  const [local, setLocal] = useState(value);
  const id = useId();
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  useEffect(() => {
    setLocal(value);
  }, [value]);

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  const handle = (next: string) => {
    setLocal(next);
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => onChangeRef.current(next), delay);
  };

  return (
    <div style={{ position: 'relative' }}>
      <label className="vl-sr-only" htmlFor={id}>
        {label}
      </label>
      <span
        style={{
          position: 'absolute',
          left: 10,
          top: '50%',
          transform: 'translateY(-50%)',
          color: 'var(--color-ink-subtle)',
          pointerEvents: 'none',
        }}
      >
        <Icon name="search" size={16} />
      </span>
      <input
        id={id}
        type="search"
        className="vl-input"
        style={{ paddingLeft: 34 }}
        value={local}
        placeholder={placeholder}
        onChange={(e) => handle(e.target.value)}
      />
    </div>
  );
}

/* --------------------------------------------------------------------- Modal */

export function Modal({
  open,
  onClose,
  title,
  children,
  footer,
  wide,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  footer?: ReactNode;
  wide?: boolean;
}) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const previouslyFocused = useRef<HTMLElement | null>(null);
  const titleId = useId();

  useEffect(() => {
    if (!open) return;
    previouslyFocused.current = document.activeElement as HTMLElement | null;
    const node = dialogRef.current;
    const focusable = node?.querySelector<HTMLElement>(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
    );
    (focusable ?? node)?.focus();
    return () => {
      previouslyFocused.current?.focus?.();
    };
  }, [open]);

  const onKeyDown = useCallback(
    (event: React.KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onClose();
        return;
      }
      if (event.key !== 'Tab') return;
      const node = dialogRef.current;
      if (!node) return;
      const items = Array.from(
        node.querySelectorAll<HTMLElement>(
          'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
      ).filter((el) => el.offsetParent !== null || el === document.activeElement);
      if (items.length === 0) return;
      const first = items[0]!;
      const last = items[items.length - 1]!;
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    },
    [onClose],
  );

  if (!open) return null;

  const content = (
    <div className="vl-modal-backdrop" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div
        className={`vl-modal${wide ? ' vl-modal--wide' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        ref={dialogRef}
        tabIndex={-1}
        onKeyDown={onKeyDown}
      >
        <div className="vl-modal__head">
          <h2 id={titleId}>{title}</h2>
          <Button variant="quiet" size="sm" onClick={onClose} aria-label={t('app.close')}>
            <Icon name="close" size={16} />
          </Button>
        </div>
        <div className="vl-modal__body">{children}</div>
        {footer ? <div className="vl-modal__foot">{footer}</div> : null}
      </div>
    </div>
  );

  return typeof document !== 'undefined' ? createPortal(content, document.body) : content;
}

export function ConfirmDialog({
  open,
  title,
  body,
  confirmLabel,
  cancelLabel,
  danger,
  loading,
  onConfirm,
  onCancel,
  children,
  confirmDisabled,
}: {
  open: boolean;
  title: string;
  body?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
  loading?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  children?: ReactNode;
  confirmDisabled?: boolean;
}) {
  return (
    <Modal
      open={open}
      onClose={onCancel}
      title={title}
      footer={
        <>
          <Button onClick={onCancel} variant="ghost">
            {cancelLabel ?? t('app.cancel')}
          </Button>
          <Button
            onClick={onConfirm}
            variant={danger ? 'danger' : 'primary'}
            loading={loading}
            disabled={confirmDisabled}
          >
            {confirmLabel ?? t('app.confirm')}
          </Button>
        </>
      }
    >
      {body ? <p>{body}</p> : null}
      {children}
    </Modal>
  );
}

/* -------------------------------------------------------------- ProductImage */

export function ProductImage({ src, alt, contain = true }: { src: string | null; alt: string; contain?: boolean }) {
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    setFailed(false);
  }, [src]);
  if (!src || failed) {
    return (
      <div className="vl-thumb">
        <span className="vl-thumb__fallback">
          <Icon name="image" size={26} strokeWidth={1.3} />
          {t('app.imageMissing')}
        </span>
      </div>
    );
  }
  return (
    <div className="vl-thumb">
      <img
        src={src}
        alt={alt}
        loading="lazy"
        onError={() => setFailed(true)}
        style={contain ? undefined : { objectFit: 'cover', padding: 0 }}
      />
    </div>
  );
}

/* ------------------------------------------------------------ QuantityStepper */

export function QuantityStepper({
  value,
  onChange,
  min = 1,
  max = 99,
  label,
  disabled,
}: {
  value: number;
  onChange: (value: number) => void;
  min?: number;
  max?: number;
  label: string;
  disabled?: boolean;
}) {
  const id = useId();
  return (
    <div className="vl-stepper">
      <button
        type="button"
        onClick={() => onChange(Math.max(min, value - 1))}
        disabled={disabled || value <= min}
        aria-label={`${label} −`}
      >
        <Icon name="minus" size={14} />
      </button>
      <label className="vl-sr-only" htmlFor={id}>
        {label}
      </label>
      <input
        id={id}
        type="number"
        value={value}
        min={min}
        max={max}
        disabled={disabled}
        onChange={(e) => {
          const next = Number(e.target.value);
          if (!Number.isNaN(next)) onChange(Math.min(max, Math.max(min, next)));
        }}
      />
      <button
        type="button"
        onClick={() => onChange(Math.min(max, value + 1))}
        disabled={disabled || value >= max}
        aria-label={`${label} +`}
      >
        <Icon name="plus" size={14} />
      </button>
    </div>
  );
}

/* ---------------------------------------------------------------------- Tabs */

export function Tabs({
  tabs,
  active,
  onChange,
  label,
}: {
  tabs: { id: string; label: string }[];
  active: string;
  onChange: (id: string) => void;
  label: string;
}) {
  return (
    <div className="vl-tabs" role="tablist" aria-label={label}>
      {tabs.map((tab) => (
        <button
          key={tab.id}
          type="button"
          role="tab"
          id={`tab-${tab.id}`}
          aria-selected={active === tab.id}
          aria-controls={`panel-${tab.id}`}
          className="vl-tab"
          onClick={() => onChange(tab.id)}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}
