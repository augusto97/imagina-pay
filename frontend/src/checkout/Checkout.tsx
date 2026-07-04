import { useMemo, useState } from 'react';
import { z } from 'zod';
import { api, ApiError, boot } from '@shared/api';
import { intervalLabels } from '@shared/format';
import type { Price, Product } from '@shared/types';

const schema = z.object({
  full_name: z.string().min(3, 'Ingresa tu nombre completo.'),
  email: z.string().email('Correo electrónico inválido.'),
  company: z.string().optional(),
  tax_id_type: z.enum(['CC', 'NIT', 'CE', 'PAS', 'RUT', 'OTRO']),
  tax_id: z.string().min(3, 'Ingresa tu número de documento.'),
  country: z.string().length(2),
  phone: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

const initialValues: FormValues = {
  full_name: '',
  email: '',
  company: '',
  tax_id_type: 'CC',
  tax_id: '',
  country: 'CO',
  phone: '',
};

interface CheckoutBoot {
  product?: Product;
}

export function CheckoutPage() {
  const product = useMemo(() => (boot() as unknown as CheckoutBoot).product, []);
  const [priceUuid, setPriceUuid] = useState(product?.prices[0]?.uuid ?? '');
  const [gateway, setGateway] = useState('mercadopago');
  const [values, setValues] = useState<FormValues>(initialValues);
  const [errors, setErrors] = useState<Partial<Record<keyof FormValues | 'form', string>>>({});
  const [submitting, setSubmitting] = useState(false);
  const [honeypot, setHoneypot] = useState('');

  if (!product) return null;

  const price = product.prices.find((candidate) => candidate.uuid === priceUuid);
  const isRecurring = product.type === 'subscription' && price?.interval !== 'one_time';
  const availableForGateway = (candidate: Price) =>
    gateway === 'paypal' ? candidate.currency === 'USD' : candidate.currency === 'COP';

  const submit = async () => {
    const parsed = schema.safeParse(values);

    if (!parsed.success) {
      const fieldErrors: Record<string, string> = {};

      for (const issue of parsed.error.issues) {
        fieldErrors[String(issue.path[0])] = issue.message;
      }

      setErrors(fieldErrors);
      return;
    }

    setErrors({});
    setSubmitting(true);

    try {
      const result = await api.post<{ redirect_url: string }>('checkout', {
        product: product.uuid,
        price: priceUuid,
        gateway,
        website: honeypot,
        ...parsed.data,
      });

      window.location.href = result.redirect_url;
    } catch (error) {
      setSubmitting(false);
      setErrors({
        form: error instanceof ApiError ? error.message : 'No fue posible iniciar el pago. Intenta de nuevo.',
      });
    }
  };

  const set = (field: keyof FormValues) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setValues((current) => ({ ...current, [field]: event.target.value }));

  const inputClass =
    'impay-h-11 impay-w-full impay-rounded-control impay-border impay-border-line impay-bg-white impay-px-3 impay-text-sm focus:impay-outline-none focus:impay-ring-2 focus:impay-ring-accent/30 focus:impay-border-accent';

  return (
    <div className="impay-mx-auto impay-max-w-5xl impay-px-4 impay-py-10">
      <div className="impay-grid impay-grid-cols-2 impay-gap-10 max-md:impay-grid-cols-1">
        {/* Resumen del producto */}
        <div>
          {product.image_url && (
            <img
              src={product.image_url}
              alt={product.name}
              className="impay-mb-6 impay-h-44 impay-w-full impay-rounded-card impay-border impay-border-line impay-object-cover"
            />
          )}
          <h1 className="impay-text-2xl impay-font-semibold impay-tracking-tight">{product.name}</h1>
          {product.description && <p className="impay-mt-2 impay-text-sm impay-text-muted">{product.description}</p>}

          {product.features && product.features.length > 0 && (
            <ul className="impay-mt-6 impay-space-y-2">
              {product.features.map((feature) => (
                <li key={feature} className="impay-flex impay-items-start impay-gap-2 impay-text-sm">
                  <span className="impay-mt-0.5 impay-text-ok">✓</span> {feature}
                </li>
              ))}
            </ul>
          )}

          {price && (
            <div className="impay-mt-8 impay-rounded-card impay-border impay-border-line impay-bg-white impay-p-5 impay-shadow-card">
              <p className="impay-tabular impay-text-3xl impay-font-semibold impay-tracking-tight">
                {price.formatted}
                <span className="impay-ml-1 impay-text-base impay-font-normal impay-text-muted">
                  {intervalLabels[price.interval]}
                </span>
              </p>
              {isRecurring && (
                <p className="impay-mt-1 impay-text-xs impay-text-muted">Se renueva automáticamente. Cancela cuando quieras.</p>
              )}
            </div>
          )}

          <p className="impay-mt-6 impay-flex impay-items-center impay-gap-2 impay-text-xs impay-text-muted">
            🔒 Pago seguro procesado por Mercado Pago o PayPal. No almacenamos datos de tu tarjeta.
          </p>
        </div>

        {/* Formulario */}
        <div className="impay-rounded-card impay-border impay-border-line impay-bg-white impay-p-6 impay-shadow-card">
          <div className="impay-space-y-4">
            <label className="impay-block impay-text-sm">
              <span className="impay-mb-1.5 impay-block impay-font-medium">Nombre completo</span>
              <input className={inputClass} value={values.full_name} onChange={set('full_name')} />
              {errors.full_name && <span className="impay-mt-1 impay-block impay-text-xs impay-text-bad">{errors.full_name}</span>}
            </label>

            <label className="impay-block impay-text-sm">
              <span className="impay-mb-1.5 impay-block impay-font-medium">Correo electrónico</span>
              <input className={inputClass} type="email" value={values.email} onChange={set('email')} />
              {errors.email && <span className="impay-mt-1 impay-block impay-text-xs impay-text-bad">{errors.email}</span>}
            </label>

            <div className="impay-grid impay-grid-cols-3 impay-gap-3">
              <label className="impay-block impay-text-sm">
                <span className="impay-mb-1.5 impay-block impay-font-medium">Documento</span>
                <select className={inputClass} value={values.tax_id_type} onChange={set('tax_id_type')}>
                  {['CC', 'NIT', 'CE', 'PAS', 'RUT', 'OTRO'].map((type) => (
                    <option key={type}>{type}</option>
                  ))}
                </select>
              </label>
              <label className="impay-col-span-2 impay-block impay-text-sm">
                <span className="impay-mb-1.5 impay-block impay-font-medium">Número</span>
                <input className={inputClass} value={values.tax_id} onChange={set('tax_id')} />
                {errors.tax_id && <span className="impay-mt-1 impay-block impay-text-xs impay-text-bad">{errors.tax_id}</span>}
              </label>
            </div>

            <label className="impay-block impay-text-sm">
              <span className="impay-mb-1.5 impay-block impay-font-medium">Empresa (opcional)</span>
              <input className={inputClass} value={values.company} onChange={set('company')} />
            </label>

            {/* Honeypot: invisible para humanos */}
            <input
              type="text"
              value={honeypot}
              onChange={(event) => setHoneypot(event.target.value)}
              tabIndex={-1}
              autoComplete="off"
              aria-hidden="true"
              style={{ position: 'absolute', left: '-9999px' }}
              name="website"
            />

            {/* Selector de precio si hay varios */}
            {product.prices.filter(availableForGateway).length > 1 && (
              <label className="impay-block impay-text-sm">
                <span className="impay-mb-1.5 impay-block impay-font-medium">Plan</span>
                <select className={inputClass} value={priceUuid} onChange={(event) => setPriceUuid(event.target.value)}>
                  {product.prices.filter(availableForGateway).map((candidate) => (
                    <option key={candidate.uuid} value={candidate.uuid}>
                      {candidate.formatted} {intervalLabels[candidate.interval]}
                    </option>
                  ))}
                </select>
              </label>
            )}

            {/* Método de pago */}
            <div>
              <span className="impay-mb-1.5 impay-block impay-text-sm impay-font-medium">Método de pago</span>
              <div className="impay-grid impay-grid-cols-2 impay-gap-3">
                {(['mercadopago', 'paypal'] as const).map((option) => {
                  const hasPrices = product.prices.some((candidate) =>
                    option === 'paypal' ? candidate.currency === 'USD' : candidate.currency === 'COP',
                  );

                  if (!hasPrices) return null;

                  return (
                    <button
                      key={option}
                      type="button"
                      onClick={() => {
                        setGateway(option);
                        const first = product.prices.find((candidate) =>
                          option === 'paypal' ? candidate.currency === 'USD' : candidate.currency === 'COP',
                        );
                        if (first) setPriceUuid(first.uuid);
                      }}
                      className={`impay-rounded-control impay-border impay-px-4 impay-py-3 impay-text-left impay-text-sm ${
                        gateway === option
                          ? 'impay-border-accent impay-bg-accent-soft'
                          : 'impay-border-line impay-bg-white hover:impay-bg-canvas'
                      }`}
                    >
                      <span className="impay-font-medium">{option === 'mercadopago' ? 'Mercado Pago' : 'PayPal'}</span>
                      <span className="impay-block impay-text-xs impay-text-muted">
                        {option === 'mercadopago'
                          ? isRecurring
                            ? 'COP · solo tarjeta (suscripción)'
                            : 'COP · tarjeta, PSE, Nequi'
                          : 'USD · internacional'}
                      </span>
                    </button>
                  );
                })}
              </div>
              {isRecurring && gateway === 'mercadopago' && (
                <p className="impay-mt-2 impay-text-xs impay-text-muted">
                  Las suscripciones con Mercado Pago se pagan con tarjeta de crédito o débito.
                </p>
              )}
            </div>

            {errors.form && (
              <p className="impay-rounded-control impay-bg-red-50 impay-px-3 impay-py-2 impay-text-sm impay-text-bad">
                {errors.form}
              </p>
            )}

            <button
              onClick={() => void submit()}
              disabled={submitting || !price}
              className="impay-h-12 impay-w-full impay-rounded-control impay-bg-accent impay-text-sm impay-font-semibold impay-text-white hover:impay-bg-accent-hover disabled:impay-opacity-60"
            >
              {submitting ? 'Redirigiendo…' : `Continuar al pago${price ? ` · ${price.formatted}` : ''}`}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
