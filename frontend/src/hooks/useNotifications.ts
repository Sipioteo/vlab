import { useCallback, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';

const SEEN_KEY = 'vlab.seen_approved_orders';

function readSeen(): number[] {
  try {
    const raw = localStorage.getItem(SEEN_KEY);
    if (!raw) return [];
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed.filter((x): x is number => typeof x === 'number') : [];
  } catch {
    return [];
  }
}

function writeSeen(ids: number[]): void {
  try {
    localStorage.setItem(SEEN_KEY, JSON.stringify(ids.slice(-100)));
  } catch {
    /* ignore */
  }
}

/**
 * Newly-approved-order notifications: on-load diff against the ids already
 * seen, refreshed every 2 minutes. Deliberately simple — no websockets.
 */
export function useApprovedNotifications() {
  const { isAuthenticated, permissions } = useAuth();
  const enabled = isAuthenticated && permissions['orders.create'];

  const { data } = useQuery({
    queryKey: ['orders', 'approved-notifications'],
    queryFn: () => api.getOrders({ status: 'approved', scope: 'mine', per_page: 20 }),
    enabled,
    refetchInterval: 120_000,
    staleTime: 60_000,
  });

  const ids = useMemo(() => (data?.data ?? []).map((order) => order.id), [data]);
  const seen = readSeen();
  const fresh = ids.filter((id) => !seen.includes(id));

  const markSeen = useCallback(() => {
    writeSeen([...new Set([...readSeen(), ...ids])]);
  }, [ids]);

  return { count: fresh.length, orderIds: fresh, markSeen };
}
