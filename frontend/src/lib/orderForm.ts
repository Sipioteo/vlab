import { API_PREFIX, getAccessToken } from '@/api/client';
import type { OrderStatus } from '@/types/api';

/**
 * Stati dai quali il modulo di prestito è stampabile: la richiesta è stata
 * confermata almeno una volta dal laboratorio.
 * Deve restare allineato a OrderPdfService::PRINTABLE_STATUSES (backend).
 */
export const PRINTABLE_ORDER_STATUSES: readonly OrderStatus[] = [
  'approved',
  'picked_up',
  'overdue',
  'returned',
  'returned_late',
  'no_show',
];

export function canPrintOrderForm(status: OrderStatus): boolean {
  return PRINTABLE_ORDER_STATUSES.includes(status);
}

/**
 * URL del modulo PDF. Il token viaggia in query string perché il PDF si apre
 * in una nuova scheda, dove non passiamo l'header Authorization — stessa
 * convenzione dei PDF dei regolamenti.
 */
export function orderFormUrl(orderId: number): string {
  const token = getAccessToken();
  return `${API_PREFIX}/orders/${orderId}/pdf${token ? `?token=${encodeURIComponent(token)}` : ''}`;
}
