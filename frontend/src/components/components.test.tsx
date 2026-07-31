import { useState } from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/utils';
import { App } from '@/App';
import { Modal, Pagination, ProductImage } from './ui';
import {
  AvailabilityBadge,
  DateRangePicker,
  MarkdownView,
  RowAvailability,
  StatusBadge,
} from './domain';
import { metaEnums } from '@/test/fixtures';
import type { OrderStatus, Role } from '@/types/api';

describe('StatusBadge', () => {
  it.each(metaEnums['order_status']!.map((entry) => [entry.value, entry.label]))(
    'maps %s to its Italian label "%s"',
    async (value, label) => {
      const { unmount } = renderWithProviders(<StatusBadge status={value as OrderStatus} />);
      expect(await screen.findByText(label)).toBeVisible();
      unmount();
    },
  );
});

describe('Pagination', () => {
  it('disables prev on the first page and next on the last', async () => {
    const onChange = vi.fn();
    const { rerender } = render(<Pagination page={1} totalPages={3} onChange={onChange} />);
    expect(screen.getByRole('button', { name: /Pagina precedente/ })).toBeDisabled();
    expect(screen.getByRole('button', { name: /Pagina successiva/ })).toBeEnabled();

    rerender(<Pagination page={3} totalPages={3} onChange={onChange} />);
    expect(screen.getByRole('button', { name: /Pagina precedente/ })).toBeEnabled();
    expect(screen.getByRole('button', { name: /Pagina successiva/ })).toBeDisabled();
  });

  it('renders nothing when there is a single page', () => {
    const { container } = render(<Pagination page={1} totalPages={1} onChange={() => {}} />);
    expect(container).toBeEmptyDOMElement();
  });
});

describe('Modal', () => {
  it('traps focus, closes on Esc and restores focus to the trigger', async () => {
    const user = userEvent.setup();

    function Host() {
      const [open, setOpen] = useState(false);
      return (
        <>
          <button type="button" onClick={() => setOpen(true)}>
            Apri
          </button>
          <Modal open={open} onClose={() => setOpen(false)} title="Finestra">
            <button type="button">Primo</button>
            <button type="button">Ultimo</button>
          </Modal>
        </>
      );
    }

    render(<Host />);
    const trigger = screen.getByRole('button', { name: 'Apri' });
    await user.click(trigger);

    const dialog = await screen.findByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');

    // focus starts inside the dialog
    await waitFor(() => expect(dialog.contains(document.activeElement)).toBe(true));

    // Tab cycles inside the dialog only
    const inside = within(dialog).getAllByRole('button');
    inside[inside.length - 1]!.focus();
    await user.tab();
    expect(dialog.contains(document.activeElement)).toBe(true);

    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(document.activeElement).toBe(trigger);
  });
});

describe('AvailabilityBadge', () => {
  it('renders the available count and the unavailable copy', () => {
    const { rerender } = render(<AvailabilityBadge available={3} />);
    expect(screen.getByText('3 disponibili')).toBeVisible();

    rerender(<AvailabilityBadge available={0} />);
    expect(screen.getByText('Non disponibile in queste date')).toBeVisible();
  });
});

describe('DateRangePicker', () => {
  const BASE = {
    minDate: '2026-08-01',
    maxDate: '2026-10-30',
    respectClosures: false as const,
  };

  function openPicker(props: Partial<Parameters<typeof DateRangePicker>[0]> = {}) {
    const onChange = vi.fn();
    renderWithProviders(
      <DateRangePicker pickupDate={null} returnDate={null} onChange={onChange} {...BASE} {...props} />,
    );
    return onChange;
  }

  it('shows the placeholder and opens a two-month popover with Italian labels', async () => {
    const user = userEvent.setup();
    openPicker();

    const trigger = screen.getByRole('button', { name: /Periodo di prestito: Seleziona le date/ });
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await user.click(trigger);

    const dialog = await screen.findByRole('dialog', {
      name: 'Calendario: scegli ritiro e riconsegna',
    });
    expect(within(dialog).getByText('agosto 2026')).toBeVisible();
    expect(within(dialog).getByText('settembre 2026')).toBeVisible();
    expect(within(dialog).getAllByText('lun').length).toBe(2);
    expect(within(dialog).getByText('Quando ritiri?')).toBeVisible();
  });

  it('selects a range with two clicks and reports it once, in ISO', async () => {
    const user = userEvent.setup();
    const onChange = openPicker();

    await user.click(screen.getByRole('button', { name: /Seleziona le date/ }));
    await user.click(await screen.findByRole('gridcell', { name: /lunedì 3 agosto 2026/ }));

    /* start only: nothing committed yet, and the panel now asks for the return */
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByText('Quando riconsegni?')).toBeVisible();

    await user.click(screen.getByRole('gridcell', { name: /giovedì 6 agosto 2026/ }));

    expect(onChange).toHaveBeenCalledTimes(1);
    expect(onChange).toHaveBeenCalledWith({ pickup_date: '2026-08-03', return_date: '2026-08-06' });
  });

  it('never selects a disabled day and marks it as such', async () => {
    const user = userEvent.setup();
    const onChange = openPicker({ disabledPickup: new Set(['2026-08-05']) });

    await user.click(screen.getByRole('button', { name: /Seleziona le date/ }));

    const closed = await screen.findByRole('gridcell', {
      name: /mercoledì 5 agosto 2026, laboratorio chiuso/,
    });
    expect(closed).toHaveAttribute('aria-disabled', 'true');
    expect(closed.className).toContain('vl-drp__day--closed');

    await user.click(closed);
    expect(onChange).not.toHaveBeenCalled();

    /* days outside [minDate, maxDate] are refused too */
    const outside = screen.getByRole('gridcell', { name: /sabato 1 agosto 2026/ });
    expect(outside).not.toHaveAttribute('aria-disabled');
  });

  it('greys out the weekdays the lab is closed on', async () => {
    const user = userEvent.setup();
    /* the mocked /calendar/opening marks Sunday (weekday 0) as closed */
    openPicker({ respectClosures: true });

    await user.click(screen.getByRole('button', { name: /Seleziona le date/ }));
    const sunday = await screen.findByRole('gridcell', {
      name: /domenica 2 agosto 2026, laboratorio chiuso/,
    });
    await waitFor(() => expect(sunday).toHaveAttribute('aria-disabled', 'true'));
    expect(screen.getByRole('gridcell', { name: /lunedì 3 agosto 2026/ })).not.toHaveAttribute(
      'aria-disabled',
    );
  });

  it('is fully operable from the keyboard', async () => {
    const user = userEvent.setup();
    const onChange = openPicker();

    await user.click(screen.getByRole('button', { name: /Seleziona le date/ }));
    /* focus lands on the first selectable day */
    await waitFor(() =>
      expect(document.activeElement).toHaveAttribute('data-date', '2026-08-01'),
    );

    await user.keyboard('{ArrowRight}{ArrowRight}');
    expect(document.activeElement).toHaveAttribute('data-date', '2026-08-03');
    await user.keyboard('{Enter}');

    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toHaveAttribute('data-date', '2026-08-10');
    await user.keyboard('{Enter}');

    expect(onChange).toHaveBeenCalledWith({ pickup_date: '2026-08-03', return_date: '2026-08-10' });
  });

  it('closes on Esc and gives focus back to the trigger', async () => {
    const user = userEvent.setup();
    openPicker();

    const trigger = screen.getByRole('button', { name: /Seleziona le date/ });
    await user.click(trigger);
    expect(await screen.findByRole('dialog')).toBeVisible();

    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(document.activeElement).toBe(trigger);
  });

  it('summarises the selected range on the trigger and clears it', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    renderWithProviders(
      <DateRangePicker
        pickupDate="2026-09-12"
        returnDate="2026-09-15"
        onChange={onChange}
        {...BASE}
        maxDate="2026-12-30"
      />,
    );

    expect(screen.getByRole('button', { name: /12 set → 15 set/ })).toBeVisible();

    await user.click(screen.getByRole('button', { name: /12 set → 15 set/ }));
    await user.click(await screen.findByRole('button', { name: 'Cancella' }));

    expect(onChange).toHaveBeenCalledWith({ pickup_date: null, return_date: null });
    expect(screen.getByRole('button', { name: 'Fatto' })).toBeVisible();
  });
});

describe('RowAvailability', () => {
  it('renders the three live states: green / amber / red', () => {
    const { rerender } = render(
      <RowAvailability entry={{ product_id: 1, requested: 1, available: 3, sufficient: true }} />,
    );
    expect(screen.getByTestId('row-availability-ok')).toHaveTextContent('Disponibile');

    rerender(
      <RowAvailability entry={{ product_id: 1, requested: 3, available: 1, sufficient: false }} />,
    );
    expect(screen.getByTestId('row-availability-partial')).toHaveTextContent(
      'Solo 1 disponibili su 3 richiesti',
    );

    rerender(
      <RowAvailability entry={{ product_id: 1, requested: 1, available: 0, sufficient: false }} />,
    );
    expect(screen.getByTestId('row-availability-ko')).toHaveTextContent(
      'Non disponibile in queste date',
    );
  });

  it('offers row-level substitutes through the onSwap callback', async () => {
    const user = userEvent.setup();
    const onSwap = vi.fn();
    render(
      <RowAvailability
        entry={{
          product_id: 1,
          requested: 1,
          available: 0,
          sufficient: false,
          suggested_substitutes: [
            { product_id: 9, name: 'Alternativa X', slug: 'alt-x', image_url: null, available_quantity: 2, priority: 1 },
          ],
        }}
        onSwap={onSwap}
      />,
    );
    await user.click(screen.getByRole('button', { name: /Alternativa X/ }));
    expect(onSwap).toHaveBeenCalledWith(
      expect.objectContaining({ product_id: 9, name: 'Alternativa X' }),
    );
  });
});

describe('MarkdownView', () => {
  it('renders headings, lists and emphasis without interpreting HTML', () => {
    render(
      <MarkdownView source={'# Titolo\n\nTesto **grassetto**.\n\n- uno\n- due\n\n<script>alert(1)</script>'} />,
    );
    expect(screen.getByRole('heading', { name: 'Titolo' })).toBeVisible();
    expect(screen.getByText('grassetto')).toBeVisible();
    expect(screen.getAllByRole('listitem').length).toBe(2);
    expect(document.querySelector('script')).toBeNull();
    expect(screen.getByText(/<script>alert\(1\)<\/script>/)).toBeVisible();
  });
});

describe('ProductImage', () => {
  it('falls back to a placeholder when the external image fails to load', async () => {
    render(<ProductImage src="https://example.invalid/x.jpg" alt="Foto" />);
    const img = screen.getByAltText('Foto');
    img.dispatchEvent(new Event('error'));
    expect(await screen.findByText('Immagine non disponibile')).toBeVisible();
  });

  it('renders the placeholder immediately when no url is provided', () => {
    render(<ProductImage src={null} alt="Foto" />);
    expect(screen.getByText('Immagine non disponibile')).toBeVisible();
  });
});

describe('page structure', () => {
  const ROUTES: { route: string; role: Role | null; heading: RegExp }[] = [
    { route: '/', role: null, heading: /Attrezzature per il cinema/ },
    { route: '/catalogo', role: null, heading: /Catalogo attrezzature/ },
    { route: '/prodotto/visore-vr-meta-quest-3', role: null, heading: /Visore VR Meta Quest 3/ },
    { route: '/disponibilita', role: null, heading: /Verifica disponibilità/ },
    { route: '/regolamento', role: null, heading: /Regolamento/ },
    { route: '/login', role: null, heading: /Accedi/ },
    { route: '/carrello', role: 'student', heading: /Carrello/ },
    { route: '/ordini', role: 'student', heading: /Le mie richieste/ },
    { route: '/profilo', role: 'student', heading: /Il tuo profilo/ },
    { route: '/gestione', role: 'technician', heading: /Cruscotto operativo/ },
    { route: '/gestione/ordini', role: 'technician', heading: /Coda delle richieste/ },
    { route: '/gestione/prodotti', role: 'technician', heading: /Gestione attrezzature/ },
    { route: '/gestione/categorie', role: 'technician', heading: /Categorie/ },
    { route: '/gestione/registro', role: 'technician', heading: /Registro attrezzature/ },
    { route: '/gestione/regolamenti', role: 'technician', heading: /Regolamenti/ },
    { route: '/gestione/chiusure', role: 'technician', heading: /Chiusure e ferie/ },
    { route: '/gestione/statistiche', role: 'technician', heading: /Statistiche/ },
    { route: '/gestione/utenti', role: 'technician', heading: /Utenti/ },
    { route: '/gestione/impostazioni', role: 'admin', heading: /Impostazioni/ },
    { route: '/gestione/audit', role: 'admin', heading: /Registro attività/ },
    { route: '/rotta-inesistente', role: null, heading: /Pagina non trovata/ },
  ];

  it.each(ROUTES)('renders exactly one <h1> on $route', async ({ route, role, heading }) => {
    const view = renderWithProviders(<App />, { route, role });
    await screen.findByRole('heading', { level: 1, name: heading });
    await waitFor(() => {
      const headings = screen.getAllByRole('heading', { level: 1 });
      expect(headings).toHaveLength(1);
      expect(headings[0]).toHaveTextContent(heading);
      expect(headings[0]).toBeVisible();
    });
    view.unmount();
  });
});
