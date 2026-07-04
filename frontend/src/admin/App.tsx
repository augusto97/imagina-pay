import {
  CreditCard,
  LayoutDashboard,
  Package,
  RefreshCcw,
  Settings as SettingsIcon,
  Users,
  Webhook,
} from 'lucide-react';
import { boot } from '@shared/api';
import { Toaster } from '@shared/ui/toast';
import { useHashRoute } from './router';
import { CustomersPage } from './pages/Customers';
import { DashboardPage } from './pages/Dashboard';
import { PaymentsPage } from './pages/Payments';
import { ProductsPage } from './pages/Products';
import { SettingsPage } from './pages/Settings';
import { SubscriptionsPage } from './pages/Subscriptions';
import { WebhooksPage } from './pages/Webhooks';

const NAV = [
  { route: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { route: 'productos', label: 'Productos', icon: Package },
  { route: 'suscripciones', label: 'Suscripciones', icon: RefreshCcw },
  { route: 'clientes', label: 'Clientes', icon: Users },
  { route: 'pagos', label: 'Pagos', icon: CreditCard },
  { route: 'webhooks', label: 'Webhooks & Logs', icon: Webhook },
  { route: 'ajustes', label: 'Ajustes', icon: SettingsIcon },
] as const;

export function App() {
  const [route, navigate] = useHashRoute('dashboard');
  const baseRoute = route.split('/')[0];

  // Layout dentro del canvas de wp-admin (menú y admin bar visibles):
  // cabecera con pestañas horizontales en lugar de sidebar propia.
  return (
    <div className="impay-w-full impay-pb-8 impay-pr-3 impay-pt-2">
      <header className="impay-mb-6 impay-flex impay-flex-wrap impay-items-center impay-justify-between impay-gap-3">
        <div className="impay-flex impay-items-baseline impay-gap-2">
          <span className="impay-text-xl impay-font-semibold impay-tracking-tight">
            Imagina <span className="impay-text-accent">Pay</span>
          </span>
          {boot().version && <span className="impay-text-xs impay-text-muted">v{boot().version}</span>}
        </div>
        <nav className="impay-flex impay-flex-wrap impay-gap-1 impay-rounded-card impay-border impay-border-line impay-bg-white impay-p-1 impay-shadow-card">
          {NAV.map(({ route: itemRoute, label, icon: Icon }) => (
            <button
              key={itemRoute}
              onClick={() => navigate(itemRoute)}
              className={`impay-flex impay-items-center impay-gap-1.5 impay-rounded-control impay-px-3 impay-py-1.5 impay-text-sm impay-font-medium impay-transition-colors ${
                baseRoute === itemRoute
                  ? 'impay-bg-accent-soft impay-text-accent'
                  : 'impay-text-muted hover:impay-bg-canvas hover:impay-text-ink'
              }`}
            >
              <Icon size={15} />
              {label}
            </button>
          ))}
        </nav>
      </header>

      <main className="impay-min-w-0">
        {baseRoute === 'dashboard' && <DashboardPage />}
        {baseRoute === 'productos' && <ProductsPage />}
        {baseRoute === 'suscripciones' && <SubscriptionsPage />}
        {baseRoute === 'clientes' && <CustomersPage />}
        {baseRoute === 'pagos' && <PaymentsPage />}
        {baseRoute === 'webhooks' && <WebhooksPage />}
        {baseRoute === 'ajustes' && <SettingsPage />}
      </main>

      <Toaster />
    </div>
  );
}

export function PageHeader({ title, actions }: { title: string; actions?: React.ReactNode }) {
  return (
    <div className="impay-mb-6 impay-flex impay-items-center impay-justify-between">
      <h1 className="impay-text-xl impay-font-semibold impay-tracking-tight">{title}</h1>
      {actions}
    </div>
  );
}
