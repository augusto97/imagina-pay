import { AnimatePresence, motion } from 'framer-motion';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';

/** Drawer lateral y tabla base con la estética del sistema. */

export function Drawer({
  open,
  title,
  onClose,
  children,
  wide = false,
}: {
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
  wide?: boolean;
}) {
  return (
    <AnimatePresence>
      {open && (
        <>
          <motion.div
            className="impay-fixed impay-inset-0 impay-z-[100000] impay-bg-black/20"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.15 }}
            onClick={onClose}
          />
          <motion.aside
            className={`impay-fixed impay-right-0 impay-top-0 impay-z-[100001] impay-h-full impay-overflow-y-auto impay-bg-white impay-shadow-xl ${
              wide ? 'impay-w-[560px]' : 'impay-w-[440px]'
            } impay-max-w-full`}
            initial={{ x: 24, opacity: 0 }}
            animate={{ x: 0, opacity: 1 }}
            exit={{ x: 24, opacity: 0 }}
            transition={{ duration: 0.18, ease: 'easeOut' }}
          >
            <header className="impay-sticky impay-top-0 impay-z-10 impay-flex impay-items-center impay-justify-between impay-border-b impay-border-line impay-bg-white impay-px-6 impay-py-4">
              <h2 className="impay-text-base impay-font-semibold impay-tracking-tight">{title}</h2>
              <button
                onClick={onClose}
                className="impay-rounded-control impay-p-1.5 impay-text-muted hover:impay-bg-canvas hover:impay-text-ink"
                aria-label="Cerrar"
              >
                <X size={18} />
              </button>
            </header>
            <div className="impay-p-6">{children}</div>
          </motion.aside>
        </>
      )}
    </AnimatePresence>
  );
}

export function DataTable({ head, children }: { head: string[]; children: ReactNode }) {
  return (
    <div className="impay-overflow-x-auto">
      <table className="impay-w-full impay-text-sm">
        <thead>
          <tr className="impay-border-b impay-border-line">
            {head.map((column) => (
              <th
                key={column}
                className="impay-px-4 impay-py-3 impay-text-left impay-text-xs impay-font-medium impay-uppercase impay-tracking-wide impay-text-muted"
              >
                {column}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="impay-divide-y impay-divide-line">{children}</tbody>
      </table>
    </div>
  );
}

export function Pagination({
  page,
  total,
  perPage,
  onPage,
}: {
  page: number;
  total: number;
  perPage: number;
  onPage: (page: number) => void;
}) {
  const pages = Math.max(1, Math.ceil(total / perPage));

  if (pages <= 1) return null;

  return (
    <div className="impay-flex impay-items-center impay-justify-between impay-border-t impay-border-line impay-px-4 impay-py-3 impay-text-sm impay-text-muted">
      <span>
        Página {page} de {pages} · {total} registros
      </span>
      <div className="impay-flex impay-gap-2">
        <button
          disabled={page <= 1}
          onClick={() => onPage(page - 1)}
          className="impay-rounded-control impay-border impay-border-line impay-px-3 impay-py-1 disabled:impay-opacity-40"
        >
          Anterior
        </button>
        <button
          disabled={page >= pages}
          onClick={() => onPage(page + 1)}
          className="impay-rounded-control impay-border impay-border-line impay-px-3 impay-py-1 disabled:impay-opacity-40"
        >
          Siguiente
        </button>
      </div>
    </div>
  );
}
