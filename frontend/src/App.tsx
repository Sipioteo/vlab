import type { ReactNode } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { useAuth } from '@/auth/AuthProvider';
import { RequireAuth, RequireRole } from '@/auth/guards';
import { AppShell } from '@/components/AppShell';
import { StaffLayout } from '@/components/StaffLayout';
import { Splash } from '@/components/Splash';

import { HomePage } from '@/pages/HomePage';
import { LoginPage } from '@/pages/LoginPage';
import { CatalogPage } from '@/pages/CatalogPage';
import { ProductDetailPage } from '@/pages/ProductDetailPage';
import { AvailabilityFinderPage } from '@/pages/AvailabilityFinderPage';
import { CartPage } from '@/pages/CartPage';
import { CheckoutPage } from '@/pages/CheckoutPage';
import { MyOrdersPage } from '@/pages/MyOrdersPage';
import { OrderDetailPage } from '@/pages/OrderDetailPage';
import { RegulationsPage } from '@/pages/RegulationsPage';
import { RegulationDetailPage } from '@/pages/RegulationDetailPage';
import { AcceptRegulationsPage } from '@/pages/AcceptRegulationsPage';
import { ProfilePage } from '@/pages/ProfilePage';
import { ForbiddenPage, NotFoundPage } from '@/pages/ErrorPages';

import { StaffDashboardPage } from '@/pages/staff/StaffDashboardPage';
import { StaffOrdersPage } from '@/pages/staff/StaffOrdersPage';
import { StaffOrderDetailPage } from '@/pages/staff/StaffOrderDetailPage';
import { StaffCalendarPage } from '@/pages/staff/StaffCalendarPage';
import { AdminProductsPage } from '@/pages/staff/AdminProductsPage';
import { ProductFormPage } from '@/pages/staff/ProductFormPage';
import { AdminCategoriesPage } from '@/pages/staff/AdminCategoriesPage';
import { ProductLogsPage } from '@/pages/staff/ProductLogsPage';
import { AdminRegulationsPage } from '@/pages/staff/AdminRegulationsPage';
import { AdminClosuresPage } from '@/pages/staff/AdminClosuresPage';
import { StatsPage } from '@/pages/staff/StatsPage';
import { AdminUsersPage, UserDetailPage } from '@/pages/staff/AdminUsersPage';
import { SettingsPage } from '@/pages/staff/SettingsPage';
import { AuditLogPage } from '@/pages/staff/AuditLogPage';

/**
 * Blocking global regulations replace every route until accepted
 * (SPEC §5.5 / §11.4), except the login and the regulation pages themselves.
 */
function RegulationGate({ children }: { children: ReactNode }) {
  const { pendingRegulations, isAuthenticated } = useAuth();
  const location = useLocation();
  const blocking = isAuthenticated && pendingRegulations.some((reg) => reg.blocking);
  const exempt =
    location.pathname.startsWith('/regolamento') || location.pathname.startsWith('/login');
  if (blocking && !exempt) return <AcceptRegulationsPage />;
  return <>{children}</>;
}

function Staff({ children }: { children: ReactNode }) {
  return <StaffLayout>{children}</StaffLayout>;
}

export function App() {
  const { isLoading } = useAuth();
  if (isLoading) {
    return (
      <AppShell>
        <Splash />
      </AppShell>
    );
  }

  return (
    <AppShell>
      <RegulationGate>
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/login" element={<LoginPage />} />
          <Route path="/catalogo" element={<CatalogPage />} />
          <Route path="/catalogo/:categorySlug" element={<CatalogPage />} />
          <Route path="/prodotto/:slug" element={<ProductDetailPage />} />
          <Route path="/disponibilita" element={<AvailabilityFinderPage />} />
          <Route path="/regolamento" element={<RegulationsPage />} />
          <Route
            path="/regolamento/accetta"
            element={
              <RequireAuth>
                <AcceptRegulationsPage />
              </RequireAuth>
            }
          />
          <Route path="/regolamento/:slug" element={<RegulationDetailPage />} />

          <Route
            path="/carrello"
            element={
              <RequireRole anyOf={['orders.create']}>
                <CartPage />
              </RequireRole>
            }
          />
          <Route
            path="/carrello/checkout"
            element={
              <RequireRole anyOf={['orders.create']}>
                <CheckoutPage />
              </RequireRole>
            }
          />
          <Route
            path="/ordini"
            element={
              <RequireAuth>
                <MyOrdersPage />
              </RequireAuth>
            }
          />
          <Route
            path="/ordini/:id"
            element={
              <RequireAuth>
                <OrderDetailPage />
              </RequireAuth>
            }
          />
          <Route
            path="/profilo"
            element={
              <RequireAuth>
                <ProfilePage />
              </RequireAuth>
            }
          />

          {/* ---------------------------------------------------------- staff */}
          <Route
            path="/gestione"
            element={
              <RequireRole anyOf={['orders.manage']}>
                <Staff>
                  <StaffDashboardPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/ordini"
            element={
              <RequireRole anyOf={['orders.manage']}>
                <Staff>
                  <StaffOrdersPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/ordini/:id"
            element={
              <RequireRole anyOf={['orders.manage']}>
                <Staff>
                  <StaffOrderDetailPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/calendario"
            element={
              <RequireRole anyOf={['orders.manage']}>
                <Staff>
                  <StaffCalendarPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/prodotti"
            element={
              <RequireRole anyOf={['products.manage']}>
                <Staff>
                  <AdminProductsPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/prodotti/nuovo"
            element={
              <RequireRole anyOf={['products.manage']}>
                <Staff>
                  <ProductFormPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/prodotti/:id"
            element={
              <RequireRole anyOf={['products.manage']}>
                <Staff>
                  <ProductFormPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/categorie"
            element={
              <RequireRole anyOf={['products.manage']}>
                <Staff>
                  <AdminCategoriesPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/registro"
            element={
              <RequireRole anyOf={['logs.create']}>
                <Staff>
                  <ProductLogsPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/regolamenti"
            element={
              <RequireRole anyOf={['regulations.manage']}>
                <Staff>
                  <AdminRegulationsPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/chiusure"
            element={
              <RequireRole anyOf={['closures.manage']}>
                <Staff>
                  <AdminClosuresPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/statistiche"
            element={
              <RequireRole anyOf={['stats.view_limited', 'stats.view_full']}>
                <Staff>
                  <StatsPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/utenti"
            element={
              <RequireRole anyOf={['users.view']}>
                <Staff>
                  <AdminUsersPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/utenti/:id"
            element={
              <RequireRole anyOf={['users.view']}>
                <Staff>
                  <UserDetailPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/impostazioni"
            element={
              <RequireRole anyOf={['settings.view']}>
                <Staff>
                  <SettingsPage />
                </Staff>
              </RequireRole>
            }
          />
          <Route
            path="/gestione/audit"
            element={
              <RequireRole anyOf={['audit.view']}>
                <Staff>
                  <AuditLogPage />
                </Staff>
              </RequireRole>
            }
          />

          <Route path="/403" element={<ForbiddenPage />} />
          <Route path="/404" element={<NotFoundPage />} />
          <Route path="/index.html" element={<Navigate to="/" replace />} />
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </RegulationGate>
    </AppShell>
  );
}
