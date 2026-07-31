import { createContext, useContext, useEffect, useMemo, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import type { PublicSettings } from '@/types/api';

interface SettingsContextValue {
  settings: PublicSettings;
  isLoading: boolean;
  get: <T>(key: string, fallback: T) => T;
}

const SettingsContext = createContext<SettingsContextValue | null>(null);

/** Sensible defaults so the SPA renders before/without the backend. */
export const SETTINGS_FALLBACK: PublicSettings = {
  'lab.name': 'Visionary Lab',
  'lab.subtitle': 'Politecnico di Torino — Prestito attrezzature',
  'ui.primary_color': '#00284B',
  'ui.accent_color': '#EF7B02',
  'ui.highlight_color': '#00C2CB',
  'ui.items_per_page': 24,
  'ui.catalog_default_view': 'grid',
  'ui.allow_anonymous_catalog': true,
  'ui.banner_enabled': false,
  'booking.max_loan_days': 7,
  'booking.min_advance_days': 1,
  'booking.max_advance_days': 90,
  'booking.motivation_min_length': 20,
  'booking.require_motivation': true,
  'booking.require_professor': false,
  'booking.require_subject': true,
  'booking.max_quantity_per_product_per_order': 2,
};

export function SettingsProvider({ children }: { children: ReactNode }) {
  const { data, isLoading } = useQuery({
    queryKey: ['settings', 'public'],
    queryFn: api.getPublicSettings,
    staleTime: 5 * 60 * 1000,
    retry: false,
  });

  const settings = useMemo<PublicSettings>(
    () => ({ ...SETTINGS_FALLBACK, ...(data ?? {}) }),
    [data],
  );

  /* Admin-set branding actually applies (SPEC §11.4). */
  useEffect(() => {
    const root = document.documentElement;
    const map: Record<string, string> = {
      'ui.primary_color': '--color-primary',
      'ui.accent_color': '--color-accent',
      'ui.highlight_color': '--color-highlight',
    };
    for (const [key, cssVar] of Object.entries(map)) {
      const value = settings[key];
      if (typeof value === 'string' && /^#[0-9a-f]{3,8}$/i.test(value)) {
        root.style.setProperty(cssVar, value);
      }
    }
  }, [settings]);

  const value = useMemo<SettingsContextValue>(
    () => ({
      settings,
      isLoading,
      get: <T,>(key: string, fallback: T): T => {
        const v = settings[key];
        return (v === undefined ? fallback : (v as T));
      },
    }),
    [settings, isLoading],
  );

  return <SettingsContext.Provider value={value}>{children}</SettingsContext.Provider>;
}

export function useSettings(): SettingsContextValue {
  const ctx = useContext(SettingsContext);
  if (!ctx) throw new Error('useSettings must be used inside <SettingsProvider>');
  return ctx;
}

export function useSetting<T>(key: string, fallback: T): T {
  return useSettings().get<T>(key, fallback);
}
