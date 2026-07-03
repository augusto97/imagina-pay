import { useState } from 'react';
import { boot } from '@shared/api';
import { Toaster } from '@shared/ui/toast';
import { LoginForm } from './Login';
import { PaymentsView } from './views/Payments';
import { ProfileView } from './views/Profile';
import { ServicesView } from './views/Services';

interface PortalBoot {
  loggedIn?: boolean;
  userName?: string;
  supportEmail?: string;
}

const TABS = [
  { key: 'servicios', label: 'Mis servicios' },
  { key: 'pagos', label: 'Pagos' },
  { key: 'perfil', label: 'Mi perfil' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

export function PortalApp() {
  const portalBoot = boot() as unknown as PortalBoot;
  const [tab, setTab] = useState<TabKey>('servicios');

  if (!portalBoot.loggedIn) {
    return (
      <div className="impay-mx-auto impay-max-w-md impay-px-4 impay-py-16">
        <LoginForm />
        <Toaster />
      </div>
    );
  }

  return (
    <div className="impay-mx-auto impay-max-w-4xl impay-px-4 impay-py-10">
      <header className="impay-mb-8 impay-flex impay-items-center impay-justify-between">
        <div>
          <h1 className="impay-text-xl impay-font-semibold impay-tracking-tight">Mi cuenta</h1>
          <p className="impay-text-sm impay-text-muted">Hola, {portalBoot.userName || 'cliente'} 👋</p>
        </div>
        {portalBoot.supportEmail && (
          <a
            href={`mailto:${portalBoot.supportEmail}`}
            className="impay-rounded-control impay-border impay-border-line impay-bg-white impay-px-4 impay-py-2 impay-text-sm impay-font-medium hover:impay-bg-canvas"
          >
            Soporte
          </a>
        )}
      </header>

      <div className="impay-mb-6 impay-flex impay-gap-1 impay-rounded-control impay-border impay-border-line impay-bg-white impay-p-1 impay-w-fit">
        {TABS.map(({ key, label }) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={`impay-rounded-[7px] impay-px-4 impay-py-1.5 impay-text-sm impay-font-medium ${
              tab === key ? 'impay-bg-accent-soft impay-text-accent' : 'impay-text-muted hover:impay-text-ink'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'servicios' && <ServicesView />}
      {tab === 'pagos' && <PaymentsView />}
      {tab === 'perfil' && <ProfileView />}

      <Toaster />
    </div>
  );
}
