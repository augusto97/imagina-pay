# PROGRESS.md — Imagina Pay

> Bitácora de sesiones. Leer al iniciar cada sesión, actualizar al terminar.

---

## Estado actual

- **Fase actual:** Fase 2 — Mercado Pago end-to-end → **COMPLETADA (código + tests) ✅** · Pendiente: prueba en sandbox con credenciales reales de MP
- **Siguiente paso:** Fase 3 — PayPal + suscripciones lógicas (annual_hybrid, RenewalService, DunningService, ReconciliationService, jobs)
- **Gates de calidad:** PHPStan level 8 en verde (0 errores) · PHPUnit 98 tests / 345 aserciones en verde · PHPCS en verde

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
