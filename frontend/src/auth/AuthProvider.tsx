import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import {
  getRefreshToken,
  refreshSession,
  registerAuthLostHandler,
  setAccessToken,
  setRefreshToken,
} from '@/api/client';
import type { MeResponse, PendingRegulation, PermissionKey, Permissions, User } from '@/types/api';

export const EMPTY_PERMISSIONS: Permissions = {
  'products.manage': false,
  'orders.manage': false,
  'orders.create': false,
  'logs.create': false,
  'settings.manage': false,
  'settings.view': false,
  'stats.view_full': false,
  'stats.view_limited': false,
  'users.manage': false,
  'users.view': false,
  'regulations.manage': false,
  'regulations.delete': false,
  'closures.manage': false,
  'orders.reopen': false,
  'orders.edit_full': false,
  'audit.view': false,
};

export interface AuthContextValue {
  user: User | null;
  permissions: Permissions;
  pendingRegulations: PendingRegulation[];
  cartItemsCount: number;
  activeOrdersCount: number;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (username: string, password: string) => Promise<User>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
  setPendingRegulations: (regs: PendingRegulation[]) => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [session, setSession] = useState<MeResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const booted = useRef(false);

  const clear = useCallback(() => {
    setAccessToken(null);
    setRefreshToken(null);
    setSession(null);
    queryClient.clear();
  }, [queryClient]);

  useEffect(() => {
    registerAuthLostHandler(() => {
      setSession(null);
    });
    return () => registerAuthLostHandler(null);
  }, []);

  /**
   * Boot: exchange a stored refresh token for a session before rendering routes.
   *
   * The `booted` ref — not an effect-cleanup flag — is what guarantees a single
   * execution. Under <StrictMode> React 18 mounts, unmounts and remounts the
   * provider: a cleanup flag would cancel the in-flight boot started by the
   * first mount while the ref makes the second mount bail out, leaving
   * `isLoading` true forever (endless splash on every reload with a stored
   * refresh token). Settling the state unconditionally is safe: post-unmount
   * updates are no-ops in React 18.
   */
  useEffect(() => {
    if (booted.current) return;
    booted.current = true;

    async function boot() {
      if (!getRefreshToken()) {
        setIsLoading(false);
        return;
      }
      try {
        await refreshSession();
        const me = await api.getMe();
        setSession(me);
      } catch {
        setAccessToken(null);
        setRefreshToken(null);
        setSession(null);
      } finally {
        setIsLoading(false);
      }
    }
    void boot();
  }, []);

  const login = useCallback(
    async (username: string, password: string) => {
      const res = await api.login(username, password);
      setAccessToken(res.access_token);
      setRefreshToken(res.refresh_token);
      const me = await api.getMe();
      setSession(me);
      return me.user;
    },
    [],
  );

  const logout = useCallback(async () => {
    try {
      await api.logout(getRefreshToken());
    } catch {
      /* logging out locally regardless */
    }
    clear();
  }, [clear]);

  const refresh = useCallback(async () => {
    const me = await api.getMe();
    setSession(me);
  }, []);

  const setPendingRegulations = useCallback((regs: PendingRegulation[]) => {
    setSession((prev) => (prev ? { ...prev, pending_regulations: regs } : prev));
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      user: session?.user ?? null,
      permissions: session?.permissions ?? EMPTY_PERMISSIONS,
      pendingRegulations: session?.pending_regulations ?? [],
      cartItemsCount: session?.cart_items_count ?? 0,
      activeOrdersCount: session?.active_orders_count ?? 0,
      isAuthenticated: Boolean(session?.user),
      isLoading,
      login,
      logout,
      refresh,
      setPendingRegulations,
    }),
    [session, isLoading, login, logout, refresh, setPendingRegulations],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}

/** The ONLY way components ask about authorization (SPEC §11.4). */
export function usePermission(key: PermissionKey): boolean {
  const { permissions } = useAuth();
  return permissions[key] === true;
}

/** Convenience: any staff-area permission at all. */
export function useIsStaff(): boolean {
  const { permissions } = useAuth();
  return (
    permissions['orders.manage'] ||
    permissions['products.manage'] ||
    permissions['settings.view'] ||
    permissions['stats.view_limited']
  );
}
