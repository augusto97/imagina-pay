import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { api, ApiError } from '@shared/api';
import { intervalLabels, money } from '@shared/format';
import type { Product } from '@shared/types';
import { Button, Field, Input, Select } from '@shared/ui/primitives';
import { Drawer } from '@shared/ui/layout';
import { toast } from '@shared/ui/toast';

interface FormState {
  name: string;
  slug: string;
  type: string;
  description: string;
  status: string;
  provisioningType: string;
  updaterProductId: string;
}

const empty: FormState = {
  name: '',
  slug: '',
  type: 'subscription',
  description: '',
  status: 'draft',
  provisioningType: '',
  updaterProductId: '',
};

export function ProductDrawer({ product, open, onClose }: { product: Product | null; open: boolean; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<FormState>(empty);
  const [newPrice, setNewPrice] = useState({ currency: 'COP', amount: '', interval: 'month' });

  useEffect(() => {
    setForm(
      product
        ? {
            name: product.name,
            slug: product.slug,
            type: product.type,
            description: product.description ?? '',
            status: product.status,
            provisioningType: product.provisioning?.type ?? '',
            updaterProductId: String(product.provisioning?.updater_product_id ?? ''),
          }
        : empty,
    );
  }, [product, open]);

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        name: form.name,
        slug: form.slug || undefined,
        type: form.type,
        description: form.description || undefined,
        status: form.status,
        provisioning: form.provisioningType
          ? {
              type: form.provisioningType,
              ...(form.provisioningType === 'updater_license'
                ? { updater_product_id: Number(form.updaterProductId) || 0 }
                : {}),
            }
          : undefined,
      };

      return product ? api.put(`admin/products/${product.uuid}`, payload) : api.post('admin/products', payload);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      toast(product ? 'Producto actualizado.' : 'Producto creado.');
      onClose();
    },
    onError: (error) => toast(error instanceof ApiError ? error.message : 'Error inesperado.', 'error'),
  });

  const addPrice = useMutation({
    mutationFn: () =>
      api.post(`admin/products/${product?.uuid}/prices`, {
        currency: newPrice.currency,
        amount: Math.round(Number(newPrice.amount) * 100),
        interval: newPrice.interval,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      setNewPrice({ currency: 'COP', amount: '', interval: 'month' });
      toast('Precio creado.');
    },
    onError: (error) => toast(error instanceof ApiError ? error.message : 'Error inesperado.', 'error'),
  });

  const archivePrice = useMutation({
    mutationFn: (priceUuid: string) => api.put(`admin/prices/${priceUuid}`, { status: 'archived' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      toast('Precio archivado.');
    },
  });

  const set = (patch: Partial<FormState>) => setForm((current) => ({ ...current, ...patch }));

  return (
    <Drawer open={open} title={product ? 'Editar producto' : 'Nuevo producto'} onClose={onClose} wide>
      <div className="impay-space-y-4">
        <Field label="Nombre">
          <Input value={form.name} onChange={(e) => set({ name: e.target.value })} placeholder="VPS Cloud 2GB" />
        </Field>

        <div className="impay-grid impay-grid-cols-2 impay-gap-4">
          <Field label="Slug (URL del checkout)">
            <Input value={form.slug} onChange={(e) => set({ slug: e.target.value })} placeholder="vps-cloud-2gb" />
          </Field>
          <Field label="Tipo">
            <Select value={form.type} onChange={(e) => set({ type: e.target.value })} className="impay-w-full">
              <option value="one_time">Pago único</option>
              <option value="subscription">Suscripción</option>
              <option value="annual_hybrid">Anual híbrido (renovación por link)</option>
            </Select>
          </Field>
        </div>

        <Field label="Descripción">
          <Input value={form.description} onChange={(e) => set({ description: e.target.value })} />
        </Field>

        <div className="impay-grid impay-grid-cols-2 impay-gap-4">
          <Field label="Estado">
            <Select value={form.status} onChange={(e) => set({ status: e.target.value })} className="impay-w-full">
              <option value="draft">Borrador</option>
              <option value="active">Activo</option>
              <option value="archived">Archivado</option>
            </Select>
          </Field>
          <Field label="Provisión">
            <Select
              value={form.provisioningType}
              onChange={(e) => set({ provisioningType: e.target.value })}
              className="impay-w-full"
            >
              <option value="">Sin provisión automática</option>
              <option value="updater_license">Licencia Imagina Updater</option>
              <option value="hook">Hook (impay_provision)</option>
              <option value="manual">Tarea manual</option>
            </Select>
          </Field>
        </div>

        {form.provisioningType === 'updater_license' && (
          <Field label="ID del producto en Imagina Updater">
            <Input
              value={form.updaterProductId}
              onChange={(e) => set({ updaterProductId: e.target.value })}
              placeholder="12"
            />
          </Field>
        )}

        {product && (
          <div className="impay-border-t impay-border-line impay-pt-4">
            <h3 className="impay-mb-3 impay-text-sm impay-font-semibold">Precios</h3>
            <div className="impay-space-y-2">
              {product.prices.map((price) => (
                <div
                  key={price.uuid}
                  className="impay-flex impay-items-center impay-justify-between impay-rounded-control impay-border impay-border-line impay-px-3 impay-py-2"
                >
                  <span className="impay-tabular impay-text-sm">
                    {price.formatted} <span className="impay-text-muted">{intervalLabels[price.interval]}</span>
                    {price.status === 'archived' && <span className="impay-ml-2 impay-text-xs impay-text-muted">(archivado)</span>}
                  </span>
                  {price.status === 'active' && (
                    <button
                      onClick={() => archivePrice.mutate(price.uuid)}
                      className="impay-text-xs impay-text-muted hover:impay-text-bad"
                    >
                      Archivar
                    </button>
                  )}
                </div>
              ))}
            </div>

            <div className="impay-mt-3 impay-flex impay-items-end impay-gap-2">
              <Field label="Moneda">
                <Select
                  value={newPrice.currency}
                  onChange={(e) => setNewPrice({ ...newPrice, currency: e.target.value })}
                >
                  <option value="COP">COP</option>
                  <option value="USD">USD</option>
                </Select>
              </Field>
              <Field label="Monto">
                <Input
                  value={newPrice.amount}
                  onChange={(e) => setNewPrice({ ...newPrice, amount: e.target.value })}
                  placeholder="49900"
                  inputMode="decimal"
                />
              </Field>
              <Field label="Intervalo">
                <Select
                  value={newPrice.interval}
                  onChange={(e) => setNewPrice({ ...newPrice, interval: e.target.value })}
                >
                  <option value="one_time">Único</option>
                  <option value="month">Mensual</option>
                  <option value="year">Anual</option>
                </Select>
              </Field>
              <Button variant="secondary" onClick={() => addPrice.mutate()} disabled={!newPrice.amount}>
                Añadir
              </Button>
            </div>
            {newPrice.amount && Number(newPrice.amount) > 0 && (
              <p className="impay-mt-2 impay-text-xs impay-text-muted">
                El cliente verá: {money(Math.round(Number(newPrice.amount) * 100), newPrice.currency)}{' '}
                {intervalLabels[newPrice.interval]}
              </p>
            )}
          </div>
        )}

        <div className="impay-flex impay-justify-end impay-gap-2 impay-border-t impay-border-line impay-pt-4">
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={() => save.mutate()} disabled={!form.name || save.isPending}>
            {product ? 'Guardar cambios' : 'Crear producto'}
          </Button>
        </div>
      </div>
    </Drawer>
  );
}
