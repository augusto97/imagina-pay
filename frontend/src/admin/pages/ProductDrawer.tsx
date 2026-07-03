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

const emptyForm: FormState = {
  name: '',
  slug: '',
  type: 'subscription',
  description: '',
  status: 'active',
  provisioningType: '',
  updaterProductId: '',
};

const emptyPrice = { currency: 'COP', amount: '', interval: 'month' };

/**
 * Monto en formato es-CO ("49.900" o "49900" o "49,90") → centavos.
 * null si no es un número válido.
 */
function parseMoneyInput(raw: string): number | null {
  const cleaned = raw.trim().replace(/\s|\$/g, '');

  if (!cleaned) return null;

  // Puntos = separador de miles; coma = decimales.
  const normalized = cleaned.replace(/\./g, '').replace(',', '.');
  const value = Number(normalized);

  if (!Number.isFinite(value) || value <= 0) return null;

  return Math.round(value * 100);
}

export function ProductDrawer({
  product,
  open,
  onClose,
}: {
  product: Product | null;
  open: boolean;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<FormState>(emptyForm);
  const [newPrice, setNewPrice] = useState(emptyPrice);

  const isNew = product === null;
  const parsedAmount = parseMoneyInput(newPrice.amount);

  // Solo re-inicializar al cambiar de producto o abrir/cerrar — no en cada
  // refetch (borraría lo que el usuario está escribiendo).
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
        : emptyForm,
    );
    setNewPrice(emptyPrice);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [product?.uuid, open]);

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['products'] });

  const productPayload = () => ({
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
  });

  const pricePayload = () => ({
    currency: newPrice.currency,
    amount: parsedAmount ?? 0,
    interval: newPrice.interval,
  });

  const save = useMutation({
    mutationFn: async () => {
      if (product) {
        await api.put(`admin/products/${product.uuid}`, productPayload());

        return;
      }

      // Alta en un solo paso: producto + primer precio.
      const created = await api.post<{ product: { uuid: string } }>('admin/products', productPayload());

      if (parsedAmount !== null) {
        await api.post(`admin/products/${created.product.uuid}/prices`, pricePayload());
      }
    },
    onSuccess: () => {
      void refresh();
      toast(product ? 'Producto actualizado.' : 'Producto creado. Ya tiene su link de venta.');
      onClose();
    },
    onError: (error) => toast(error instanceof ApiError ? error.message : 'Error inesperado.', 'error'),
  });

  const addPrice = useMutation({
    mutationFn: () => api.post(`admin/products/${product?.uuid}/prices`, pricePayload()),
    onSuccess: () => {
      void refresh();
      setNewPrice(emptyPrice);
      toast('Precio guardado.');
    },
    onError: (error) => toast(error instanceof ApiError ? error.message : 'Error inesperado.', 'error'),
  });

  const archivePrice = useMutation({
    mutationFn: (priceUuid: string) => api.put(`admin/prices/${priceUuid}`, { status: 'archived' }),
    onSuccess: () => {
      void refresh();
      toast('Precio archivado.');
    },
  });

  const set = (patch: Partial<FormState>) => setForm((current) => ({ ...current, ...patch }));

  const priceFields = (
    <div className="impay-flex impay-items-end impay-gap-2">
      <Field label="Moneda">
        <Select value={newPrice.currency} onChange={(e) => setNewPrice({ ...newPrice, currency: e.target.value })}>
          <option value="COP">COP</option>
          <option value="USD">USD</option>
        </Select>
      </Field>
      <Field label="Monto">
        <Input
          value={newPrice.amount}
          onChange={(e) => setNewPrice({ ...newPrice, amount: e.target.value })}
          placeholder="49.900"
          inputMode="decimal"
        />
      </Field>
      <Field label="Intervalo">
        <Select value={newPrice.interval} onChange={(e) => setNewPrice({ ...newPrice, interval: e.target.value })}>
          <option value="one_time">Único</option>
          <option value="month">Mensual</option>
          <option value="year">Anual</option>
        </Select>
      </Field>
      {!isNew && (
        <Button variant="secondary" onClick={() => addPrice.mutate()} disabled={parsedAmount === null || addPrice.isPending}>
          {addPrice.isPending ? 'Guardando…' : 'Añadir'}
        </Button>
      )}
    </div>
  );

  const pricePreview = newPrice.amount.trim() !== '' && (
    <p className={`impay-mt-2 impay-text-xs ${parsedAmount === null ? 'impay-text-bad' : 'impay-text-muted'}`}>
      {parsedAmount === null
        ? 'Monto no válido. Escribe por ejemplo 49.900 (pesos) o 12,99 (con decimales).'
        : `El cliente verá: ${money(parsedAmount, newPrice.currency)} ${intervalLabels[newPrice.interval]}`}
    </p>
  );

  return (
    <Drawer open={open} title={product ? 'Editar producto' : 'Nuevo producto'} onClose={onClose} wide>
      <div className="impay-space-y-4">
        <Field label="Nombre">
          <Input value={form.name} onChange={(e) => set({ name: e.target.value })} placeholder="VPS Cloud 2GB" />
        </Field>

        <div className="impay-grid impay-grid-cols-2 impay-gap-4">
          <Field label="Slug (URL de venta)">
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
              <option value="active">Activo (a la venta)</option>
              <option value="draft">Borrador</option>
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

        <div className="impay-border-t impay-border-line impay-pt-4">
          <h3 className="impay-mb-3 impay-text-sm impay-font-semibold">
            {isNew ? 'Precio inicial' : 'Precios'}
          </h3>

          {!isNew && (
            <div className="impay-mb-3 impay-space-y-2">
              {(product?.prices ?? []).map((price) => (
                <div
                  key={price.uuid}
                  className="impay-flex impay-items-center impay-justify-between impay-rounded-control impay-border impay-border-line impay-px-3 impay-py-2"
                >
                  <span className="impay-tabular impay-text-sm">
                    {price.formatted} <span className="impay-text-muted">{intervalLabels[price.interval]}</span>
                    {price.status === 'archived' && (
                      <span className="impay-ml-2 impay-text-xs impay-text-muted">(archivado)</span>
                    )}
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
              {(product?.prices ?? []).length === 0 && (
                <p className="impay-text-sm impay-text-muted">Este producto aún no tiene precios.</p>
              )}
            </div>
          )}

          {priceFields}
          {pricePreview}
        </div>

        <div className="impay-flex impay-justify-end impay-gap-2 impay-border-t impay-border-line impay-pt-4">
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button
            onClick={() => save.mutate()}
            disabled={!form.name || save.isPending || (isNew && newPrice.amount.trim() !== '' && parsedAmount === null)}
          >
            {save.isPending ? 'Guardando…' : product ? 'Guardar cambios' : 'Crear producto'}
          </Button>
        </div>
      </div>
    </Drawer>
  );
}
