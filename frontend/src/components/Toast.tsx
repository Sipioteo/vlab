import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { ApiError } from '@/api/client';
import { t } from '@/i18n/it';
import { Icon } from './Icon';

export type ToastLevel = 'info' | 'success' | 'error';

interface ToastItem {
  id: number;
  message: string;
  level: ToastLevel;
}

interface ToastContextValue {
  push: (message: string, level?: ToastLevel) => void;
  pushError: (error: unknown) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const nextId = useRef(1);

  const remove = useCallback((id: number) => {
    setItems((prev) => prev.filter((item) => item.id !== id));
  }, []);

  const push = useCallback(
    (message: string, level: ToastLevel = 'info') => {
      const id = nextId.current++;
      setItems((prev) => [...prev, { id, message, level }]);
      setTimeout(() => remove(id), 6000);
    },
    [remove],
  );

  const pushError = useCallback(
    (error: unknown) => {
      const message =
        error instanceof ApiError
          ? error.message
          : error instanceof Error && error.message
            ? error.message
            : t('app.unknownError');
      push(message, 'error');
    },
    [push],
  );

  const value = useMemo(() => ({ push, pushError }), [push, pushError]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="vl-toaster" role="region" aria-label={t('a11y.statusRegion')}>
        <div aria-live="polite" aria-atomic="false">
          {items.map((item) => (
            <div key={item.id} className={`vl-toast vl-toast--${item.level}`}>
              <span style={{ flex: 1 }}>{item.message}</span>
              <button
                type="button"
                className="vl-toast__close"
                onClick={() => remove(item.id)}
                aria-label={t('app.close')}
              >
                <Icon name="close" size={16} />
              </button>
            </div>
          ))}
        </div>
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>');
  return ctx;
}
