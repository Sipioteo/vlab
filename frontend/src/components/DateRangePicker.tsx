import {
  useCallback,
  useEffect,
  useId,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
} from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './Icon';
import { useOpeningCalendar } from '@/hooks/useOpeningCalendar';
import { t } from '@/i18n/it';
import { addDaysIso, inclusiveDays, todayIso } from '@/lib/format';

/* ------------------------------------------------------------ date helpers */

const MONTHS = [
  'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
  'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre',
];
const MONTHS_SHORT = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
/** Monday-first, as every Italian calendar is printed. */
const DOW_SHORT = ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'];
const DOW_LONG = ['lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica'];

interface YearMonth {
  year: number;
  month: number; // 0-11
}

function pad(n: number): string {
  return n < 10 ? `0${n}` : String(n);
}

function isoOf(year: number, month: number, day: number): string {
  return `${year}-${pad(month + 1)}-${pad(day)}`;
}

function partsOf(iso: string): { year: number; month: number; day: number } {
  return {
    year: Number(iso.slice(0, 4)),
    month: Number(iso.slice(5, 7)) - 1,
    day: Number(iso.slice(8, 10)),
  };
}

/** Timezone-proof day arithmetic: everything happens in UTC. */
function shiftDays(iso: string, delta: number): string {
  const { year, month, day } = partsOf(iso);
  const ms = Date.UTC(year, month, day) + delta * 86_400_000;
  const d = new Date(ms);
  return isoOf(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
}

/** 0 = Sunday, matching JS getDay() and the backend weekday numbering. */
function weekdayOf(iso: string): number {
  const { year, month, day } = partsOf(iso);
  return new Date(Date.UTC(year, month, day)).getUTCDay();
}

/** 0 = Monday, for grid placement. */
function gridColumnOf(iso: string): number {
  return (weekdayOf(iso) + 6) % 7;
}

function monthOf(iso: string): YearMonth {
  const { year, month } = partsOf(iso);
  return { year, month };
}

function shiftMonth(ym: YearMonth, delta: number): YearMonth {
  const total = ym.year * 12 + ym.month + delta;
  return { year: Math.floor(total / 12), month: ((total % 12) + 12) % 12 };
}

function sameMonth(a: YearMonth, b: YearMonth): boolean {
  return a.year === b.year && a.month === b.month;
}

function daysInMonth(ym: YearMonth): number {
  return new Date(Date.UTC(ym.year, ym.month + 1, 0)).getUTCDate();
}

/** Weeks of 7 slots; `null` pads the leading/trailing days of other months. */
function weeksOf(ym: YearMonth): (string | null)[][] {
  const total = daysInMonth(ym);
  const first = isoOf(ym.year, ym.month, 1);
  const lead = gridColumnOf(first);
  const cells: (string | null)[] = Array.from({ length: lead }, () => null);
  for (let day = 1; day <= total; day++) cells.push(isoOf(ym.year, ym.month, day));
  while (cells.length % 7 !== 0) cells.push(null);
  const weeks: (string | null)[][] = [];
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));
  return weeks;
}

function clampIso(iso: string, min: string, max: string): string {
  if (iso < min) return min;
  if (iso > max) return max;
  return iso;
}

/** `2026-09-12` → `12 set` (with the year when it is not the current one). */
export function formatDayShort(iso: string, currentYear: number): string {
  const { year, month, day } = partsOf(iso);
  const base = `${day} ${MONTHS_SHORT[month] ?? ''}`;
  return year === currentYear ? base : `${base} ${year}`;
}

function formatDayLabel(iso: string): string {
  const { year, month, day } = partsOf(iso);
  return `${DOW_LONG[gridColumnOf(iso)] ?? ''} ${day} ${MONTHS[month] ?? ''} ${year}`;
}

/* ------------------------------------------------------------- media query */

function useIsNarrow(): boolean {
  const [narrow, setNarrow] = useState(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
    return window.matchMedia('(max-width: 767px)').matches;
  });
  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
    const mql = window.matchMedia('(max-width: 767px)');
    const onChange = () => setNarrow(mql.matches);
    onChange();
    mql.addEventListener?.('change', onChange);
    return () => mql.removeEventListener?.('change', onChange);
  }, []);
  return narrow;
}

/* --------------------------------------------------------------- component */

export type DayAvailability = Record<string, number>;

export interface DateRangePickerProps {
  pickupDate: string | null;
  returnDate: string | null;
  onChange: (next: { pickup_date: string | null; return_date: string | null }) => void;
  /** earliest selectable day; defaults to the backend booking window, else today */
  minDate?: string;
  /** latest selectable day; defaults to the backend booking window, else +365 days */
  maxDate?: string;
  /** extra days that cannot host a pickup, on top of the lab calendar */
  disabledPickup?: Set<string>;
  disabledReturn?: Set<string>;
  /** date → free units; painted with the cyan availability semantics */
  availability?: DayAvailability | null;
  capacity?: number | null;
  /** accessible name of the trigger; defaults to "Periodo di prestito" */
  label?: string;
  /** set to false to ignore lab closures (e.g. a pure search horizon) */
  respectClosures?: boolean;
  className?: string;
  id?: string;
}

/**
 * Hand-rolled range calendar (no date-picker dependency, SPEC §12.2).
 * One trigger, one popover: two months side by side on desktop, a full-screen
 * sheet with one month below 768px. Fully operable from the keyboard.
 */
export function DateRangePicker({
  pickupDate,
  returnDate,
  onChange,
  minDate,
  maxDate,
  disabledPickup,
  disabledReturn,
  availability,
  capacity,
  label,
  respectClosures = true,
  className,
  id,
}: DateRangePickerProps) {
  const generatedId = useId();
  const rootId = id ?? generatedId;
  const isNarrow = useIsNarrow();

  const [open, setOpen] = useState(false);
  const [draftStart, setDraftStart] = useState<string | null>(pickupDate);
  const [draftEnd, setDraftEnd] = useState<string | null>(returnDate);
  const [hovered, setHovered] = useState<string | null>(null);
  const [phase, setPhase] = useState<'start' | 'end'>('start');
  const [focusDate, setFocusDate] = useState<string>(pickupDate ?? todayIso());
  const [view, setView] = useState<YearMonth>(() => monthOf(pickupDate ?? todayIso()));
  const [position, setPosition] = useState<{ top: number; left: number; width: number } | null>(null);

  const triggerRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const focusedDayRef = useRef<HTMLButtonElement>(null);
  const wantsFocus = useRef(false);

  const today = todayIso();
  const calendar = useOpeningCalendar(today, addDaysIso(today, 365), respectClosures);

  const min = minDate ?? calendar.minDate ?? today;
  const max = maxDate ?? calendar.maxDate ?? addDaysIso(today, 365);

  /* Props win whenever the popover is closed — the cart may rewrite the range. */
  useEffect(() => {
    if (open) return;
    setDraftStart(pickupDate);
    setDraftEnd(returnDate);
    setPhase('start');
  }, [pickupDate, returnDate, open]);

  const isClosedDay = useCallback(
    (iso: string, kind: 'pickup' | 'return') => {
      if (!respectClosures) return false;
      if (calendar.closedWeekdays.has(weekdayOf(iso))) return true;
      return kind === 'pickup' ? calendar.noPickup.has(iso) : calendar.noReturn.has(iso);
    },
    [calendar, respectClosures],
  );

  /** Why a day cannot be picked right now — drives both styling and aria. */
  const disabledReason = useCallback(
    (iso: string): 'window' | 'closed' | 'before-start' | null => {
      if (iso < min || iso > max) return 'window';
      const choosingEnd = phase === 'end' && draftStart !== null;
      if (choosingEnd && iso < draftStart) return 'before-start';
      const kind = choosingEnd ? 'return' : 'pickup';
      if (isClosedDay(iso, kind)) return 'closed';
      const extra = kind === 'pickup' ? disabledPickup : disabledReturn;
      if (extra?.has(iso)) return 'closed';
      return null;
    },
    [min, max, phase, draftStart, isClosedDay, disabledPickup, disabledReturn],
  );

  const months = useMemo<YearMonth[]>(
    () => (isNarrow ? [view] : [view, shiftMonth(view, 1)]),
    [isNarrow, view],
  );

  const minMonth = monthOf(min);
  const maxMonth = monthOf(max);
  const canGoPrev = view.year * 12 + view.month > minMonth.year * 12 + minMonth.month;
  const lastVisible = months[months.length - 1]!;
  const canGoNext = lastVisible.year * 12 + lastVisible.month < maxMonth.year * 12 + maxMonth.month;

  const openPanel = useCallback(() => {
    const seed = clampIso(pickupDate ?? today, min, max);
    setDraftStart(pickupDate);
    setDraftEnd(returnDate);
    setPhase(pickupDate && returnDate ? 'start' : pickupDate ? 'end' : 'start');
    setFocusDate(seed);
    setView(monthOf(seed));
    setHovered(null);
    wantsFocus.current = true;
    setOpen(true);
  }, [pickupDate, returnDate, today, min, max]);

  const closePanel = useCallback(
    (restoreFocus = true) => {
      setOpen(false);
      setHovered(null);
      if (restoreFocus) triggerRef.current?.focus();
    },
    [],
  );

  /* Popover placement: portalled and fixed, so a 280px sidebar cannot clip it. */
  useLayoutEffect(() => {
    if (!open || isNarrow) {
      setPosition(null);
      return;
    }
    const place = () => {
      const rect = triggerRef.current?.getBoundingClientRect();
      if (!rect) return;
      const viewportW = window.innerWidth || 1024;
      const viewportH = window.innerHeight || 768;
      const width = Math.min(620, Math.max(300, viewportW - 24));
      const estimatedH = 420;
      const left = Math.max(12, Math.min(rect.left, viewportW - width - 12));
      const below = rect.bottom + 8;
      const top = below + estimatedH > viewportH ? Math.max(12, rect.top - estimatedH - 8) : below;
      setPosition({ top, left, width });
    };
    place();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => {
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open, isNarrow]);

  /* Roving focus: only steal it after opening or an arrow keypress. */
  useEffect(() => {
    if (!open || !wantsFocus.current) return;
    wantsFocus.current = false;
    focusedDayRef.current?.focus();
  }, [open, focusDate, view]);

  function moveFocus(delta: number) {
    const next = clampIso(shiftDays(focusDate, delta), min, max);
    setFocusDate(next);
    const nextMonth = monthOf(next);
    if (!months.some((m) => sameMonth(m, nextMonth))) {
      setView(delta < 0 ? nextMonth : shiftMonth(nextMonth, isNarrow ? 0 : -1));
    }
    wantsFocus.current = true;
  }

  function select(iso: string) {
    if (disabledReason(iso)) return;
    if (phase === 'start' || draftStart === null) {
      setDraftStart(iso);
      setDraftEnd(null);
      setPhase('end');
      setHovered(null);
      return;
    }
    if (iso < draftStart) {
      setDraftStart(iso);
      setDraftEnd(null);
      return;
    }
    setDraftEnd(iso);
    setPhase('start');
    setHovered(null);
    onChange({ pickup_date: draftStart, return_date: iso });
  }

  function clear() {
    setDraftStart(null);
    setDraftEnd(null);
    setPhase('start');
    setHovered(null);
    onChange({ pickup_date: null, return_date: null });
  }

  function onGridKeyDown(event: ReactKeyboardEvent<HTMLDivElement>) {
    switch (event.key) {
      case 'ArrowLeft':
        event.preventDefault();
        moveFocus(-1);
        break;
      case 'ArrowRight':
        event.preventDefault();
        moveFocus(1);
        break;
      case 'ArrowUp':
        event.preventDefault();
        moveFocus(-7);
        break;
      case 'ArrowDown':
        event.preventDefault();
        moveFocus(7);
        break;
      case 'Home':
        event.preventDefault();
        moveFocus(-gridColumnOf(focusDate));
        break;
      case 'End':
        event.preventDefault();
        moveFocus(6 - gridColumnOf(focusDate));
        break;
      case 'PageUp':
        event.preventDefault();
        moveFocus(-daysInMonth(shiftMonth(monthOf(focusDate), -1)));
        break;
      case 'PageDown':
        event.preventDefault();
        moveFocus(daysInMonth(monthOf(focusDate)));
        break;
      case 'Enter':
      case ' ':
        event.preventDefault();
        select(focusDate);
        break;
      default:
        break;
    }
  }

  function onPanelKeyDown(event: ReactKeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      closePanel();
      return;
    }
    if (event.key !== 'Tab') return;
    const node = panelRef.current;
    if (!node) return;
    const items = Array.from(
      node.querySelectorAll<HTMLElement>('button:not([disabled]):not([tabindex="-1"])'),
    );
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
  }

  /* -------------------------------------------------------------- rendering */

  const currentYear = Number(today.slice(0, 4));
  const shownStart = open ? draftStart : pickupDate;
  const shownEnd = open ? draftEnd : returnDate;
  const summary = shownStart
    ? shownEnd
      ? `${formatDayShort(shownStart, currentYear)} → ${formatDayShort(shownEnd, currentYear)}`
      : `${formatDayShort(shownStart, currentYear)} → …`
    : t('dates.placeholder');
  const hasRange = Boolean(pickupDate || returnDate);
  const triggerLabel = label ?? t('dates.label');
  const countedDays = inclusiveDays(draftStart, draftEnd) ?? 0;

  const rangeEnd = draftEnd ?? (phase === 'end' && hovered && draftStart && hovered >= draftStart ? hovered : null);

  function dayClasses(iso: string, reason: ReturnType<typeof disabledReason>): string {
    const classes = ['vl-drp__day'];
    const isStart = draftStart === iso;
    const isEnd = draftEnd === iso || (rangeEnd === iso && draftStart !== iso);
    const inside = Boolean(draftStart && rangeEnd && iso > draftStart && iso < rangeEnd);
    if (reason) classes.push('vl-drp__day--disabled');
    if (reason === 'closed') classes.push('vl-drp__day--closed');
    if (iso === today) classes.push('vl-drp__day--today');
    if (isStart || isEnd) classes.push('vl-drp__day--edge');
    if (isStart) classes.push('vl-drp__day--start');
    if (isEnd) classes.push('vl-drp__day--end');
    if (inside) classes.push('vl-drp__day--inrange');
    if ((isStart && rangeEnd && rangeEnd !== iso) || inside || (isEnd && draftStart !== iso)) {
      classes.push('vl-drp__day--band');
    }
    if (draftEnd === null && rangeEnd !== null && (isStart || isEnd || inside)) {
      classes.push('vl-drp__day--preview');
    }
    return classes.join(' ');
  }

  function availabilityTone(iso: string): 'ok' | 'partial' | 'ko' | null {
    if (!availability) return null;
    const free = availability[iso];
    if (free === undefined) return null;
    if (free <= 0) return 'ko';
    if (typeof capacity === 'number' && capacity > 0 && free < capacity) return 'partial';
    return 'ok';
  }

  const panel = (
    <div
      className={`vl-drp__layer${isNarrow ? ' vl-drp__layer--sheet' : ''}`}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) closePanel();
      }}
    >
      <div
        className={`vl-drp__panel${isNarrow ? ' vl-drp__panel--sheet' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-label={t('dates.dialogLabel')}
        ref={panelRef}
        onKeyDown={onPanelKeyDown}
        style={
          position && !isNarrow
            ? { top: position.top, left: position.left, width: position.width }
            : undefined
        }
      >
        <div className="vl-drp__head">
          <div className="vl-drp__headtext">
            <strong>{phase === 'end' && draftStart ? t('dates.chooseEnd') : t('dates.chooseStart')}</strong>
            <span className="vl-drp__headsub">
              {phase === 'end' && draftStart ? t('dates.hintEnd') : t('dates.hintStart')}
            </span>
          </div>
          <button
            type="button"
            className="vl-drp__iconbtn vl-drp__closebtn"
            aria-label={t('app.close')}
            onClick={() => closePanel()}
          >
            <Icon name="close" size={16} />
          </button>
        </div>

        <div className="vl-drp__months" onKeyDown={onGridKeyDown}>
          {months.map((ym, index) => {
            const caption = `${MONTHS[ym.month] ?? ''} ${ym.year}`;
            return (
              <div className="vl-drp__month" key={`${ym.year}-${ym.month}`}>
                <div className="vl-drp__caption">
                  {index === 0 ? (
                    <button
                      type="button"
                      className="vl-drp__iconbtn"
                      aria-label={t('dates.prevMonth')}
                      disabled={!canGoPrev}
                      onClick={() => setView((v) => shiftMonth(v, -1))}
                    >
                      <Icon name="chevron-left" size={16} />
                    </button>
                  ) : (
                    <span className="vl-drp__iconbtn vl-drp__iconbtn--ghost" aria-hidden="true" />
                  )}
                  <span className="vl-drp__captiontext">{caption}</span>
                  {index === months.length - 1 ? (
                    <button
                      type="button"
                      className="vl-drp__iconbtn"
                      aria-label={t('dates.nextMonth')}
                      disabled={!canGoNext}
                      onClick={() => setView((v) => shiftMonth(v, 1))}
                    >
                      <Icon name="chevron-right" size={16} />
                    </button>
                  ) : (
                    <span className="vl-drp__iconbtn vl-drp__iconbtn--ghost" aria-hidden="true" />
                  )}
                </div>

                <div className="vl-drp__grid" role="grid" aria-label={caption}>
                  <div className="vl-drp__row vl-drp__row--dow" role="row">
                    {DOW_SHORT.map((dow, i) => (
                      <span
                        key={dow}
                        role="columnheader"
                        className="vl-drp__dow"
                        aria-label={DOW_LONG[i] ?? dow}
                      >
                        {dow}
                      </span>
                    ))}
                  </div>
                  {weeksOf(ym).map((week, wi) => (
                    <div className="vl-drp__row" role="row" key={`${ym.year}-${ym.month}-w${wi}`}>
                      {week.map((iso, di) => {
                        if (!iso) {
                          return (
                            <span
                              key={`pad-${wi}-${di}`}
                              role="gridcell"
                              className="vl-drp__day vl-drp__day--pad"
                            />
                          );
                        }
                        const reason = disabledReason(iso);
                        const tone = availabilityTone(iso);
                        const parts = [formatDayLabel(iso)];
                        if (iso === today) parts.push(t('dates.today'));
                        if (reason === 'closed') parts.push(t('dates.closedDay'));
                        if (reason === 'window') parts.push(t('dates.outOfWindow'));
                        if (reason === 'before-start') parts.push(t('dates.beforeStart'));
                        if (tone && !reason) parts.push(t('dates.unitsFree', { n: availability?.[iso] ?? 0 }));
                        return (
                          <button
                            key={iso}
                            type="button"
                            role="gridcell"
                            ref={iso === focusDate ? focusedDayRef : undefined}
                            tabIndex={iso === focusDate ? 0 : -1}
                            className={`${dayClasses(iso, reason)}${tone ? ` vl-drp__day--av-${tone}` : ''}`}
                            aria-label={parts.join(', ')}
                            aria-disabled={reason ? true : undefined}
                            aria-selected={draftStart === iso || draftEnd === iso}
                            aria-current={iso === today ? 'date' : undefined}
                            data-date={iso}
                            data-disabled={reason ?? undefined}
                            onClick={() => select(iso)}
                            onFocus={() => setFocusDate(iso)}
                            onMouseEnter={() => (reason ? undefined : setHovered(iso))}
                            onMouseLeave={() => setHovered((h) => (h === iso ? null : h))}
                          >
                            <span className="vl-drp__num">{partsOf(iso).day}</span>
                          </button>
                        );
                      })}
                    </div>
                  ))}
                </div>
              </div>
            );
          })}
        </div>

        <div className="vl-drp__foot">
          <button type="button" className="vl-drp__link" onClick={clear}>
            {t('dates.clear')}
          </button>
          <span className="vl-drp__footsummary" aria-live="polite">
            {draftStart && draftEnd
              ? `${summary} · ${
                  countedDays === 1 ? t('dates.durationOne') : t('dates.duration', { n: countedDays })
                }`
              : ''}
          </span>
          <button type="button" className="vl-drp__done" onClick={() => closePanel()}>
            {t('dates.done')}
          </button>
        </div>
      </div>
    </div>
  );

  return (
    <div className={`vl-drp${className ? ` ${className}` : ''}`} id={rootId}>
      <button
        type="button"
        ref={triggerRef}
        className={`vl-drp__trigger${hasRange ? ' vl-drp__trigger--filled' : ''}`}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={`${triggerLabel}: ${summary}`}
        onClick={() => (open ? closePanel() : openPanel())}
      >
        <Icon name="calendar" size={16} />
        <span className="vl-drp__value">{summary}</span>
        <Icon name="chevron-down" size={14} />
      </button>
      {hasRange ? (
        <button
          type="button"
          className="vl-drp__reset"
          aria-label={t('dates.reset')}
          onClick={() => {
            setDraftStart(null);
            setDraftEnd(null);
            setPhase('start');
            onChange({ pickup_date: null, return_date: null });
          }}
        >
          <Icon name="close" size={14} />
        </button>
      ) : null}
      {open && typeof document !== 'undefined' ? createPortal(panel, document.body) : null}
    </div>
  );
}
