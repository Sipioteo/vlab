import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router';
import { useAuth } from './AuthProvider';
import type { PermissionKey } from '@/types/api';
import { Splash } from '@/components/Splash';

/** Redirects anonymous visitors to /login?next=<path> (SPEC §11.3). */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth();
  const location = useLocation();
  if (isLoading) return <Splash />;
  if (!isAuthenticated) {
    const next = `${location.pathname}${location.search}`;
    return <Navigate to={`/login?next=${encodeURIComponent(next)}`} replace />;
  }
  return <>{children}</>;
}

/**
 * Gates on the `permissions` object only — never on role strings (SPEC §11.4).
 * `anyOf`: the route is allowed when at least one permission is true.
 */
export function RequireRole({
  anyOf,
  children,
}: {
  anyOf: PermissionKey[];
  children: ReactNode;
}) {
  const { isAuthenticated, isLoading, permissions } = useAuth();
  const location = useLocation();
  if (isLoading) return <Splash />;
  if (!isAuthenticated) {
    const next = `${location.pathname}${location.search}`;
    return <Navigate to={`/login?next=${encodeURIComponent(next)}`} replace />;
  }
  const allowed = anyOf.some((key) => permissions[key] === true);
  if (!allowed) return <Navigate to="/403" replace />;
  return <>{children}</>;
}
