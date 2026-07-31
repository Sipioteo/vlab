import { useEffect, useRef, useState } from 'react';
import * as api from '@/api/endpoints';
import type { AvailabilityCheckResponse, AvailabilityEntry } from '@/types/api';

export interface LiveCheckItem {
  product_id: number;
  quantity: number;
}

/**
 * Live availability pre-flight (owner request A + C): whenever the dates are
 * set and the item list changes, POST /availability/check — debounced 400 ms,
 * stale responses discarded — so the operator sees per-row availability BEFORE
 * submitting, not as a rejection afterwards.
 */
export function useLiveCheck({
  items,
  pickupDate,
  returnDate,
  excludeOrderId = null,
  enabled = true,
}: {
  items: LiveCheckItem[];
  pickupDate: string | null;
  returnDate: string | null;
  excludeOrderId?: number | null;
  enabled?: boolean;
}): { check: AvailabilityCheckResponse | null; checking: boolean; entryFor: (productId: number) => AvailabilityEntry | null } {
  const [check, setCheck] = useState<AvailabilityCheckResponse | null>(null);
  const [checking, setChecking] = useState(false);
  const seqRef = useRef(0);

  const active =
    enabled && items.length > 0 && pickupDate !== null && returnDate !== null && returnDate >= pickupDate;
  /* Value-stable key so effect only re-runs on real payload changes. */
  const payloadKey = JSON.stringify({
    items: items.map(({ product_id, quantity }) => ({ product_id, quantity })),
    pickupDate,
    returnDate,
    excludeOrderId,
  });

  useEffect(() => {
    if (!active) {
      seqRef.current += 1; // cancel any in-flight response
      setCheck(null);
      setChecking(false);
      return;
    }
    const seq = ++seqRef.current;
    setChecking(true);
    const payload = JSON.parse(payloadKey) as {
      items: LiveCheckItem[];
      pickupDate: string;
      returnDate: string;
      excludeOrderId: number | null;
    };
    const timer = setTimeout(() => {
      api
        .postAvailabilityCheck({
          items: payload.items,
          pickup_date: payload.pickupDate,
          return_date: payload.returnDate,
          exclude_order_id: payload.excludeOrderId,
        })
        .then((result) => {
          if (seqRef.current === seq) {
            setCheck(result);
            setChecking(false);
          }
        })
        .catch(() => {
          if (seqRef.current === seq) setChecking(false);
        });
    }, 400);
    return () => clearTimeout(timer);
  }, [active, payloadKey]);

  return {
    check: active ? check : null,
    checking,
    entryFor: (productId: number) =>
      (active ? check : null)?.availability.find((entry) => entry.product_id === productId) ?? null,
  };
}
