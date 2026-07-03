# Hooks públicos de Imagina Pay

Todos los hooks son *actions* de WordPress. Las entidades que reciben son
objetos readonly del namespace `ImaginaPay\Domain\Entities`.

## Ciclo de vida de suscripciones

Cada transición de la máquina de estados dispara su hook:

| Hook | Argumentos | Cuándo |
|---|---|---|
| `impay_subscription_active` | `Subscription $sub, array $context` | Se activa (primer pago, recuperación de mora, renovación) |
| `impay_subscription_past_due` | `Subscription $sub, array $context` | Pago fallido con la suscripción activa |
| `impay_subscription_paused` | `Subscription $sub, array $context` | Pausada (Mercado Pago) |
| `impay_subscription_cancelled` | `Subscription $sub, array $context` | Cancelada (cliente, admin, pasarela o 3 fallos) |
| `impay_subscription_expired` | `Subscription $sub, array $context` | Vencida (anual +7 días sin renovar) |

`$context['source']` indica el origen: `webhook`, `payment`, `reconciliation`,
`annual_hybrid`, `renewal_link`, `dunning`, `expire_stale`.

## Provisión

| Hook | Argumentos | Cuándo |
|---|---|---|
| `impay_provision` | `Subscription $sub, Product $product` | Producto con provisión tipo `hook` al activarse: automatiza aquí VPS/hosting |
| `impay_service_suspend` | `Subscription $sub` | Suspender el servicio (mora día 7 o anual vencida +7 días): corta acceso aquí |
| `impay_license_created` | `Subscription $sub, string $licenseKey` | Licencia creada en Imagina Updater |
| `impay_manual_task` | `Subscription $sub, Product $product` | Producto con provisión `manual`: tarea pendiente para el equipo |

## Pagos y renovaciones

| Hook | Argumentos | Cuándo |
|---|---|---|
| `impay_order_paid` | `Order $order, GatewayPayment $payment` | Un order pasa a pagado |
| `impay_order_failed` / `impay_order_refunded` | `Order $order, GatewayPayment $payment` | Cambios de estado del order |
| `impay_payment_approved` | `GatewayPayment $payment, int $customerId` | Cada cobro aprobado (único o recurrente) |
| `impay_renewal_reminder` | `Subscription $sub, PaymentLink $link, int $daysLeft` | Recordatorio anual (30/15/5/0 días) |
| `impay_renewal_paid` | `Subscription $sub, ?Order $order` | Link de renovación pagado |
| `impay_dunning_notice` | `Subscription $sub, int $day` | Aviso de mora (día 0/3/7) |

## Prioridades internas

El plugin registra sus propios listeners: provisión en prioridad **10** y
emails en prioridad **20** (así el correo de bienvenida incluye la licencia).
Engancha tu código con prioridad >20 si necesitas correr después de ambos.

## Ejemplo

```php
add_action('impay_subscription_active', function ($subscription, $context) {
    // Activar el VPS del cliente.
}, 30, 2);

add_action('impay_service_suspend', function ($subscription) {
    // Suspender el VPS.
}, 30);
```
