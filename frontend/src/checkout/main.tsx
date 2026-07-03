import { createRoot } from 'react-dom/client';
import '../styles.css';

/** Checkout (/checkout/{producto}) — se implementa en la Fase 6. */

const rootElement = document.getElementById('impay-checkout-root');

if (rootElement) {
  createRoot(rootElement).render(
    <div className="impay-p-8 impay-text-sm impay-text-muted">Checkout — próximamente.</div>,
  );
}
