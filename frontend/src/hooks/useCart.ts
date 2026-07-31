import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';
import type { Cart } from '@/types/api';

export const CART_KEY = ['cart'] as const;

/**
 * Cart badge: seeded from GET /auth/me (cart_items_count) at boot and then kept
 * in sync from the ['cart'] query cache — every mutation returns the full cart
 * (SPEC §11.4), so no extra requests are needed.
 */
export function useCartQuery(enabled = true) {
  const { permissions } = useAuth();
  return useQuery({
    queryKey: CART_KEY,
    queryFn: api.getCart,
    enabled: enabled && permissions['orders.create'],
    staleTime: 10_000,
  });
}

export function useCartBadge(): number {
  const { cartItemsCount, permissions } = useAuth();
  /* Subscribes to the ['cart'] cache without ever fetching on its own. */
  const { data } = useQuery<Cart>({ queryKey: CART_KEY, queryFn: api.getCart, enabled: false });
  if (!permissions['orders.create']) return 0;
  return data ? data.items_count : cartItemsCount;
}

export function useCartMutations() {
  const queryClient = useQueryClient();
  const write = (cart: Cart) => queryClient.setQueryData(CART_KEY, cart);

  const addItem = useMutation({
    mutationFn: (body: { product_id: number; quantity: number; notes?: string | null }) =>
      api.addCartItem(body),
    onSuccess: write,
  });

  const patchItem = useMutation({
    mutationFn: ({ itemId, ...body }: { itemId: number; quantity?: number; notes?: string | null }) =>
      api.patchCartItem(itemId, body),
    onSuccess: write,
  });

  const removeItem = useMutation({
    mutationFn: (itemId: number) => api.deleteCartItem(itemId),
    onSuccess: write,
  });

  const swapItem = useMutation({
    mutationFn: ({ itemId, productId }: { itemId: number; productId: number }) =>
      api.swapCartItem(itemId, productId),
    onSuccess: write,
  });

  const setDates = useMutation({
    mutationFn: (body: {
      pickup_date?: string | null;
      pickup_time?: string | null;
      return_date?: string | null;
      return_time?: string | null;
    }) => api.putCartDates(body),
    onSuccess: write,
  });

  const empty = useMutation({
    mutationFn: () => api.clearCart(),
    onSuccess: write,
  });

  return { addItem, patchItem, removeItem, swapItem, setDates, empty };
}
