import type { ReactElement, ReactNode } from 'react';
import { render, type RenderResult } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider } from '@/auth/AuthProvider';
import { SettingsProvider } from '@/settings/SettingsProvider';
import { ToastProvider } from '@/components/Toast';
import { setRefreshToken } from '@/api/client';
import { mockState } from './handlers';
import type { Role } from '@/types/api';

export function makeTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0, staleTime: 0, refetchOnWindowFocus: false },
      mutations: { retry: false },
    },
  });
}

export interface RenderOptions {
  /** null = anonymous visitor */
  role?: Role | null;
  route?: string;
  /** additional routes so navigation assertions have a target */
  extraRoutes?: ReactNode;
  path?: string;
}

/**
 * Renders a subtree inside the real provider stack.
 * Passing a role seeds a refresh token so <AuthProvider> boots an authenticated
 * session through the msw-mocked /auth/refresh + /auth/me pair.
 */
export function renderWithProviders(
  ui: ReactElement,
  { role = null, route = '/', extraRoutes, path }: RenderOptions = {},
): RenderResult & { queryClient: QueryClient } {
  mockState.role = role;
  if (role) setRefreshToken(`refresh-token-${role}`);

  const queryClient = makeTestQueryClient();

  const result = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]} future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
        <ToastProvider>
          <AuthProvider>
            <SettingsProvider>
              {path || extraRoutes ? (
                <Routes>
                  <Route path={path ?? '*'} element={ui} />
                  {extraRoutes}
                </Routes>
              ) : (
                ui
              )}
            </SettingsProvider>
          </AuthProvider>
        </ToastProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return { ...result, queryClient };
}
