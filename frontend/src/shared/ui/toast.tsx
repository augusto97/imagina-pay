import { AnimatePresence, motion } from 'framer-motion';
import { create } from 'zustand';

/** Toasts estilo sonner: feedback discreto abajo a la derecha. */

interface Toast {
  id: number;
  message: string;
  tone: 'ok' | 'error';
}

interface ToastStore {
  toasts: Toast[];
  push: (message: string, tone?: 'ok' | 'error') => void;
  dismiss: (id: number) => void;
}

let nextId = 1;

export const useToasts = create<ToastStore>((set) => ({
  toasts: [],
  push: (message, tone = 'ok') => {
    const id = nextId++;
    set((state) => ({ toasts: [...state.toasts, { id, message, tone }] }));
    setTimeout(() => set((state) => ({ toasts: state.toasts.filter((toast) => toast.id !== id) })), 4000);
  },
  dismiss: (id) => set((state) => ({ toasts: state.toasts.filter((toast) => toast.id !== id) })),
}));

export function toast(message: string, tone: 'ok' | 'error' = 'ok') {
  useToasts.getState().push(message, tone);
}

export function Toaster() {
  const toasts = useToasts((state) => state.toasts);

  return (
    <div className="impay-fixed impay-bottom-6 impay-right-6 impay-z-[60] impay-flex impay-flex-col impay-gap-2">
      <AnimatePresence>
        {toasts.map((item) => (
          <motion.div
            key={item.id}
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: 8 }}
            transition={{ duration: 0.15 }}
            className={`impay-rounded-card impay-border impay-px-4 impay-py-3 impay-text-sm impay-shadow-lg impay-bg-white ${
              item.tone === 'ok' ? 'impay-border-line impay-text-ink' : 'impay-border-bad/30 impay-text-bad'
            }`}
          >
            {item.message}
          </motion.div>
        ))}
      </AnimatePresence>
    </div>
  );
}
