import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { mockState } from '@/test/handlers';
import { renderWithProviders } from '@/test/utils';
import { App } from '@/App';
import { pendingGlobalRegulation, makeMe } from '@/test/fixtures';
import type { Role } from '@/types/api';

function renderApp(route: string, role: Role | null = null) {
  return renderWithProviders(<App />, { route, role });
}

describe('authentication flow', () => {
  it('logs in, stores the refresh token and navigates to ?next', async () => {
    const user = userEvent.setup();
    renderApp('/login?next=/ordini');

    await screen.findByRole('heading', { name: 'Accedi', level: 1 });

    await user.type(screen.getByLabelText('Nome utente'), 'student1');
    await user.type(screen.getByLabelText('Password'), 'password');
    await user.click(screen.getByRole('button', { name: 'Accedi' }));

    await waitFor(() => {
      expect(localStorage.getItem('vlab.refresh_token')).toBe('refresh-token-student');
    });
    expect(await screen.findByRole('heading', { name: 'Le mie richieste', level: 1 })).toBeVisible();
    expect(mockState.loginCalls).toBe(1);
  });

  it('renders the Italian error message returned for invalid credentials', async () => {
    const user = userEvent.setup();
    renderApp('/login');

    await screen.findByRole('heading', { name: 'Accedi', level: 1 });
    await user.type(screen.getByLabelText('Nome utente'), 'student1');
    await user.type(screen.getByLabelText('Password'), 'sbagliata');
    await user.click(screen.getByRole('button', { name: 'Accedi' }));

    expect(await screen.findByText('Credenziali non valide.')).toBeVisible();
  });

  it('shows the LDAP dev hint only in fake mode', async () => {
    renderApp('/login');
    expect(await screen.findByText(/Modalità di sviluppo/)).toBeVisible();
  });

  it('boots from a stored refresh token before rendering routes', async () => {
    renderApp('/profilo', 'student');
    expect(await screen.findByRole('heading', { name: 'Il tuo profilo', level: 1 })).toBeVisible();
    expect(mockState.refreshCalls).toBe(1);
  });
});

describe('route guards', () => {
  it('redirects an anonymous visitor from /carrello to the login page', async () => {
    renderApp('/carrello');
    expect(await screen.findByRole('heading', { name: 'Accedi', level: 1 })).toBeVisible();
  });

  it('renders the 403 page for a student hitting /gestione', async () => {
    renderApp('/gestione', 'student');
    expect(await screen.findByRole('heading', { name: 'Accesso non consentito' })).toBeVisible();
  });

  it('lets a technician into the staff dashboard', async () => {
    renderApp('/gestione', 'technician');
    expect(await screen.findByRole('heading', { name: 'Cruscotto operativo', level: 1 })).toBeVisible();
  });
});

describe('permission-driven UI (borsista vs tecnico)', () => {
  it('hides the product management entries from an assistant but shows the orders queue', async () => {
    renderApp('/gestione', 'assistant');
    await screen.findByRole('heading', { name: 'Cruscotto operativo', level: 1 });

    const rail = screen.getAllByRole('navigation', { name: 'Area gestione' })[0]!;
    expect(rail).toHaveTextContent('Richieste');
    expect(rail).not.toHaveTextContent('Attrezzature');
    expect(rail).not.toHaveTextContent('Categorie');
    expect(rail).not.toHaveTextContent('Regolamenti');
  });

  it('shows the product management entries to a technician', async () => {
    renderApp('/gestione', 'technician');
    await screen.findByRole('heading', { name: 'Cruscotto operativo', level: 1 });

    const rail = screen.getAllByRole('navigation', { name: 'Area gestione' })[0]!;
    expect(rail).toHaveTextContent('Attrezzature');
    expect(rail).toHaveTextContent('Categorie');
  });

  it('blocks an assistant from the product management route with 403', async () => {
    renderApp('/gestione/prodotti', 'assistant');
    expect(await screen.findByRole('heading', { name: 'Accesso non consentito' })).toBeVisible();
  });

  it('lets a technician reach the product management route', async () => {
    renderApp('/gestione/prodotti', 'technician');
    expect(await screen.findByRole('heading', { name: 'Gestione attrezzature', level: 1 })).toBeVisible();
  });
});

describe('regulation gate', () => {
  it('puts a blocking dialog in front of the app on boot with a pending regulation', async () => {
    mockState.pendingRegulations = [pendingGlobalRegulation];

    renderApp('/catalogo', 'student');

    const dialog = await screen.findByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveTextContent('Prima di continuare');
    // The route still renders — but behind an inert, aria-hidden shell.
    const shell = document.querySelector('.vl-approot')!;
    expect(shell).toHaveAttribute('inert');
    expect(shell).toHaveAttribute('aria-hidden', 'true');
  });

  it('lets the app through once nothing is pending', async () => {
    renderApp('/catalogo', 'student');
    expect(await screen.findByRole('heading', { name: 'Catalogo attrezzature', level: 1 })).toBeVisible();
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('redirects the legacy /regolamento/accetta interstitial to the index', async () => {
    renderApp('/regolamento/accetta', 'student');
    expect(await screen.findByRole('heading', { name: 'Regolamento', level: 1 })).toBeVisible();
  });

  it('still honours a login response that omits the blocking flag', async () => {
    // Defence in depth: a global scope is blocking whether or not the backend
    // says so — this is exactly the field whose absence broke the whole flow.
    const { blocking: _blocking, ...withoutFlag } = pendingGlobalRegulation;
    server.use(
      http.get('/api/v1/auth/me', () =>
        HttpResponse.json(makeMe('student', { pending_regulations: [withoutFlag] })),
      ),
      http.get('/api/v1/me/regulations/pending', () =>
        HttpResponse.json({ data: [withoutFlag], meta: null }),
      ),
    );

    renderApp('/catalogo', 'student');
    expect(await screen.findByRole('dialog')).toBeVisible();
  });
});
