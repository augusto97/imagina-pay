# Imagina Pay

Plugin WordPress de venta de productos digitales y suscripciones para LATAM con
**Mercado Pago** (Checkout Pro + Preapproval) y **PayPal** (Orders v2 + Billing
Subscriptions). Sin WooCommerce. El motor de cobro recurrente vive en las
pasarelas; el plugin orquesta estado vía webhooks firmados e idempotentes.

La especificación completa vive en [`CLAUDE.md`](CLAUDE.md); la bitácora de
construcción y decisiones en [`PROGRESS.md`](PROGRESS.md); los hooks públicos
en [`docs/HOOKS.md`](docs/HOOKS.md).

## Requisitos

- PHP **8.1+** · WordPress **6.4+** · MySQL/MariaDB **10.4+**
- Node 20+ (solo para compilar el frontend)
- Permalinks activos (la ruta `/checkout/{slug}` usa rewrite rules)

## Instalación / despliegue

```bash
cd wp-content/plugins/imagina-pay

# Backend (producción: sin dependencias de desarrollo)
composer install --no-dev --optimize-autoloader

# Frontend (genera frontend/dist/ con el manifest que consume PHP)
cd frontend && npm ci && npm run build && cd ..
```

Activa el plugin en wp-admin. La activación crea las tablas (`impay_*`), la
capability `manage_impay` (administrator), el rol `impay_customer` y las
páginas **Checkout**, **Gracias** y **Mi cuenta**.

## Configuración

En **wp-admin → Imagina Pay → Ajustes**:

1. **Mercado Pago** (cuenta Colombia, cobra en COP): Public Key + Access Token
   (producción y test), **secret de webhooks** y toggle sandbox.
2. **PayPal** (USD): Client ID + Secret (live y sandbox) y **Webhook ID**.
3. **Emails**: remitente, logo y color de marca.
4. **Avanzado**: tasa COP/USD referencial, retención de logs, URL y API key de
   Imagina Updater (licencias).

Las credenciales se cifran at-rest (AES-256-GCM, clave derivada de `AUTH_KEY`)
y jamás se muestran completas.

### Registro de webhooks

| Pasarela | URL a registrar | Suscribirse a |
|---|---|---|
| Mercado Pago (panel → Webhooks) | `https://tu-sitio.com/wp-json/impay/v1/webhooks/mercadopago` | `payment`, `subscription_preapproval`, `subscription_authorized_payment` |
| PayPal (Developer Dashboard → Webhooks) | `https://tu-sitio.com/wp-json/impay/v1/webhooks/paypal` | `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.SALE.COMPLETED`, `BILLING.SUBSCRIPTION.*` |

La URL exacta también aparece copiable en la pestaña de cada pasarela en Ajustes.
Toda entrega con firma inválida responde **401** y queda en logs.

## Checklist QA en sandbox (antes de salir a producción)

Con credenciales de **test** y sandbox activado en Ajustes:

1. **Compra única (MP)**: crear producto `one_time` con precio COP → pagar en
   `/checkout/{slug}` con tarjeta de prueba → verificar en /gracias el paso a
   "Pago confirmado" · order `paid` · payment `approved` · email de recibo ·
   email admin "venta nueva" · usuario WP creado con email de contraseña.
2. **Suscripción mensual (MP)**: producto `subscription` + precio mensual →
   autorizar preapproval con tarjeta de test → webhook activa la suscripción →
   provisión disparada (licencia/hook/manual) → email de bienvenida.
3. **Suscripción (PayPal)**: precio USD → aprobar en sandbox → `BILLING.SUBSCRIPTION.ACTIVATED`
   activa · `PAYMENT.SALE.COMPLETED` extiende el periodo.
4. **Anual híbrido**: producto `annual_hybrid` + precio anual → pagar como único →
   verificar suscripción lógica activa con vencimiento +1 año.
5. **Renovación por link**: adelantar `current_period_end` a <30 días (o extender
   con la acción admin) → correr el job `impay_renewal_reminders` (Action
   Scheduler → ejecutar) → verificar link + email → pagarlo → periodo +1 año,
   order `renewal`, email de confirmación.
6. **Dunning**: forzar un pago rechazado (tarjeta de rechazo de MP) → past_due →
   correr `impay_dunning_notices` → emails día 0/3/7 y suspensión.
7. **Reconciliación**: cancelar la suscripción desde el panel de la pasarela →
   correr `impay_reconcile` → estado local corregido.
8. **Portal**: login, licencia copiable, banda de renovación, cancelación al
   vencer, recibo imprimible.
9. **Webhooks admin**: revisar Imagina Pay → Webhooks & Logs (eventos `processed`,
   salud por pasarela, retry).

## Desarrollo

```bash
composer install            # incluye dev-deps
composer test               # PHPUnit (Brain Monkey, sin WordPress)
composer phpstan            # nivel 8
composer phpcs              # WordPress-Extra ajustado a PSR-12

cd frontend
npm run dev                 # Vite dev server
npm run build               # tsc --noEmit + build con manifest
```

- **Arquitectura**: `Controller (REST) → Service → Repository → wpdb`, contenedor
  DI propio, entidades readonly, enums PHP 8.1, máquina de estados explícita.
- **Presupuesto de peso**: en páginas ajenas al plugin solo se registran hooks
  (0 queries añadidas); los assets se encolan únicamente en las 3 páginas propias
  y en wp-admin → Imagina Pay. El checkout pesa ~66KB gz.
- **Añadir una pasarela**: implementar `ImaginaPay\Gateways\GatewayInterface`,
  registrarla en el `GatewayRegistry` (Plugin.php) y declarar sus `supports()`.
  El core ramifica por `GatewayMode`, nunca por nombre.

## Datos

Tablas propias con prefijo `impay_` (sin CPTs ni postmeta). Al desinstalar, los
datos **se conservan** (transacciones y clientes no se borran automáticamente);
eliminar las tablas manualmente si se desea una limpieza total.
