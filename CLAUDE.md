# CLAUDE.md — Imagina Pay v1.0

> **Plugin propio de venta de productos digitales y suscripciones para LATAM**
> Mercado Pago (Checkout Pro + Suscripciones/preapproval) y PayPal (Orders v2 + Subscriptions).
> Sin WooCommerce. Sin EDD. Liviano, robusto, hermoso.

---

## 1. Contexto y objetivo

Imagina WP (agencia WordPress, Manizales, Colombia) vende un catálogo **pequeño** (10–20 productos):

- Planes de hosting **anuales** → pago único + renovación asistida por link de pago
- Servidores VPS **mensuales** → suscripción recurrente real
- Licencias de aplicativos (plugins Imagina) → mensual o anual, provisionadas vía **Imagina Updater**

**Problema que resuelve:** Stripe no opera en Colombia. Las suscripciones deben funcionar con Mercado Pago y PayPal. WooCommerce + WC Subscriptions es demasiado pesado y el plugin oficial de MP no soporta recurrencia con WC Subscriptions.

**Decisión arquitectónica central:** el motor de cobro recurrente **NO vive en el plugin**. Vive en Mercado Pago (preapproval) y PayPal (Billing Subscriptions). El plugin es un **orquestador de estado**: crea la suscripción en la pasarela, consume webhooks, mantiene una máquina de estados local, provisiona servicios y expone un portal de cliente. Esto elimina PCI, reintentos de cobro propios y crons frágiles de renovación.

### Principios innegociables

1. **Liviano**: cero impacto en el frontend del sitio salvo las 3 páginas propias (checkout, gracias, portal). Nada de assets globales. Presupuesto: máx. **3 queries y 30 ms** añadidos en páginas que no son del plugin (idealmente 0 — los hooks solo se registran en rutas propias).
2. **Robusto**: webhooks idempotentes, firma verificada, máquina de estados explícita, reconciliación diaria contra la API de la pasarela, logs estructurados.
3. **API-first**: todo pasa por REST (`impay/v1`). Los SPAs (admin y portal) son clientes de esa API.
4. **Tablas propias**: nada de CPTs ni postmeta para datos transaccionales. Solo tablas custom con índices correctos.
5. **Pasarela como plugin del plugin**: `GatewayInterface` limpia. Añadir Wompi/ePayco/Bold en el futuro = una clase nueva, cero cambios en el core.
6. **Interfaz nivel Linear/Vercel**: el admin y el portal deben sentirse como un SaaS moderno, no como wp-admin.

---

## 2. Identidad del proyecto

| Ítem | Valor |
|---|---|
| Nombre | Imagina Pay |
| Slug | `imagina-pay` |
| Prefijo PHP/DB/hooks | `impay_` |
| Namespace PHP | `ImaginaPay\` (PSR-4, `src/`) |
| Namespace REST | `impay/v1` |
| Prefijo Tailwind | `impay-` |
| Text domain | `imagina-pay` |
| Versión mínima | PHP 8.1, WP 6.4, MySQL/MariaDB 10.4 |

---

## 3. Stack tecnológico

### Backend (PHP)
- PHP 8.1+ con `declare(strict_types=1)` en **todos** los archivos
- PSR-4 vía Composer, autoload optimizado en build
- Contenedor DI propio (ligero, ~100 líneas, ya usado en otros plugins Imagina — mismo patrón)
- Capas: `Controller (REST) → Service → Repository → wpdb`
- **Action Scheduler** (embebido vía Composer, `woocommerce/action-scheduler`) para jobs
- PHPStan **level 8**, PHPCS con WordPress-Extra
- Cliente HTTP: `wp_remote_*` envuelto en `HttpClient` propio con retries exponenciales (3 intentos, 1s/4s/9s) e idempotency keys

### Frontend (2 SPAs + 1 página ligera)
- React 18 + TypeScript + Vite (build multi-entry: `admin`, `portal`, `checkout`)
- shadcn/ui + Tailwind con prefijo `impay-` y `important: '#impay-root'`
- TanStack Query (server state) + TanStack Table (listados admin)
- Zustand (UI state mínimo), React Hook Form + Zod
- Lucide icons, Framer Motion (transiciones sutiles), fuente **Inter** (self-hosted, solo en páginas propias)
- i18n: strings en español por defecto, preparado con archivo de traducciones simple (JSON), no i18next completo en v1

### Sin dependencias de
- jQuery, Select2, assets de WP admin legacy, SDKs oficiales pesados de MP/PayPal en PHP (se consume la API REST directamente — los SDKs traen bloat y versiones conflictivas)

---

## 4. Arquitectura general

```
┌─────────────────────────────────────────────────────────────┐
│ WordPress (sitio agencia)                                    │
│                                                              │
│  /checkout/{product-slug}   ← página React ligera (checkout) │
│  /gracias?order={uuid}      ← estado post-pago (polling)     │
│  /mi-cuenta                 ← Portal Cliente (SPA React)     │
│  wp-admin → Imagina Pay     ← Admin SPA (React, full page)   │
│                                                              │
│  REST impay/v1  ────────────┐                                │
│  Webhooks:                  │                                │
│   /wp-json/impay/v1/webhooks/mercadopago                     │
│   /wp-json/impay/v1/webhooks/paypal                          │
└──────────────┬──────────────┴────────────────────────────────┘
               │
     ┌─────────┴──────────┐          ┌──────────────────┐
     │ Mercado Pago       │          │ PayPal            │
     │ · Checkout Pro     │          │ · Orders v2       │
     │ · Preapproval      │          │ · Billing Subs    │
     └────────────────────┘          └──────────────────┘
               │
     ┌─────────┴───────────────────────────────┐
     │ Provisión (hooks + integraciones)        │
     │ · Imagina Updater (licencias, API)       │
     │ · do_action('impay_subscription_active') │
     │ · Emails transaccionales                 │
     └──────────────────────────────────────────┘
```

---

## 5. Modelo de datos (tablas custom)

Todas con `dbDelta`, charset del sitio, motor InnoDB. UUIDs v4 (`char(36)`) como referencia pública; `bigint` autoincrement como PK interna. Timestamps UTC (`datetime`), conversión a `America/Bogota` solo en presentación.

### `impay_products`
```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
uuid CHAR(36) NOT NULL UNIQUE,
name VARCHAR(190) NOT NULL,
slug VARCHAR(190) NOT NULL UNIQUE,
type ENUM('one_time','subscription','annual_hybrid') NOT NULL,
description TEXT NULL,
features JSON NULL,              -- lista de bullets para el checkout
image_url VARCHAR(500) NULL,
status ENUM('active','archived','draft') DEFAULT 'draft',
provisioning JSON NULL,          -- {"type":"updater_license","updater_product_id":12} | {"type":"hook"} | {"type":"manual"}
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
INDEX idx_status (status)
```

### `impay_prices`
Un producto puede tener varios precios (mensual/anual, COP/USD).
```sql
id, uuid, product_id BIGINT FK,
currency CHAR(3) NOT NULL,               -- COP | USD
amount BIGINT UNSIGNED NOT NULL,         -- en unidad mínima (centavos). COP sin decimales: amount = pesos * 100 igualmente, normalizado
interval ENUM('one_time','month','year') NOT NULL,
trial_days SMALLINT UNSIGNED DEFAULT 0,
gateway_refs JSON NULL,                  -- {"mercadopago_plan_id":"2c93...","paypal_plan_id":"P-XXX"}
status ENUM('active','archived') DEFAULT 'active',
INDEX idx_product (product_id)
```

### `impay_customers`
```sql
id, uuid,
wp_user_id BIGINT UNSIGNED NULL UNIQUE,  -- se crea/vincula usuario WP al comprar
email VARCHAR(190) NOT NULL UNIQUE,
full_name VARCHAR(190) NOT NULL,
company VARCHAR(190) NULL,
tax_id_type ENUM('CC','NIT','CE','PAS','RUT','OTRO') NULL,  -- datos fiscales Colombia/LATAM
tax_id VARCHAR(40) NULL,
country CHAR(2) DEFAULT 'CO',
phone VARCHAR(40) NULL,
gateway_refs JSON NULL,                  -- {"mercadopago_payer_id":"...","paypal_payer_id":"..."}
created_at, updated_at,
INDEX idx_email (email)
```

### `impay_orders`
Toda transacción de compra (única o primer cargo de suscripción, o renovación anual por link).
```sql
id, uuid,
customer_id FK, product_id FK, price_id FK,
subscription_id BIGINT NULL,             -- si pertenece a una suscripción
kind ENUM('purchase','renewal','subscription_initial') NOT NULL,
status ENUM('pending','paid','failed','refunded','expired','cancelled') NOT NULL DEFAULT 'pending',
currency CHAR(3), amount BIGINT UNSIGNED,
gateway VARCHAR(30) NOT NULL,            -- mercadopago | paypal
gateway_ref VARCHAR(120) NULL,           -- preference_id / order_id
gateway_payment_id VARCHAR(120) NULL,    -- payment id definitivo
external_reference CHAR(36) NOT NULL,    -- = uuid, viaja a la pasarela
paid_at DATETIME NULL,
meta JSON NULL,
created_at, updated_at,
UNIQUE idx_extref (external_reference),
INDEX idx_customer (customer_id), INDEX idx_status (status),
INDEX idx_gateway_payment (gateway_payment_id)
```

### `impay_subscriptions`
```sql
id, uuid,
customer_id FK, product_id FK, price_id FK,
gateway VARCHAR(30) NOT NULL,
gateway_sub_id VARCHAR(120) NULL,        -- preapproval_id (MP) | subscription id I-XXX (PayPal)
status ENUM('pending','active','past_due','paused','cancelled','expired') NOT NULL,
current_period_start DATETIME NULL,
current_period_end DATETIME NULL,        -- para annual_hybrid: fecha de vencimiento del servicio
cancel_at_period_end TINYINT(1) DEFAULT 0,
cancelled_at DATETIME NULL,
failed_payments TINYINT UNSIGNED DEFAULT 0,
meta JSON NULL,
created_at, updated_at,
UNIQUE idx_gateway_sub (gateway, gateway_sub_id),
INDEX idx_customer (customer_id), INDEX idx_status (status),
INDEX idx_period_end (current_period_end)
```

> **Nota:** los productos `annual_hybrid` también crean un registro aquí con `gateway_sub_id = NULL`. La "suscripción" es lógica (controla el vencimiento y las renovaciones por link), aunque el cobro no sea automático. Así el portal y la provisión tratan todo uniforme.

### `impay_payments`
Cada cobro individual (incluye renovaciones recurrentes que llegan por webhook).
```sql
id, uuid,
order_id BIGINT NULL, subscription_id BIGINT NULL, customer_id FK,
gateway VARCHAR(30), gateway_payment_id VARCHAR(120) NOT NULL,
status ENUM('approved','pending','rejected','refunded','charged_back') NOT NULL,
currency CHAR(3), amount BIGINT UNSIGNED,
method VARCHAR(60) NULL,                 -- visa, master, pse, nequi, account_money...
paid_at DATETIME NULL, raw JSON NULL, created_at,
UNIQUE idx_gw_payment (gateway, gateway_payment_id),
INDEX idx_subscription (subscription_id), INDEX idx_customer (customer_id)
```

### `impay_payment_links`
Links de renovación para `annual_hybrid` y cobros manuales.
```sql
id, uuid,
customer_id FK, subscription_id BIGINT NULL, price_id FK,
gateway VARCHAR(30), gateway_ref VARCHAR(120),   -- preference_id / order id
url VARCHAR(600) NOT NULL,
status ENUM('open','paid','expired','void') DEFAULT 'open',
expires_at DATETIME NULL, paid_order_id BIGINT NULL,
created_at, INDEX idx_subscription (subscription_id)
```

### `impay_webhook_events`
Idempotencia + auditoría.
```sql
id, gateway VARCHAR(30), event_id VARCHAR(160) NOT NULL,  -- id del evento o hash determinista
topic VARCHAR(80), payload JSON,
status ENUM('received','processed','skipped','failed') DEFAULT 'received',
error TEXT NULL, attempts TINYINT DEFAULT 0,
received_at DATETIME, processed_at DATETIME NULL,
UNIQUE idx_event (gateway, event_id), INDEX idx_status (status)
```

### `impay_logs`
Log estructurado propio (nivel, contexto JSON, canal). Retención 90 días (job de limpieza). Nada de `error_log` disperso.

---

## 6. Máquina de estados de suscripción

```
                 ┌──────────┐
   checkout ───▶ │ pending  │──(webhook authorized/activated)──▶ ┌────────┐
                 └──────────┘                                     │ active │
                       │ (expira sin autorizar, 48h)              └───┬────┘
                       ▼                                    ┌─────────┼──────────────┐
                  cancelled                     pago fallido│         │usuario/admin │fin de periodo
                                                            ▼         ▼              ▼ (cancel_at_period_end)
                                                      ┌──────────┐ ┌───────────┐ ┌─────────┐
                                                      │ past_due │ │ cancelled │ │ expired │
                                                      └────┬─────┘ └───────────┘ └─────────┘
                                             pago ok ◀─────┤
                                             (→active)     │ 3 fallos o pasarela cancela
                                                           ▼
                                                       cancelled
```

**Reglas:**
- Toda transición pasa por `SubscriptionStateMachine::transition($sub, $to, $context)`. Transiciones inválidas lanzan excepción y quedan en `impay_logs`.
- Cada transición dispara `do_action("impay_subscription_{$to}", $subscription, $context)` → aquí se cuelga la provisión (activar VPS, generar licencia en Imagina Updater, suspender servicio, etc.).
- `past_due`: la pasarela reintenta por su cuenta; el plugin solo notifica al cliente (email día 0, día 3, día 7) y suspende provisión en día 7 si sigue impago (`do_action('impay_service_suspend')`). Nunca reintenta cobros por su cuenta.
- `annual_hybrid`: job diario detecta `current_period_end` a 30/15/5/0 días → genera `payment_link` (si no existe abierto) → email con CTA. Al pagarse el link: `current_period_end += 1 año`, order `kind=renewal`, email de confirmación. A los +7 días vencido sin pago → `expired` + suspensión.

---

## 7. Capa de pasarelas

### Interface
```php
interface GatewayInterface {
    public function id(): string; // 'mercadopago' | 'paypal'
    public function createOneTimeCheckout(Order $order): CheckoutSession;      // devuelve URL de redirección
    public function createSubscription(Subscription $sub, Price $price): CheckoutSession;
    public function cancelSubscription(Subscription $sub): void;
    public function pauseSubscription(Subscription $sub): void;                 // solo MP
    public function resumeSubscription(Subscription $sub): void;
    public function verifyWebhook(WP_REST_Request $req): WebhookEvent;          // valida firma, lanza si inválida
    public function handleWebhook(WebhookEvent $event): void;                   // traduce a eventos de dominio
    public function fetchSubscription(string $gatewaySubId): array;             // para reconciliación
    public function fetchPayment(string $gatewayPaymentId): array;
    public function createPaymentLink(PaymentLinkRequest $req): PaymentLink;
    public function supports(string $feature): bool;  // 'pause', 'pse', 'trial', 'nequi_recurring', ...
    public function mode(): GatewayMode;               // enum: HostedSubscription | Tokenized
}
```

### Modos de gateway (clave para el diseño multi-pasarela)

El core distingue dos modos de operación y **todo el flujo de suscripciones se ramifica según el modo**, nunca según el nombre de la pasarela:

| Modo | Quién ejecuta el cobro recurrente | Pasarelas | Qué hace el plugin |
|---|---|---|---|
| `HostedSubscription` | La pasarela (motor propio: crea plan/preapproval, cobra, reintenta) | Mercado Pago, PayPal, ePayco (v2) | Solo orquesta estado vía webhooks |
| `Tokenized` | **El plugin** (la pasarela solo guarda el token del medio de pago) | Wompi (v2) | Agenda y dispara cada cobro con el `BillingEngine` interno |

**`BillingEngine` (solo se activa si hay algún gateway `Tokenized` configurado):**
- Job diario `impay_billing_run`: busca suscripciones `active` de gateways tokenized con `current_period_end <= now()` → crea transacción con el token (`payment_source_id`) → registra en `impay_payments` → extiende periodo si aprueba.
- Reintentos propios: fallo → reintento a las 24h y 72h (máx. 3) → luego `past_due` y entra al dunning normal de la sección 6. Todo cobro saliente lleva idempotency key derivada de `{subscription_uuid}:{period_end}` para que un job duplicado jamás cobre dos veces.
- Tabla adicional `impay_payment_sources`: `id, customer_id, gateway, gateway_source_id, type (CARD|NEQUI), brand, last_four, status, expires_at`. Solo se crea/usa con gateways tokenized.
- El resto del sistema (estados, portal, emails, provisión) no cambia: `BillingEngine` produce los mismos eventos de dominio que un webhook de MP.

Esta separación se implementa desde la Fase 1 (el enum, la interface y el branching en `SubscriptionService`), aunque el `BillingEngine` como tal no se construya hasta la Fase 8. Así añadir Wompi no toca el core.

### Mercado Pago (`MercadoPagoGateway`)

**Credenciales:** Access Token de producción + test, Public Key, **secret de webhooks** (para firma `x-signature`). País de la cuenta: Colombia (MCO). Moneda de cobro: **COP** (MP cobra en la moneda del país de la cuenta — el precio USD se muestra referencialmente y se convierte con tasa configurable o manual por precio; documentar esto en el admin).

**Pago único (Checkout Pro):**
- `POST /checkout/preferences` con `items`, `external_reference` (uuid del order), `back_urls` (success/pending/failure → `/gracias?order={uuid}`), `auto_return: approved`, `notification_url` → webhook propio, `statement_descriptor: 'IMAGINAWP'`.
- Métodos habilitados: tarjeta, **PSE, Nequi**, saldo MP (configurable con `payment_methods.excluded_payment_types`).
- Redirección a `init_point`.

**Suscripción (Preapproval):**
- Opción A (usada): `POST /preapproval` sin plan, con `auto_recurring: {frequency:1, frequency_type:'months', transaction_amount, currency_id:'COP'}`, `back_url`, `external_reference`, `payer_email`. Redirigir al `init_point` para que el cliente autorice con tarjeta.
- Estados MP: `pending → authorized → paused/cancelled`. Mapeo: `authorized→active`, `paused→paused`, `cancelled→cancelled`.
- **Solo tarjeta** soporta recurrencia. El checkout debe comunicarlo claramente.
- Cancelar/pausar: `PUT /preapproval/{id}` con `status`.

**Webhooks MP** (`POST /wp-json/impay/v1/webhooks/mercadopago`):
- Topics a suscribir en el panel de MP: `payment`, `subscription_preapproval`, `subscription_authorized_payment`.
- **Verificación de firma obligatoria**: header `x-signature` (`ts=...,v1=...`) + `x-request-id`. Manifest: `id:{data.id};request-id:{x-request-id};ts:{ts};` → HMAC-SHA256 con el secret → comparar con `v1` usando `hash_equals`. Rechazar con 401 si falla o si `ts` tiene más de 5 minutos.
- El webhook de MP trae solo IDs → **siempre** hacer fetch a la API (`GET /v1/payments/{id}`, `GET /preapproval/{id}`) antes de procesar. Nunca confiar en el payload.
- Responder **200 inmediato** tras validar firma y encolar; el procesamiento pesado va a Action Scheduler (patrón: persistir en `impay_webhook_events` → `as_enqueue_async_action('impay_process_webhook', [$eventId])`).
- Idempotencia: `UNIQUE (gateway, event_id)`; si ya existe `processed`, responder 200 y salir.
- Renovaciones recurrentes llegan como `subscription_authorized_payment` / `payment` → crear registro en `impay_payments`, extender `current_period_end`, resetear `failed_payments`.
- Header `X-Idempotency-Key` en todos los POST salientes a MP.

### PayPal (`PayPalGateway`)

**Credenciales:** Client ID + Secret (live y sandbox), Webhook ID (para verificación). Moneda: **USD** (clientes internacionales: México, Venezuela, resto).

**Pago único:** Orders v2 (`POST /v2/checkout/orders`, intent `CAPTURE`, `custom_id = external_reference`) → approve link → capture en el retorno o vía webhook `CHECKOUT.ORDER.APPROVED` + `PAYMENT.CAPTURE.COMPLETED`.

**Suscripción:** Catalog Products + Billing Plans (`POST /v1/billing/plans`) creados perezosamente al guardar el precio (guardar `paypal_plan_id` en `gateway_refs`), luego `POST /v1/billing/subscriptions` con `custom_id` → approve link.
- Webhooks: `BILLING.SUBSCRIPTION.ACTIVATED`, `.CANCELLED`, `.SUSPENDED`, `.PAYMENT.FAILED`, `PAYMENT.SALE.COMPLETED` (renovaciones).
- Verificación: `POST /v1/notifications/verify-webhook-signature` con headers (`paypal-transmission-id`, `-time`, `-sig`, `cert-url`, `auth-algo`) + webhook_id. `verification_status === 'SUCCESS'` o 401.
- OAuth token cacheado en transient (expira ~9h, renovar a los 8h).

### Pasarelas v2 (diseñadas aquí, NO implementar en v1)

**Wompi (`WompiGateway`, modo Tokenized) — prioridad v2.**
Pasarela de Bancolombia. Razón de negocio: es la única que permite cobros recurrentes con **Nequi además de tarjeta** (diferenciador de conversión enorme en Colombia), y liquida a cuenta Bancolombia en 24–48h.
- Flujo de alta de suscripción: tokenizar medio de pago (`POST /tokens/cards` desde el navegador con llave pública — el PAN nunca toca el servidor — o token Nequi) → `POST /payment_sources` con `acceptance_token` (términos Wompi) y llave privada → guardar en `impay_payment_sources` → primer cobro inmediato vía `POST /transactions` con `payment_source_id` → suscripción `active`.
- Tarjetas requieren autenticación 3DS en la transacción inicial para habilitar recurrencia.
- Cobros siguientes: los ejecuta el `BillingEngine` (ver Modos de gateway). Las transacciones son asíncronas: crear → polling/webhook de evento (`transaction.updated`) hasta estado final `APPROVED|DECLINED|ERROR`.
- Webhook de eventos firmado (checksum SHA-256 con secret de eventos) — misma política: verificar, encolar, idempotencia.
- El checkout debe renderizar el widget/campos de tokenización de Wompi (frontend propio, llave pública), a diferencia de MP/PayPal que son redirect.

**ePayco (`EpaycoGateway`, modo HostedSubscription) — baja prioridad, evaluar solo con razón fuerte.**
Agregador colombiano con producto de suscripciones activo (API REST: crear plan → tokenizar → asociar suscripción → ePayco cobra automáticamente). Soporta definir moneda del cobro incluyendo USD (liquida en COP). **Advertencia comercial:** el producto Suscripciones tiene precio no público, pactado por "anexo de condiciones comerciales" con asesor, y ePayco puede modificar tarifas unilateralmente con 15 días de aviso — costo adicional sobre la comisión transaccional (~2.7–3% + $900 agregador). Implementar solo si en la práctica ofrece tasas de aprobación de tarjetas claramente superiores a MP que justifiquen ese costo; medir antes de integrar.

**Descartadas (decisión de negocio, no reabrír sin nueva razón):**
- **dLocal Go**: exige que el pagador tenga cuenta dLocal Go para suscribirse (fricción alta) y comisiones cross-border elevadas. Para clientes fuera de Colombia se usa PayPal.

### Reconciliación (red de seguridad)
Job diario (`impay_reconcile`): para toda suscripción `active|past_due|pending` con `gateway_sub_id`, hacer fetch a la pasarela y corregir divergencias (webhook perdido). Log de cada corrección. También: orders `pending` > 48h → `expired`.

---

## 8. Flujos de negocio

### 8.1 Checkout (página `/checkout/{product-slug}`)
1. Página React ligera (entry `checkout`, < 90 KB gz). SSR mínimo: PHP imprime JSON del producto en `<script type="application/json">` para render instantáneo sin fetch.
2. Layout de 2 columnas (desktop) / apilado (móvil): izquierda resumen del producto (nombre, features, precio, intervalo, badge "Pago seguro"), derecha formulario.
3. Formulario (RHF + Zod): nombre completo, email, empresa (opcional), tipo y número de documento (CC/NIT/CE — para factura), país, teléfono (opcional). Selector de **método**: Mercado Pago (COP — tarjeta/PSE/Nequi si es pago único; solo tarjeta si es suscripción, con nota explicativa) o PayPal (USD).
4. `POST /impay/v1/checkout` → crea/actualiza customer, crea order (y subscription si aplica), llama al gateway → responde `redirect_url`.
5. Redirect a la pasarela. Sin iframes, sin campos de tarjeta propios (cero PCI).
6. Anti-abuso: honeypot + rate limit por IP (transient, 10 req/10 min) + nonce.

### 8.2 Página `/gracias`
- Recibe `?order={uuid}`. Polling a `GET /impay/v1/orders/{uuid}/status` cada 3 s (máx. 2 min).
- Estados visuales: `pending` (spinner "Confirmando tu pago…"), `paid` (check animado + resumen + CTA "Ir a mi cuenta" + "acabamos de enviarte un email"), `failed` (mensaje amable + botón reintentar → regenera checkout).
- Endpoint público pero solo devuelve `{status, product_name}` — nada sensible.

### 8.3 Suscripción mensual (VPS, licencias)
checkout → `pending` → webhook `authorized/ACTIVATED` → `active` → `do_action('impay_subscription_active')` → provisión → email de bienvenida con accesos → cada mes webhook de pago → extender periodo → email de recibo.

### 8.4 Anual híbrido (hosting)
checkout como **pago único** (acepta PSE/Nequi) → order `paid` → se crea subscription lógica `active` con `current_period_end = +1 año` → provisión → jobs de renovación (sección 6) → link de pago Checkout Pro/PayPal Order → pagado → +1 año.

### 8.5 Cancelación
- Cliente desde el portal: modal de confirmación → por defecto `cancel_at_period_end = true` (sigue activo hasta el fin del periodo; en MP la cancelación del preapproval es inmediata para cobros futuros, lo cual es equivalente: no se cobra más y el servicio sigue hasta `current_period_end`). Encuesta opcional de 1 pregunta (motivo).
- Admin: cancelar inmediato o a fin de periodo, pausar/reanudar (MP).

### 8.6 Provisión
`provisioning` del producto define la acción al activar:
- `updater_license`: llamar API de Imagina Updater (`POST {updater_url}/wp-json/.../licenses` con API key configurada) → guardar license key en `meta` de la suscripción → incluirla en el email y en el portal. Al suspender/cancelar → desactivar licencia.
- `hook`: solo dispara `do_action('impay_provision', $subscription, $product)` — para VPS/hosting el equipo automatiza aparte o actúa manual con notificación admin (email + panel).
- `manual`: crea "tarea pendiente" visible en el dashboard admin.

---

## 9. REST API (`impay/v1`)

**Públicos (rate-limited):**
```
POST /checkout                      → crea order/sub y devuelve redirect_url
GET  /orders/{uuid}/status          → {status, product_name}
POST /webhooks/mercadopago
POST /webhooks/paypal
```

**Portal cliente (auth: usuario WP logueado, `impay_customer` capability o vínculo wp_user_id):**
```
GET  /me                            → perfil + datos fiscales
PUT  /me
GET  /me/subscriptions              → con producto, estado, periodo, licencia
POST /me/subscriptions/{uuid}/cancel
GET  /me/payments                   → historial paginado
GET  /me/payments/{uuid}/receipt    → HTML imprimible del recibo
GET  /me/payment-links              → links de renovación abiertos
```

**Admin (capability `manage_impay`, creada en activación y asignada a administrator):**
```
CRUD /admin/products, /admin/prices
GET  /admin/subscriptions (+filtros TanStack: estado, gateway, producto, búsqueda)
POST /admin/subscriptions/{uuid}/cancel|pause|resume|extend
GET  /admin/customers, GET /admin/customers/{uuid} (ficha 360)
GET  /admin/orders, /admin/payments
POST /admin/payment-links           → cobro manual a un cliente
GET  /admin/webhook-events (+retry) 
GET  /admin/dashboard/metrics       → MRR, activos, churn 30d, ingresos mes, próximas renovaciones
GET/PUT /admin/settings
GET  /admin/export/payments.csv     → export contable (para facturación DIAN externa: Siigo/Alegra)
```
Nonce + cookie auth de WP para los SPAs. Validación de entrada con esquemas centralizados; nunca `$_POST` directo.

---

## 10. Admin SPA (wp-admin → "Imagina Pay")

Página full-screen (oculta admin bar y menú WP dentro de la vista, como Imagina Reports): sidebar propia, `#impay-root`.

**Páginas:**
1. **Dashboard** — 4 stat cards (MRR estimado, suscripciones activas, ingresos del mes, pagos fallidos/past_due), gráfico de ingresos 12 meses (Recharts, línea suave), tabla "Próximas renovaciones (30 días)", feed de actividad reciente (últimos eventos), tareas de provisión manual pendientes.
2. **Productos** — grid de cards con estado, precios asociados, botón nuevo producto → drawer lateral con formulario (tabs: General / Precios / Provisión). Los precios se crean inline con preview del texto que verá el cliente ("$ 49.900 COP / mes").
3. **Suscripciones** — TanStack Table: cliente, producto, estado (badge con color semántico), gateway (logo mini), periodo actual, MRR. Filtros arriba, búsqueda. Click → drawer de detalle: timeline de eventos, pagos, acciones (cancelar, pausar, extender periodo, reenviar email, copiar license key).
4. **Clientes** — tabla + ficha 360: datos fiscales, suscripciones, pagos, LTV, botón "crear cobro" (payment link con selección de precio o monto libre).
5. **Pagos** — tabla con export CSV, filtro por fecha/gateway/estado, total del rango visible.
6. **Webhooks & Logs** — tabla de `webhook_events` con payload colapsable (JSON viewer), botón retry, indicador de salud (último evento recibido por gateway); tab de logs.
7. **Ajustes** — tabs: Mercado Pago (credenciales test/live, toggle sandbox, estado de conexión con test call, secret de webhook, URL a registrar copiable), PayPal (ídem), Emails (remitente, logo, color, textos editables por plantilla con variables `{{customer_name}}`), Páginas (selector de páginas WP para checkout/gracias/portal + creación automática), Avanzado (retención de logs, tasa COP/USD referencial, Imagina Updater API URL + key).

---

## 11. Portal Cliente (`/mi-cuenta`)

SPA React montado por shortcode `[impay_portal]` en la página elegida. Requiere login WP (si no, muestra login propio estilizado con `wp_signon` vía endpoint — o link mágico por email en v1.1). El usuario WP se crea automáticamente en la primera compra (rol `impay_customer`, sin acceso a wp-admin) y recibe email de establecer contraseña.

**Vistas (sidebar izquierda / tabs en móvil):**
1. **Mis servicios** — cards por suscripción: producto, badge de estado, "Se renueva el {fecha}" o "Vence el {fecha}", precio, license key con botón copiar (si aplica), CTA de renovar si hay payment link abierto (destacado con banda ámbar), acciones (cancelar).
2. **Pagos** — historial con recibo imprimible (ventana HTML limpia con logo, datos fiscales del cliente y de Imagina, detalle — sirve como soporte contable; la factura DIAN formal se emite fuera).
3. **Mi perfil** — datos personales y fiscales editables.
4. Header con logo de la agencia, saludo por nombre y botón de soporte (mailto/WhatsApp configurable).

Empty states ilustrados y amables. Todo en español.

---

## 12. Emails transaccionales

Plantilla HTML propia única (600px, compatible clientes de correo, logo + color de marca configurables), enviados con `wp_mail` a través de un `Mailer` service (hook para SMTP externo). Cada envío se registra en logs.

| Email | Trigger |
|---|---|
| Bienvenida + accesos/licencia | subscription `active` (primera vez) |
| Recibo de pago | payment `approved` |
| Pago fallido (día 0 / 3 / 7) | `past_due` + jobs |
| Recordatorio renovación anual (30/15/5/0) | jobs `annual_hybrid` |
| Renovación confirmada | payment link pagado |
| Suscripción cancelada / servicio suspendido | transiciones |
| Establecer contraseña | creación de usuario |
| **Admin:** venta nueva, pago fallido, tarea de provisión manual | eventos |

---

## 13. Jobs (Action Scheduler)

| Hook | Frecuencia | Función |
|---|---|---|
| `impay_process_webhook` | async | procesar evento encolado |
| `impay_reconcile` | diario 03:00 | reconciliación vs. pasarelas |
| `impay_renewal_reminders` | diario 08:00 | anuales: generar links + emails 30/15/5/0 |
| `impay_dunning_notices` | diario 09:00 | past_due día 3/7 + suspensión día 7 |
| `impay_expire_stale` | diario | orders pending>48h, links vencidos, subs vencidas +7d |
| `impay_cleanup` | semanal | logs>90d, webhook_events>180d |

---

## 14. Seguridad

- Firmas de webhook verificadas SIEMPRE (MP HMAC + ventana de 5 min; PayPal verify-webhook-signature). 401 si falla, log de intento.
- Credenciales cifradas at-rest en `wp_options` (AES-256-GCM con clave derivada de `AUTH_KEY` vía `hash_hkdf`); nunca en logs ni respuestas REST (mostrar solo últimos 4 chars).
- Idempotencia en entrada (unique event) y salida (X-Idempotency-Key).
- Todos los montos en enteros (unidad mínima). Nunca floats en dinero.
- Nonces + capabilities en todo endpoint; el portal solo accede a recursos donde `customer.wp_user_id === get_current_user_id()`.
- `$wpdb->prepare` en el 100% de queries; PHPStan level 8 lo vigila.
- Sin datos de tarjeta jamás en el servidor.

---

## 15. Diseño UI (guía obligatoria)

Estética **Linear/Vercel/Stripe Dashboard**: precisa, aireada, sin ruido.

- **Tipografía:** Inter (400/500/600). Títulos 600 tracking-tight; datos numéricos con `tabular-nums`.
- **Paleta:** fondo `#FAFAFA` (admin) / blanco (portal y checkout), superficie blanca con borde `#E4E4E7` y sombra sutilísima (`0 1px 2px rgb(0 0 0 / .04)`), texto `#18181B` / secundario `#71717A`. Acento configurable (default índigo `#4F46E5`). Estados: éxito `#059669`, ámbar `#D97706`, error `#DC2626` — usados en badges suaves (fondo 10%, texto 100%).
- **Componentes:** shadcn/ui como base; radios 10–12px; inputs altos (h-11) en checkout; botones primarios sólidos con hover -5% luminosidad; skeletons en toda carga; toasts (sonner-style) para feedback.
- **Motion:** Framer Motion discreto — fade+slide 8px en drawers y cards (150–200 ms), check de éxito con spring en /gracias. Nada de animaciones gratuitas.
- **Checkout:** una sola pantalla, confianza visual (candado, logos MP/PayPal, "No almacenamos datos de tu tarjeta"), formato de moneda colombiano (`$ 49.900 COP`) con `Intl.NumberFormat('es-CO')`.
- Dark mode: no en v1 (mantener alcance).

---

## 16. Estructura de carpetas

```
imagina-pay/
├── imagina-pay.php              # bootstrap: requisitos, autoload, container, hooks mínimos
├── composer.json                # psr-4, action-scheduler, phpstan, phpcs
├── src/
│   ├── Core/                    # Plugin, Container, Activator (dbDelta, caps, páginas), Router
│   ├── Http/                    # HttpClient, IdempotencyKey
│   ├── Rest/                    # Controllers por recurso + Middleware (auth, rate-limit, validación)
│   ├── Domain/
│   │   ├── Entities/            # Product, Price, Customer, Order, Subscription, Payment (readonly DTOs)
│   │   ├── Repositories/        # *Repository (wpdb)
│   │   ├── Services/            # CheckoutService, SubscriptionService, ProvisioningService,
│   │   │                        #   RenewalService, DunningService, ReconciliationService, MetricsService
│   │   └── StateMachine/
│   ├── Gateways/                # GatewayInterface, GatewayRegistry, MercadoPago/, PayPal/
│   ├── Webhooks/                # WebhookController, EventStore, Processor
│   ├── Integrations/            # ImaginaUpdaterClient
│   ├── Mail/                    # Mailer, plantillas
│   ├── Jobs/                    # clases de cada job + Scheduler (registro AS)
│   └── Support/                 # Money, Uuid, Crypto, Logger, Clock
├── frontend/
│   ├── vite.config.ts           # entries: admin, portal, checkout (manifest → PHP enqueue)
│   ├── src/admin/  src/portal/  src/checkout/
│   └── src/shared/              # ui (shadcn), api client (fetch+nonce), hooks, lib/format.ts
├── templates/                   # PHP mínimos que montan cada SPA + JSON inicial
├── languages/
└── tests/                       # PHPUnit + Brain Monkey: StateMachine, firmas webhook, Money, gateways (HTTP mockeado)
```

---

## 17. Roadmap por fases (para Claude Code)

**Fase 1 — Fundaciones (backend core):** bootstrap, Activator (tablas, caps, páginas), Container, entidades/repos, Money/Uuid/Crypto/Logger, REST base con middleware, ajustes cifrados, tests de StateMachine y Money. ✔️ Criterio: activar plugin crea todo sin errores; PHPStan 8 en verde.

**Fase 2 — Mercado Pago end-to-end:** MercadoPagoGateway completo (checkout pro + preapproval + firma webhook + fetchers), CheckoutService, webhooks encolados + processor, /gracias polling endpoint, sandbox probado con cuentas de test. ✔️ Criterio: compra única y suscripción de prueba activan estado correctamente vía webhook.

**Fase 3 — PayPal + suscripciones lógicas:** PayPalGateway, annual_hybrid, RenewalService + payment links, DunningService, ReconciliationService, jobs completos.

**Fase 4 — Emails + provisión:** Mailer + 10 plantillas, ProvisioningService, ImaginaUpdaterClient, hooks públicos documentados.

**Fase 5 — Admin SPA:** las 7 páginas, empezando por Ajustes (necesario para operar) → Productos → Suscripciones → Dashboard → resto.

**Fase 6 — Checkout + Portal:** página de checkout, /gracias, portal completo, creación de usuarios, recibos.

**Fase 7 — Pulido:** performance audit (presupuesto sección 1), accesibilidad básica (focus, labels, contraste), textos, export CSV, QA end-to-end en sandbox de ambas pasarelas, README de despliegue.

**Fase 8 (v2) — Wompi + BillingEngine:** tabla `impay_payment_sources`, tokenización en checkout (widget Wompi, 3DS inicial), `WompiGateway`, `BillingEngine` con job `impay_billing_run`, reintentos 24h/72h e idempotencia por periodo, webhook de eventos Wompi, opción "Nequi recurrente" en el checkout, vista de medios de pago guardados en el portal cliente. ✔️ Criterio: suscripción sandbox Wompi cobra dos periodos consecutivos sin intervención y un job duplicado no genera doble cobro.

Mantener `PROGRESS.md` en la raíz: al terminar cada sesión, registrar qué se hizo, decisiones tomadas y siguiente paso. Leerlo al iniciar cada sesión.

---

## 18. Convenciones de código

- `declare(strict_types=1)` + tipos en todo (params, returns, properties). `readonly` donde aplique. Enums PHP 8.1 para estados.
- Nada de lógica en hooks de WP: los hooks solo delegan a services del container.
- Errores: excepciones de dominio (`GatewayException`, `InvalidTransitionException`…) capturadas en el borde REST → respuesta JSON `{code, message}` + log.
- Frontend: componentes < 200 líneas, hooks de datos separados (`useSubscriptions()`), Zod schemas espejo de la API en `shared/schemas.ts`, cero `any`.
- Commits convencionales (`feat:`, `fix:`…), un feature por commit.
- Todo texto visible al usuario en **español** (es_CO).

---

## 19. Fuera de alcance v1 (no implementar)

- Facturación electrónica DIAN nativa (solo export CSV + recibo HTML; integración Siigo/Alegra en v2)
- Cupones/descuentos, impuestos automáticos, multi-vendedor, carrito multi-producto (checkout es de 1 producto), dark mode, cambio de plan con prorrateo (upgrade = cancelar + comprar en v1)
- Wompi y `BillingEngine` (Fase 8/v2 — pero el enum `GatewayMode` y el branching por modo SÍ van desde Fase 1), ePayco (evaluar en v2), dLocal Go (descartada — ver sección 7)

---

## 20. Prompt de kickoff para Claude Code

> Lee completo este CLAUDE.md y crea PROGRESS.md. Estamos construyendo **Imagina Pay**, un plugin WordPress de venta de productos digitales y suscripciones con Mercado Pago y PayPal, sin WooCommerce. Comienza por la **Fase 1** del roadmap (sección 17): estructura del plugin, composer, Activator con las tablas de la sección 5, container DI, entidades, repositorios, StateMachine (sección 6) y utilidades de Support, con sus tests. No avances a la Fase 2 hasta que PHPStan level 8 pase y los tests de StateMachine y Money estén en verde. Respeta estrictamente las convenciones (sección 18), los principios (sección 1) y el alcance excluido (sección 19). Ante cualquier ambigüedad, decide lo más simple que cumpla el spec y anótalo en PROGRESS.md.
