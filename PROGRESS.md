# PROGRESS.md — Imagina Pay

> Bitácora de sesiones. Leer al iniciar cada sesión, actualizar al terminar.

---

## Estado actual

- **Fase actual:** Fase 4 — Emails + provisión → **COMPLETADA (código + tests) ✅**
- **Siguiente paso:** Fase 5 — Admin SPA (React), empezando por Ajustes → Productos → Suscripciones → Dashboard → resto
- **Gates de calidad:** PHPStan level 8 en verde (0 errores) · PHPUnit 157 tests / 501 aserciones en verde · PHPCS en verde

---

## Sesión 2026-07-03 (continuación 3) — Fase 4 completa

### Tareas completadas

1. **EmailTemplate** (`src/Mail/`): plantilla HTML única de 600px con estilos inline (compatible clientes de correo), logo y color de marca desde ajustes (color validado contra `#RRGGBB`, fallback índigo), CTA opcional, pie automático.
2. **Mailer**: envío sobre `wp_mail` (un plugin SMTP se engancha ahí sin tocar código), header From configurable, `sendToAdmin` a `admin_email`, cada envío/fallo registrado en `impay_logs`.
3. **EmailNotifications** — las plantillas de la sección 12 colgadas de los hooks de dominio:
   - Bienvenida + licencia (solo primera activación, flag `welcome_sent` en meta; relee la sub para encontrar la licencia recién provisionada)
   - Recibo de pago (`impay_payment_approved`, nuevo hook en PaymentService, un recibo por pago aprobado)
   - Pago fallido día 0/3/7 (`impay_dunning_notice`; el día 0 también notifica al admin)
   - Recordatorio de renovación 30/15/5/0 (`impay_renewal_reminder`, CTA = URL del link de pago)
   - Renovación confirmada (`impay_renewal_paid`)
   - Cancelada y servicio suspendido (transiciones/suspensión)
   - Establecer contraseña (método público `passwordSetup`, se invoca en Fase 6 al crear el usuario WP)
   - Admin: venta nueva (`impay_order_paid`) y tarea de provisión manual (`impay_manual_task`)
4. **ProvisioningService**: según `provisioning.type` del producto — `updater_license` (crea licencia vía ImaginaUpdaterClient, guarda `license_key` en meta, reactiva si ya existía; si la API falla cae a tarea manual), `hook` (`do_action('impay_provision')`), `manual` (tarea pendiente en meta + log + aviso admin). `suspend()` desactiva la licencia (colgado de `impay_service_suspend`, `cancelled` y `expired`).
5. **ImaginaUpdaterClient**: POST `/wp-json/imagina-updater/v1/licenses` (+ `/activate`, `/deactivate`) con header `X-Api-Key` (guardada cifrada).
6. **Hooks** (`src/Core/Hooks.php`): listeners internos registrados con prioridades — provisión 10, emails 20 (el welcome ya encuentra la licencia). `docs/HOOKS.md` documenta todos los hooks públicos con ejemplos.
7. **Tests nuevos (23)**: Mailer/plantilla (branding, headers, color inválido), ProvisioningService (licencia nueva/reactivación/fallback manual/hook/manual/suspensión), ImaginaUpdaterClient (payloads, API key, errores), EmailNotifications (welcome única con licencia, recibo formateado es-CO, dunning día 0 vs 7, CTA de renovación).

### Decisiones tomadas (Fase 4)

| # | Decisión | Razón |
|---|---|---|
| 33 | Nuevo hook `impay_payment_approved(GatewayPayment, int $customerId)` disparado en PaymentService | El recibo aplica a pagos únicos Y renovaciones; el upsert deduplica reintentos |
| 34 | Bienvenida una sola vez vía flag `welcome_sent` en meta | "subscription active (primera vez)" del spec; las reactivaciones no reenvían bienvenida |
| 35 | Prioridades: provisión 10, emails 20 en los mismos hooks | El correo de bienvenida debe incluir la licencia recién creada |
| 36 | Tareas manuales en `meta.manual_task` + log canal `provisioning` + email admin (sin tabla nueva) | Volumen bajo (10-20 productos); el dashboard (Fase 5) las lista desde ahí |
| 37 | Contrato del API de Imagina Updater asumido (`/licenses`, `/activate`, `/deactivate`, header X-Api-Key) | El spec no lo define; encapsulado en el cliente, ajustar en un solo archivo |
| 38 | Si falla la creación de licencia, la activación NO se revierte: se crea tarea manual + email admin | El cliente ya pagó; el equipo resuelve la licencia a mano |
| 39 | "Textos editables por plantilla" del admin (sección 10) se difiere a Fase 5 (Ajustes) | Los textos viven en EmailNotifications; el editor de plantillas es UI de Ajustes |

### Pendientes acumulados

- Sandbox MP y PayPal (checklist al desplegar, con credenciales).
- `passwordSetup` se conecta a la creación de usuarios WP en Fase 6.
- Editor de textos de emails (variables `{{customer_name}}`) en Ajustes, Fase 5.

### Siguiente paso (Fase 5 — Admin SPA)

1. Scaffolding frontend: Vite multi-entry (admin/portal/checkout), Tailwind prefijo `impay-`, shadcn/ui, TanStack Query.
2. Endpoints admin REST que faltan: CRUD productos/precios, listados (subscriptions/customers/orders/payments), acciones de suscripción, dashboard metrics, webhook events + retry, export CSV.
3. Página full-screen en wp-admin (`#impay-root`) con las 7 vistas, empezando por **Ajustes**.

---

## Sesión 2026-07-03 (continuación 2) — Fase 3 completa

### Tareas completadas

1. **PayPalClient**: OAuth client_credentials con token cacheado en transient (TTL = min(expires_in−5min, 8h), clave separada live/sandbox), base URL por modo, `PayPal-Request-Id` como idempotencia en POST, `verify-webhook-signature` oficial.
2. **PayPalGateway**: Orders v2 (intent CAPTURE, `custom_id` = external_reference, `invoice_id` único, return a /gracias), Billing Plans creados **perezosamente** al primer uso (Catalog Product + Plan → `gateway_refs.paypal_plan_id` persistido en el precio), Billing Subscriptions con `custom_id` = uuid de la sub, cancel vía `/cancel`, links de pago vía Orders v2. `supports('pause')` = false (spec: pausar es solo MP).
3. **PayPalWebhookHandler**: `CHECKOUT.ORDER.APPROVED` → captura inmediata (idempotente, tolera ORDER_ALREADY_CAPTURED); `PAYMENT.CAPTURE.COMPLETED/REFUNDED` → pago de order o link (por custom_id); `PAYMENT.SALE.COMPLETED` → renovación (por billing_agreement_id); `BILLING.SUBSCRIPTION.ACTIVATED/CANCELLED/SUSPENDED/EXPIRED` → transiciones; `.PAYMENT.FAILED` → `PaymentService::applyChargeFailure`.
4. **Suscripciones lógicas annual_hybrid**: al pagarse un order `purchase` de producto annual_hybrid (hook interno `impay_order_paid`), `RenewalService::handleOrderPaid` crea la suscripción lógica (`gateway_sub_id` NULL), fija periodo +1 año y transiciona pending→active (dispara provisión). Idempotente releyendo el order (subscription_id ya vinculado → no-op).
5. **RenewalService**: job diario de recordatorios — marcas 30/15/5/0 días con catch-up (la menor marca ≥ días restantes, así un job caído no salta avisos), dedupe por periodo en `meta.renewal_notices`, garantiza link de pago abierto (crea en la pasarela de la sub si no existe) y dispara `impay_renewal_reminder`. `applyPaidLink`: order `kind=renewal` + pago vía PaymentService, periodo +1 desde max(now, period_end), reactivación expired→active, link → paid.
6. **DunningService**: episodios por meta (`dunning.since` + `notices`), avisos día 0/3/7 con catch-up, suspensión (`impay_service_suspend`) en día 7. PaymentService limpia el episodio al aprobarse un cobro. Nunca reintenta cobros (eso es de la pasarela).
7. **ReconciliationService**: `reconcile()` coteja subs active/past_due/pending contra la API (mapeo de estados MP y PayPal normalizado) y corrige divergencias vía state machine con log; `expireStale()` expira orders pending >48h, links vencidos y anuales +7 días (→ expired + suspensión).
8. **MaintenanceService**: retención de logs (configurable, default 90d) y webhook_events (180d).
9. **Jobs (Scheduler)**: registro de los 6 hooks (`impay_process_webhook`, `impay_reconcile` 03:00, `impay_expire_stale` 04:00, `impay_renewal_reminders` 08:00, `impay_dunning_notices` 09:00, `impay_cleanup` semanal 02:00) con agendamiento idempotente en Action Scheduler (`as_has_scheduled_action` guard) y resolución perezosa del container.
10. **MercadoPagoWebhookHandler**: tercera rama en `payment` — external_reference = uuid de payment link → `applyPaidLink`.
11. **Tests nuevos (36)**: PayPalGateway (payloads Orders v2/plans/subscriptions, plan lazy vs reutilizado), PayPalWebhookHandler (8 rutas de eventos), RenewalService (sub lógica, link pagado, reactivación de expirada, marcas y dedupe), DunningService (día 0/3/7, catch-up, no repetición), ReconciliationService (divergencias, tolerancia a fallos, expire stale).

### Decisiones tomadas (Fase 3)

| # | Decisión | Razón |
|---|---|---|
| 25 | `RenewalService` recibe el Container y resuelve `GatewayRegistry` perezosamente | Los webhook handlers dependen de RenewalService y el registry depende de los handlers: ciclo de construcción |
| 26 | Renovación por link: el nuevo periodo corre desde `max(now, current_period_end)` (no `period_end + 1 año` literal del spec) | Consistente con PaymentService; el cliente no paga tiempo muerto si renueva tarde. |
| 27 | Recordatorios y avisos de dunning con catch-up (menor marca ≥ días restantes / todos los días alcanzados) | Un job caído un día no salta avisos |
| 28 | PayPal `supports('pause')` = false y pause/resume lanzan excepción | Spec sección 7: pausar es "solo MP" (aunque la API de PayPal soporte suspend) |
| 29 | Captura de PayPal en `CHECKOUT.ORDER.APPROVED` (webhook) además de esperar `PAYMENT.CAPTURE.COMPLETED` | Redundancia intencional del spec; idempotente vía PayPal-Request-Id y dedupe de payments |
| 30 | `BILLING.SUBSCRIPTION.EXPIRED` → expired (transición active→expired ya prevista) | Cobertura completa de estados PayPal |
| 31 | El evento de PayPal se procesa desde su payload verificado (sin re-fetch) | A diferencia de MP, la firma cubre el payload completo (verify-webhook-signature) |
| 32 | Horas de jobs en UTC (03/04/08/09) | Simplicidad; el spec no fija zona. Ajustable en Fase 7 si se quiere hora Bogotá |

### Pendientes acumulados

- Sandbox MP (Fase 2) y sandbox PayPal (Fase 3): compra única, suscripción, renovación por link y webhooks reales — requieren credenciales; checklist al desplegar.
- Emails de recordatorio/dunning/renovación: los hooks (`impay_renewal_reminder`, `impay_dunning_notice`, `impay_renewal_paid`, `impay_service_suspend`) ya disparan; el Mailer se cuelga en Fase 4.

### Siguiente paso (Fase 4 — Emails + provisión)

1. `Mailer` service sobre `wp_mail` con plantilla HTML única (600px, logo/color configurables) y registro en logs.
2. Las 10 plantillas transaccionales de la sección 12, colgadas de los hooks existentes.
3. `ProvisioningService` + `ImaginaUpdaterClient` (licencias) según `provisioning` del producto.
4. Hooks públicos documentados (`impay_subscription_active`, `impay_provision`, `impay_service_suspend`...).

---

## Sesión 2026-07-03 (continuación) — Fase 2 completa

### Tareas completadas

1. **Capa HTTP** (`src/Http/`): `HttpClient` sobre `wp_remote_request` con reintentos exponenciales (intento inicial + 3 reintentos, esperas 1s/4s/9s) ante error de red, 429 o 5xx; 4xx no se reintenta. Sleeper inyectable para tests. `HttpResponse` y `IdempotencyKey` (SHA-256 determinista por operación de dominio).
2. **MercadoPagoClient**: wrapper REST sin SDK (Bearer token, `X-Idempotency-Key` en POST, toggle sandbox → access token de test, errores de API → `GatewayException`).
3. **MercadoPagoWebhookVerifier**: firma `x-signature` (`ts=…,v1=…`), manifest `id:{data.id};request-id:{x-request-id};ts:{ts};`, HMAC-SHA256 + `hash_equals`, ventana de 5 minutos, tolera ts en ms.
4. **MercadoPagoGateway**: Checkout Pro (`POST /checkout/preferences` con external_reference, back_urls a /gracias?order={uuid}, auto_return, notification_url, statement_descriptor IMAGINAWP), Preapproval sin plan (`frequency_type: months`, anual = frequency 12), cancel/pause/resume (`PUT /preapproval`), fetchers de reconciliación, `createPaymentLink` vía preferencia (consumo del link pagado llega con RenewalService en Fase 3).
5. **MercadoPagoWebhookHandler**: siempre fetch a la API antes de procesar (nunca confiar en el payload). Topics: `payment` (order por external_reference, o suscripción si el extref es el uuid de la sub), `subscription_preapproval` (mapeo authorized→active, paused→paused, cancelled→cancelled), `subscription_authorized_payment` (dedupe con topic payment vía id del pago subyacente).
6. **PaymentService**: upsert idempotente por (gateway, gateway_payment_id) con actualización de estado (pending→approved); order paid nunca se degrada por webhooks tardíos; cobro de suscripción aprobado → extiende periodo desde max(now, period_end), resetea fallos, activa (pending/past_due→active) y marca paid el order inicial (vía meta `initial_order_uuid`); rechazado → incrementa fallos, active→past_due, 3er fallo en past_due→cancelled.
7. **CheckoutService**: valida producto/precio activos y correspondencia, chequea `supports('currency_XXX')` y `supports('recurring')` del gateway, upsert de customer por email, crea order (kind purchase | subscription_initial) y subscription pending si aplica, guarda gateway_ref / gateway_sub_id, devuelve redirect_url.
8. **Webhooks**: `WebhookController` (`POST /webhooks/{gateway}`): verificar firma (401 si falla) → persistir con UNIQUE (gateway, event_id) → `as_enqueue_async_action('impay_process_webhook')` → 200 inmediato; duplicado → 200 sin reprocesar; fallo de persistencia → 500 (la pasarela reintenta). `WebhookProcessor` marca processed/failed. `Jobs\Scheduler` registra el hook con resolución perezosa del container.
9. **REST**: `POST /checkout` (honeypot `website` + rate limit 10/10min + nonce + validación por esquema) y `GET /orders/{uuid}/status` (público, rate limit 120/10min, solo `{status, product_name}`).
10. **Tests nuevos** (39): HttpClient (reintentos/backoff/4xx), verificador de firma MP (8 casos), PaymentService (idempotencia, transiciones, periodo, dunning), CheckoutService (one-time, recurrente, annual_hybrid, errores), MercadoPagoGateway (payloads de preference/preapproval, sandbox, supports).

### Decisiones tomadas (Fase 2)

| # | Decisión | Razón |
|---|---|---|
| 16 | `event_id` de MP = header `x-request-id` (fallback: hash de topic+data.id+firma) | Idempotencia por entrega; la idempotencia de efectos la garantizan además la UNIQUE de payments y la state machine |
| 17 | La activación por `subscription_preapproval` NO extiende el periodo; solo el webhook del pago aprobado lo hace | Evita doble extensión (activación + primer cargo) |
| 18 | El order `subscription_initial` pasa a paid cuando se aprueba el primer cargo, vía `meta.initial_order_uuid` de la suscripción | El external_reference del preapproval es el uuid de la sub, no del order; /gracias necesita ver el order en paid |
| 19 | `annual_hybrid` en checkout = pago único (`kind: purchase`); su suscripción lógica se crea al pagar (Fase 3, RenewalService) | Roadmap sección 17 |
| 20 | Honeypot activado → respuesta neutra 200 con redirect a home (no se revela el mecanismo al bot) | Anti-abuso sin señal |
| 21 | Sin Action Scheduler cargado, el webhook se procesa inline en el request (`do_action` directo) tras persistir | Degradación aceptable; nunca se pierde el evento |
| 22 | Reintentos HTTP: intento inicial + 3 reintentos (1s/4s/9s); "3 intentos" del spec leído como 3 reintentos | Interpretación más robusta de la ambigüedad |
| 23 | `insertRow` deriva formatos de wpdb del tipo de cada valor | Los arrays posicionales de formatos se desalineaban con columnas opcionales |
| 24 | Montos a la API de MP: conversión int→float (`round(amount/100, 2)`) solo en el borde | La regla "nunca floats" aplica a almacenamiento y aritmética de dominio |

### Pendientes de Fase 2

- **Prueba en sandbox de MP**: requiere Access Token de test y registrar la URL de webhooks en el panel. El entorno remoto no tiene credenciales; ejecutar el checklist al desplegar: compra única de prueba → order paid vía webhook; suscripción de prueba con tarjeta de test → active vía webhook.

### Siguiente paso (Fase 3)

1. `PayPalGateway` (Orders v2 + Billing Subscriptions + verify-webhook-signature + OAuth token en transient).
2. Suscripciones lógicas `annual_hybrid` (crear al pagar order de producto anual).
3. `RenewalService` (links 30/15/5/0 días) + consumo de payment links pagados.
4. `DunningService` (emails día 0/3/7 + suspensión) y `ReconciliationService`.
5. Jobs diarios (`impay_reconcile`, `impay_renewal_reminders`, `impay_dunning_notices`, `impay_expire_stale`, `impay_cleanup`).

---

## Sesión 2026-07-03 — Fase 1 completa

### Tareas completadas

1. **Scaffolding**: `composer.json` (PSR-4 `ImaginaPay\` → `src/`, action-scheduler, phpstan 8, phpcs), `imagina-pay.php` (bootstrap mínimo), `phpstan.neon.dist`, `phpcs.xml.dist`, `phpunit.xml.dist`, `.gitignore`.
2. **Activator** (`src/Core/Activator.php`): las 9 tablas de la sección 5 con `dbDelta` (products, prices, customers, orders, subscriptions, payments, payment_links, webhook_events, logs), capability `manage_impay` en administrator, rol `impay_customer`, creación de las 3 páginas (checkout / gracias / mi-cuenta) con shortcodes placeholder y opción `impay_db_version`.
3. **Container DI** (`src/Core/Container.php`): ~100 líneas, registro explícito (bind / singleton / instance), verificación de tipo con `instanceof` en `get()`. Sin autowiring por reflexión (más simple y verificable por PHPStan).
4. **Entidades readonly** (`src/Domain/Entities/`): Product, Price, Customer, Order, Subscription, Payment, PaymentLink. **Enums PHP 8.1** (`src/Domain/Enums/`): SubscriptionStatus, OrderStatus, OrderKind, ProductType, ProductStatus, PriceInterval, PriceStatus, PaymentStatus, PaymentLinkStatus, TaxIdType, WebhookEventStatus.
5. **Repositorios** (`src/Domain/Repositories/`): AbstractRepository + 9 repos concretos sobre `wpdb`, 100% `$wpdb->prepare` (placeholder `%i` para nombres de tabla → queries literal-string verificables por PHPStan).
6. **SubscriptionStateMachine** (`src/Domain/StateMachine/`): transiciones validadas de la sección 6, `do_action("impay_subscription_{estado}")` en cada transición, log de transiciones inválidas + `InvalidTransitionException`.
7. **Support**: `Money` (enteros en unidad mínima, formato es-CO), `Uuid` (v4), `Crypto` (AES-256-GCM, clave HKDF desde AUTH_KEY, payload versionado `v1:`), `Logger` (interface + DatabaseLogger a `impay_logs` + NullLogger), `Clock` (interface + SystemClock UTC).
8. **Capa de gateways** (`src/Gateways/`): `GatewayInterface` completa (sección 7), enum `GatewayMode` (HostedSubscription | Tokenized), `GatewayRegistry`, DTOs (`CheckoutSession`, `WebhookEvent`, `PaymentLinkRequest`). **Sin implementaciones** (Fase 2-3).
9. **SubscriptionService** con branching por `GatewayMode` (match exhaustivo): Hosted delega a la pasarela; Tokenized lanza `GatewayException` hasta Fase 8. Cancelación con `cancel_at_period_end` por defecto.
10. **REST base** `impay/v1` (`src/Rest/`): `AbstractController` con borde de errores (excepción de dominio → JSON `{code, message}` + log), middlewares componibles (Nonce, Capability, RateLimit por IP con transients 10 req/10 min), `Validator` de esquemas declarativos, `Router`, endpoints `GET /health` y `GET|PUT /admin/settings`.
11. **Ajustes cifrados** (`src/Core/Settings.php`): secretos AES-256-GCM at-rest en `wp_options`; el GET REST enmascara (`••••1234`) y el PUT ignora valores enmascarados.
12. **Tests** (PHPUnit 10 + Brain Monkey + Mockery): MoneyTest, CryptoTest, UuidTest, SubscriptionStateMachineTest (transiciones válidas/inválidas/idempotencia + hooks), SubscriptionServiceTest (branching por modo, cancelaciones). 59 tests, 236 aserciones.

### Decisiones tomadas (ambigüedad → lo más simple que cumple el spec)

| # | Decisión | Razón |
|---|---|---|
| 1 | Columnas JSON del spec creadas como `longtext` | Compatibilidad MariaDB 10.4 (JSON es alias de longtext) y `dbDelta` |
| 2 | Sin FOREIGN KEY físicas | `dbDelta` no las soporta; integridad a nivel de aplicación + índices del spec |
| 3 | `Money`: unidad mínima = centavos (÷100) para COP y USD; COP se muestra sin decimales cuando son 0 (`$ 49.900 COP`) | Sección 5: "COP sin decimales: amount = pesos * 100 igualmente, normalizado" |
| 4 | `Money` rechaza montos negativos | Columnas `unsigned`; los reembolsos se modelan con `status`, no con montos negativos |
| 5 | StateMachine: transición al mismo estado = **no-op idempotente sin hooks** | Los webhooks de pasarela se repiten; re-disparar provisión sería peligroso |
| 6 | Transición `expired → active` permitida | annual_hybrid vencido que paga su link tarde debe poder reactivarse |
| 7 | `paused` solo alcanzable desde `active`; `cancelled` es terminal | Lectura estricta del diagrama de la sección 6 |
| 8 | `Crypto`: HKDF-SHA256 de `AUTH_KEY` con info `impay-settings-encryption-v1`, payload `v1:base64(iv+tag+ct)`; fallback `wp_salt('auth')` si AUTH_KEY no existe | Permite rotación de esquema futura; robustez en instalaciones sin AUTH_KEY |
| 9 | Sin chequeo runtime de versión PHP/WP en el bootstrap | WordPress ya aplica los headers `Requires PHP` / `Requires at least` desde WP 5.1+ |
| 10 | Páginas creadas con shortcodes placeholder; la ruta dinámica `/checkout/{slug}` (rewrite) llega en Fase 6 | Fase 1 solo necesita que la activación cree todo sin errores |
| 11 | REST Fase 1 expone `/health` y `/admin/settings` como prueba del pipeline de middleware | El resto de endpoints llegan con sus fases |
| 12 | PHPCS: `WordPress-Extra` con exclusiones de sniffs de formato/naming que chocan con PSR-4/PSR-12 (tabs, snake_case, class-*.php, yoda). Sniffs de seguridad, DB, i18n y prefijos activos | El spec exige a la vez PSR-4 + tipado moderno y WordPress-Extra; se privilegia lo sustantivo (seguridad) sobre lo cosmético |
| 13 | `wpdb::prepare` con placeholder `%i` (WP ≥ 6.2) para nombres de tabla | Mantiene las queries como literal-string → PHPStan/phpstan-wordpress verifican el 100% de queries preparadas |
| 14 | Container sin autowiring por reflexión: registro explícito en `Plugin::registerServices()` | ~100 líneas, tipado verificable, sin magia |
| 15 | `composer.lock` no versionado; phpstan se instala vía repo `artifact` local (`tools/phars/`, gitignored) **solo como workaround del entorno de desarrollo remoto** (el egress bloquea `codeload.github.com`); en una máquina normal Composer lo resuelve desde Packagist sin el zip | No afecta a producción: es require-dev |

### Deuda / pendientes conocidos

- `HttpClient` + `IdempotencyKey` (`src/Http/`) se implementan al inicio de la Fase 2 (primer consumidor real: MercadoPagoGateway).
- Los shortcodes placeholder de las páginas no renderizan nada aún (Fases 5-6).
- `PHPStan 2.x` disponible; se mantiene 1.12 (compatible con szepeviktor/phpstan-wordpress ^1.3). Evaluar upgrade en Fase 7.
- Probar la activación contra un WordPress real (wp-env) al inicio de la Fase 2.

### Siguiente paso (Fase 2 — Mercado Pago end-to-end)

1. `HttpClient` con retries exponenciales (3 intentos, 1s/4s/9s) + `X-Idempotency-Key`.
2. `MercadoPagoGateway`: Checkout Pro (preferences), Preapproval, verificación de firma `x-signature` (HMAC + ventana 5 min), fetchers.
3. `CheckoutService` + endpoint público `POST /checkout` (honeypot + rate limit + nonce).
4. Webhook controller: 200 inmediato → persistir en `impay_webhook_events` → `as_enqueue_async_action('impay_process_webhook')` → processor.
5. `GET /orders/{uuid}/status` para el polling de `/gracias`.
6. Probar en sandbox de MP con cuentas de test.
