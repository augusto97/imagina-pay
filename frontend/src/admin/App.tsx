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

  return (
    <div className="impay-flex impay-min-h-screen impay-bg-canvas">
      <aside className="impay-fixed impay-flex impay-h-screen impay-w-60 impay-flex-col impay-border-r impay-border-line impay-bg-white">
        <div className="impay-px-5 impay-py-5">
          <span className="impay-text-lg impay-font-semibold impay-tracking-tight">
            Imagina <span className="impay-text-accent">Pay</span>
          </span>
        </div>
        <nav className="impay-flex-1 impay-space-y-0.5 impay-px-3">
          {NAV.map(({ route: itemRoute, label, icon: Icon }) => (
            <button
              key={itemRoute}
              onClick={() => navigate(itemRoute)}
              className={`impay-flex impay-w-full impay-items-center impay-gap-3 impay-rounded-control impay-px-3 impay-py-2 impay-text-sm impay-font-medium impay-transition-colors ${
                baseRoute === itemRoute
                  ? 'impay-bg-accent-soft impay-text-accent'
                  : 'impay-text-muted hover:impay-bg-canvas hover:impay-text-ink'
              }`}
            >
              <Icon size={17} />
              {label}
            </button>
          ))}
        </nav>
        <div className="impay-border-t impay-border-line impay-px-5 impay-py-4 impay-text-xs impay-text-muted">
          {boot().userName ?? 'Administrador'}
        </div>
      </aside>

      <main className="impay-ml-60 impay-flex-1 impay-p-8">
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
