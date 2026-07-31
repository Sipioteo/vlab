import { format, parseISO, differenceInCalendarDays, isValid } from 'date-fns';
import { it as itLocale } from 'date-fns/locale/it';

/** `2026-08-01` → `01/08/2026` (SPEC §12.5). */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const date = parseISO(value);
  if (!isValid(date)) return value;
  return format(date, 'dd/MM/yyyy');
}

/** `2026-07-25T11:02:00Z` → `25/07/2026, 11:02`. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—';
  const date = parseISO(value);
  if (!isValid(date)) return value;
  return format(date, 'dd/MM/yyyy, HH:mm');
}

export function formatDayLong(value: string): string {
  const date = parseISO(value);
  if (!isValid(date)) return value;
  return format(date, 'EEEE d MMMM yyyy', { locale: itLocale });
}

export function formatMonthLabel(value: string): string {
  const date = parseISO(value);
  if (!isValid(date)) return value;
  return format(date, 'MMMM yyyy', { locale: itLocale });
}

export function formatTime(value: string | null | undefined): string {
  return value ?? '—';
}

/** Inclusive day count: a 1-day loan has pickup_date === return_date. */
export function inclusiveDays(from: string | null, to: string | null): number | null {
  if (!from || !to) return null;
  const a = parseISO(from);
  const b = parseISO(to);
  if (!isValid(a) || !isValid(b)) return null;
  return differenceInCalendarDays(b, a) + 1;
}

export function todayIso(): string {
  return format(new Date(), 'yyyy-MM-dd');
}

export function addDaysIso(iso: string, days: number): string {
  const date = parseISO(iso);
  if (!isValid(date)) return iso;
  const next = new Date(date.getTime());
  next.setDate(next.getDate() + days);
  return format(next, 'yyyy-MM-dd');
}

export function initials(displayName: string): string {
  const parts = displayName.trim().split(/\s+/).filter(Boolean);
  const first = parts[0]?.[0] ?? '?';
  const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '';
  return (first + last).toUpperCase();
}

export function percent(value: number): string {
  return `${Math.round(value * 1000) / 10}%`;
}

export function plural(n: number, one: string, many: string): string {
  return n === 1 ? one : many;
}
