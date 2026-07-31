import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { addDaysIso, todayIso } from '@/lib/format';
import type { Closure } from '@/types/api';

export interface OpeningInfo {
  isLoading: boolean;
  /** first bookable day according to the backend booking window */
  minDate: string | null;
  /** last bookable day according to the backend booking window */
  maxDate: string | null;
  /** JS weekday numbers (0 = Sunday) the lab is closed on */
  closedWeekdays: Set<number>;
  /** days that cannot host a pickup (closures, closed weekdays) */
  noPickup: Set<string>;
  /** days that cannot host a return */
  noReturn: Set<string>;
  closures: Closure[];
}

const EMPTY: Set<string> = new Set();

/**
 * Opening hours, closures and booking window from GET /calendar/opening
 * (the same source the staff calendar and the availability check use).
 * Public endpoint: works for anonymous visitors too.
 */
export function useOpeningCalendar(
  from: string = todayIso(),
  to: string = addDaysIso(todayIso(), 365),
  enabled = true,
): OpeningInfo {
  const query = useQuery({
    queryKey: ['calendar-opening', from, to],
    queryFn: () => api.getOpeningCalendar({ from, to }),
    enabled,
    staleTime: 10 * 60 * 1000,
    retry: false,
  });

  return useMemo<OpeningInfo>(() => {
    const data = query.data;
    if (!data) {
      return {
        isLoading: query.isLoading,
        minDate: null,
        maxDate: null,
        closedWeekdays: new Set<number>(),
        noPickup: EMPTY,
        noReturn: EMPTY,
        closures: [],
      };
    }
    const closedWeekdays = new Set<number>();
    for (const entry of data.weekly ?? []) {
      if (entry.closed) closedWeekdays.add(entry.weekday);
    }
    const noPickup = new Set<string>();
    const noReturn = new Set<string>();
    for (const day of data.days ?? []) {
      if (!day.can_pickup) noPickup.add(day.date);
      if (!day.can_return) noReturn.add(day.date);
    }
    return {
      isLoading: false,
      minDate: data.booking_window?.min_date ?? null,
      maxDate: data.booking_window?.max_date ?? null,
      closedWeekdays,
      noPickup,
      noReturn,
      closures: data.closures ?? [],
    };
  }, [query.data, query.isLoading]);
}
