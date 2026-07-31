import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import type { EnumEntry, MetaEnums } from '@/types/api';

/**
 * Italian labels come from GET /meta/enums — the SPA never hardcodes them
 * (SPEC Appendix A). The response is cached for the whole session.
 */
export function useEnums() {
  const { data } = useQuery({
    queryKey: ['meta', 'enums'],
    queryFn: api.getEnums,
    staleTime: Infinity,
    retry: false,
  });

  const enums: MetaEnums = data ?? {};

  function list(name: string): EnumEntry[] {
    return enums[name] ?? [];
  }

  /** Label lookup with a graceful fallback to the raw value. */
  function label(name: string, value: string | null | undefined): string {
    if (!value) return '—';
    return list(name).find((entry) => entry.value === value)?.label ?? value;
  }

  return { enums, list, label, isReady: Boolean(data) };
}
