import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { api, ApiError, boot } from '@shared/api';
import { Button, Card, Field, Input, Spinner } from '@shared/ui/primitives';
import { toast } from '@shared/ui/toast';
import { PageHeader } from '../App';

type SettingsMap = Record<string, string | boolean | number | null>;

const TABS = [
  { key: 'mercadopago', label: 'Mercado Pago' },
  { key: 'paypal', label: 'PayPal' },
  { key: 'epayco', label: 'ePayco' },
  { key: 'emails', label: 'Emails' },
  { key: 'avanzado', label: 'Avanzado' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

const FIELDS: Record<TabKey, { key: string; label: string; secret?: boolean; hint?: string }[]> = {
  mercadopago: [
    { key: 'mercadopago_public_key', label: 'Public Key (producción)' },
    { key: 'mercadopago_access_token', label: 'Access Token (producción)', secret: true },
    { key: 'mercadopago_public_key_test', label: 'Public Key (test)' },
    { key: 'mercadopago_access_token_test', label: 'Access Token (test)', secret: true },
    { key: 'mercadopago_webhook_secret', label: 'Secret de webhooks', secret: true, hint: 'Se usa para verificar la firma x-signature.' },
  ],
  paypal: [
    { key: 'paypal_client_id', label: 'Client ID (live)' },
    { key: 'paypal_client_secret', label: 'Client Secret (live)', secret: true },
    { key: 'paypal_client_id_test', label: 'Client ID (sandbox)' },
    { key: 'paypal_client_secret_test', label: 'Client Secret (sandbox)', secret: true },
    { key: 'paypal_webhook_id', label: 'Webhook ID', hint: 'Necesario para verificar la firma de los webhooks.' },
  ],
  epayco: [
    { key: 'epayco_cust_id', label: 'P_CUST_ID_CLIENTE (ID de cliente)' },
    { key: 'epayco_public_key', label: 'PUBLIC_KEY' },
    { key: 'epayco_p_key', label: 'P_KEY', secret: true, hint: 'Se usa para verificar la firma de la confirmación.' },
  ],
  emails: [
    { key: 'email_from_name', label: 'Nombre del remitente' },
    { key: 'email_from_address', label: 'Correo del remitente' },
    { key: 'brand_logo_url', label: 'URL del logo' },
    { key: 'brand_color', label: 'Color de marca (hex)', hint: 'Ejemplo: #4F46E5' },
  ],
  avanzado: [
    { key: 'cop_usd_rate', label: 'Tasa COP/USD referencial' },
    { key: 'log_retention_days', label: 'Retención de logs (días)' },
    { key: 'updater_api_url', label: 'URL del API de Imagina Updater' },
    { key: 'updater_api_key', label: 'API Key de Imagina Updater', secret: true },
  ],
};

export function SettingsPage() {
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<TabKey>('mercadopago');
  const [form, setForm] = useState<SettingsMap>({});

  const { data, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: () => api.get<{ settings: SettingsMap }>('admin/settings'),
  });

  useEffect(() => {
    if (data) setForm(data.settings);
  }, [data]);

  const save = useMutation({
    mutationFn: () => api.put('admin/settings', { settings: form }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['settings'] });
      toast('Ajustes guardados.');
    },
    onError: (error) => toast(error instanceof ApiError ? error.message : 'Error inesperado.', 'error'),
  });

  if (isLoading) return <Spinner />;

  const webhookUrl = (gateway: string) => `${boot().restUrl}webhooks/${gateway}`;

  return (
    <div className="impay-max-w-3xl">
      <PageHeader
        title="Ajustes"
        actions={
          <Button onClick={() => save.mutate()} disabled={save.isPending}>
            Guardar cambios
          </Button>
        }
      />

      <div className="impay-mb-4 impay-flex impay-gap-1 impay-rounded-control impay-border impay-border-line impay-bg-white impay-p-1 impay-w-fit">
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

      <Card className="impay-space-y-4 impay-p-6">
        {(tab === 'mercadopago' || tab === 'paypal' || tab === 'epayco') && (
          <div className="impay-rounded-control impay-bg-accent-soft impay-px-4 impay-py-3 impay-text-sm">
            <p className="impay-font-medium impay-text-accent">
              {tab === 'epayco' ? 'URL de confirmación para configurar en el panel:' : 'URL de webhooks para registrar en el panel:'}
            </p>
            <button
              className="impay-mt-1 impay-font-mono impay-text-xs impay-text-ink hover:impay-text-accent"
              onClick={() => {
                void navigator.clipboard.writeText(webhookUrl(tab));
                toast('URL copiada.');
              }}
            >
              {webhookUrl(tab)} (clic para copiar)
            </button>
          </div>
        )}

        {(tab === 'mercadopago' || tab === 'paypal') && (
          <label className="impay-flex impay-items-center impay-gap-2 impay-text-sm">
            <input
              type="checkbox"
              checked={Boolean(form[`${tab}_sandbox`])}
              onChange={(e) => setForm({ ...form, [`${tab}_sandbox`]: e.target.checked })}
            />
            Modo sandbox (credenciales de prueba)
          </label>
        )}

        {FIELDS[tab].map(({ key, label, secret, hint }) => (
          <Field key={key} label={label}>
            <Input
              type={secret ? 'password' : 'text'}
              value={String(form[key] ?? '')}
              onChange={(e) => setForm({ ...form, [key]: e.target.value })}
              placeholder={secret ? '••••' : ''}
              autoComplete="off"
            />
            {hint && <span className="impay-mt-1 impay-block impay-text-xs impay-text-muted">{hint}</span>}
          </Field>
        ))}

        {tab === 'epayco' && (
          <label className="impay-flex impay-items-center impay-gap-2 impay-text-sm">
            <input
              type="checkbox"
              checked={form.epayco_test === undefined ? true : Boolean(form.epayco_test)}
              onChange={(e) => setForm({ ...form, epayco_test: e.target.checked })}
            />
            Modo pruebas (transacciones de prueba)
          </label>
        )}

        {tab === 'mercadopago' && (
          <p className="impay-text-xs impay-text-muted">
            Mercado Pago cobra en COP (cuenta Colombia). Las suscripciones solo aceptan tarjeta; PSE y Nequi
            están disponibles para pagos únicos.
          </p>
        )}
        {tab === 'epayco' && (
          <p className="impay-text-xs impay-text-muted">
            ePayco está habilitado solo para pagos únicos en COP (tarjeta, PSE, efectivo). Las suscripciones no
            usan esta pasarela por decisión de negocio. Al llenar las 3 credenciales, ePayco aparece
            automáticamente como opción en el checkout de productos de pago único.
          </p>
        )}
        {tab === 'avanzado' && (
          <p className="impay-text-xs impay-text-muted">
            Los precios en USD se muestran de forma referencial usando la tasa configurada; PayPal cobra en USD.
          </p>
        )}
      </Card>
    </div>
  );
}
