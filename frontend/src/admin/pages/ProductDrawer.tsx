import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { api, ApiError } from '@shared/api';
import { intervalLabels, money } from '@shared/format';
import type { CustomFieldDef, Product } from '@shared/types';
import { Button, Field, Input, Select, Textarea } from '@shared/ui/primitives';
import { Drawer } from '@shared/ui/layout';
import { toast } from '@shared/ui/toast';

/** Campo personalizado en edición: options como texto separado por comas. */
interface CustomFieldRow {
  key: string;
  label: string;
  type: string;
  required: boolean;
  options: string;
}

interface FormState {
  name: string;
  slug: string;
  type: string;
  description: string;
  features: string;
  imageUrl: string;
  status: string;
  provisioningType: string;
  updaterProductId: string;
  customFields: CustomFieldRow[];
}

const emptyForm: FormState = {
  name: '',
  slug: '',
  type: 'subscription',
  description: '',
  features: '',
  imageUrl: '',
  status: 'active',
  provisioningType: '',
  updaterProductId: '',
  customFields: [],
};

const toFieldRows = (fields: CustomFieldDef[] | null | undefined): CustomFieldRow[] =>
  (fields ?? []).map((field) => ({
    key: field.key,
    label: field.label,
    type: field.type,
    required: field.required,
    options: (field.options ?? []).join(', '),
  }));

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
            features: (product.features ?? []).join('\n'),
            imageUrl: product.image_url ?? '',
            status: product.status,
            provisioningType: product.provisioning?.type ?? '',
            updaterProductId: String(product.provisioning?.updater_product_id ?? ''),
            customFields: toFieldRows(product.custom_fields),
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
    features: form.features
      .split('\n')
      .map((line) => line.trim())
      .filter(Boolean),
    image_url: form.imageUrl.trim() || undefined,
    custom_fields: form.customFields
      .filter((field) => field.label.trim() !== '')
      .map((field) => ({
        ...(field.key ? { key: field.key } : {}),
        label: field.label.trim(),
        type: field.type,
        required: field.required,
        ...(field.type === 'select'
          ? { options: field.options.split(',').map((option) => option.trim()).filter(Boolean) }
          : {}),
      })),
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
          <Textarea
            rows={3}
            value={form.description}
            onChange={(e) => set({ description: e.target.value })}
            placeholder="Texto corto que verá el cliente en el checkout y el catálogo."
          />
        </Field>

        <Field label="Características (una por línea)">
          <Textarea
            rows={4}
            value={form.features}
            onChange={(e) => set({ features: e.target.value })}
            placeholder={'2 GB de RAM\n40 GB SSD NVMe\nSoporte 24/7\nPanel de control incluido'}
          />
        </Field>

        <Field label="Imagen (URL)">
          <Input
            value={form.imageUrl}
            onChange={(e) => set({ imageUrl: e.target.value })}
            placeholder="https://tusitio.com/wp-content/uploads/producto.jpg"
            inputMode="url"
          />
        </Field>
        {form.imageUrl.trim() !== '' && (
          <img
            src={form.imageUrl.trim()}
            alt="Vista previa"
            className="impay-h-28 impay-w-full impay-rounded-control impay-border impay-border-line impay-object-cover"
          />
        )}

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
          <div className="impay-mb-3 impay-flex impay-items-center impay-justify-between">
            <h3 className="impay-text-sm impay-font-semibold">Campos extra del checkout</h3>
            <Button
              variant="ghost"
              onClick={() =>
                set({
                  customFields: [...form.customFields, { key: '', label: '', type: 'text', required: false, options: '' }],
                })
              }
            >
              + Añadir campo
            </Button>
          </div>
          <p className="impay-mb-3 impay-text-xs impay-text-muted">
            Información adicional que se pedirá al comprador de este producto (ej: dominio del sitio, cédula del
            titular, notas). Las respuestas llegan en el email de venta y quedan en el pedido.
          </p>

          {form.customFields.map((field, index) => {
            const updateField = (patch: Partial<CustomFieldRow>) =>
              set({
                customFields: form.customFields.map((current, i) => (i === index ? { ...current, ...patch } : current)),
              });

            return (
              <div
                key={index}
                className="impay-mb-2 impay-space-y-2 impay-rounded-control impay-border impay-border-line impay-p-3"
              >
                <div className="impay-flex impay-items-end impay-gap-2">
                  <Field label="Etiqueta del campo">
                    <Input
                      value={field.label}
                      onChange={(e) => updateField({ label: e.target.value })}
                      placeholder="Dominio de tu sitio"
                    />
                  </Field>
                  <Field label="Tipo">
                    <Select value={field.type} onChange={(e) => updateField({ type: e.target.value })}>
                      <option value="text">Texto</option>
                      <option value="textarea">Texto largo</option>
                      <option value="select">Lista de opciones</option>
                    </Select>
                  </Field>
                  <button
                    onClick={() => set({ customFields: form.customFields.filter((_, i) => i !== index) })}
                    className="impay-h-10 impay-shrink-0 impay-px-2 impay-text-xs impay-text-muted hover:impay-text-bad"
                    title="Quitar campo"
                  >
                    Quitar
                  </button>
                </div>

                {field.type === 'select' && (
                  <Field label="Opciones (separadas por coma)">
                    <Input
                      value={field.options}
                      onChange={(e) => updateField({ options: e.target.value })}
                      placeholder="Básico, Estándar, Premium"
                    />
                  </Field>
                )}

                <label className="impay-flex impay-items-center impay-gap-2 impay-text-sm">
                  <input
                    type="checkbox"
                    checked={field.required}
                    onChange={(e) => updateField({ required: e.target.checked })}
                  />
                  Obligatorio
                </label>
              </div>
            );
          })}
        </div>

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
