import { createRoot } from 'react-dom/client';
import '../styles.css';

/** Portal de cliente (/mi-cuenta) — se implementa en la Fase 6. */

const rootElement = document.getElementById('impay-portal-root');

if (rootElement) {
  createRoot(rootElement).render(
    <div className="impay-p-8 impay-text-sm impay-text-muted">Portal de cliente — próximamente.</div>,
  );
}
