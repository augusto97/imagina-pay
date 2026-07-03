# PROGRESS.md — Imagina Pay

> Bitácora de sesiones. Leer al iniciar cada sesión, actualizar al terminar.

---

## Estado actual

- **Fase actual:** Fase 1 — Fundaciones (backend core) → **COMPLETADA ✅**
- **Siguiente paso:** Fase 2 — Mercado Pago end-to-end (esperando confirmación para iniciar)
- **Gates de calidad:** PHPStan level 8 en verde (0 errores) · PHPUnit 59 tests / 236 aserciones en verde · PHPCS (WordPress-Extra ajustado) en verde

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
