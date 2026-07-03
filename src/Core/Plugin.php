<?php

declare(strict_types=1);

namespace ImaginaPay\Core;

use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\LogRepository;
use ImaginaPay\Domain\Repositories\OrderRepository;
use ImaginaPay\Domain\Repositories\PaymentLinkRepository;
use ImaginaPay\Domain\Repositories\PaymentRepository;
use ImaginaPay\Domain\Repositories\PriceRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Repositories\WebhookEventRepository;
use ImaginaPay\Domain\Services\CheckoutService;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Domain\Services\SubscriptionService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\ImaginaPayException;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoClient;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoGateway;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoWebhookHandler;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoWebhookVerifier;
use ImaginaPay\Http\HttpClient;
use ImaginaPay\Jobs\Scheduler;
use ImaginaPay\Rest\CheckoutController;
use ImaginaPay\Rest\HealthController;
use ImaginaPay\Rest\OrdersController;
use ImaginaPay\Rest\Router;
use ImaginaPay\Rest\SettingsController;
use ImaginaPay\Rest\Validator;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Crypto;
use ImaginaPay\Support\DatabaseLogger;
use ImaginaPay\Support\Logger;
use ImaginaPay\Support\SystemClock;
use ImaginaPay\Webhooks\WebhookController;
use ImaginaPay\Webhooks\WebhookProcessor;

/**
 * Bootstrap del plugin: construye el contenedor, registra servicios y
 * engancha los puntos de entrada. Principio de peso cero: en páginas
 * ajenas al plugin solo se registra el hook rest_api_init.
 */
final class Plugin
{
    private static ?Container $container = null;

    public static function boot(): void
    {
        if (self::$container !== null) {
            return;
        }

        $container = new Container();
        self::registerServices($container);
        self::$container = $container;

        self::loadActionScheduler();

        $container->get(Router::class)->register();
        $container->get(Scheduler::class)->register();
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            throw new ImaginaPayException('El plugin aún no ha sido inicializado.');
        }

        return self::$container;
    }

    /**
     * Action Scheduler embebido vía Composer (jobs a partir de la Fase 2).
     */
    private static function loadActionScheduler(): void
    {
        if (!defined('IMPAY_PLUGIN_DIR')) {
            return;
        }

        $actionScheduler = constant('IMPAY_PLUGIN_DIR') . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

        if (is_string($actionScheduler) && is_readable($actionScheduler)) {
            require_once $actionScheduler;
        }
    }

    private static function registerServices(Container $c): void
    {
        // Infraestructura.
        $c->singleton(\wpdb::class, static function (): \wpdb {
            global $wpdb;

            return $wpdb;
        });

        $c->singleton(Clock::class, static fn (): SystemClock => new SystemClock());

        $c->singleton(Crypto::class, static function (): Crypto {
            $authKey = defined('AUTH_KEY') ? constant('AUTH_KEY') : '';
            $authKey = is_string($authKey) ? $authKey : '';

            // Fallback a la sal de WP si AUTH_KEY no está definida en wp-config.
            if ($authKey === '' && function_exists('wp_salt')) {
                $authKey = wp_salt('auth');
            }

            return Crypto::fromAuthKey($authKey);
        });

        $c->singleton(Settings::class, static fn (Container $c): Settings => new Settings($c->get(Crypto::class)));

        // Repositorios.
        $c->singleton(LogRepository::class, static fn (Container $c): LogRepository => new LogRepository($c->get(\wpdb::class)));
        $c->singleton(ProductRepository::class, static fn (Container $c): ProductRepository => new ProductRepository($c->get(\wpdb::class)));
        $c->singleton(PriceRepository::class, static fn (Container $c): PriceRepository => new PriceRepository($c->get(\wpdb::class)));
        $c->singleton(CustomerRepository::class, static fn (Container $c): CustomerRepository => new CustomerRepository($c->get(\wpdb::class)));
        $c->singleton(OrderRepository::class, static fn (Container $c): OrderRepository => new OrderRepository($c->get(\wpdb::class)));
        $c->singleton(SubscriptionRepository::class, static fn (Container $c): SubscriptionRepository => new SubscriptionRepository($c->get(\wpdb::class)));
        $c->singleton(PaymentRepository::class, static fn (Container $c): PaymentRepository => new PaymentRepository($c->get(\wpdb::class)));
        $c->singleton(PaymentLinkRepository::class, static fn (Container $c): PaymentLinkRepository => new PaymentLinkRepository($c->get(\wpdb::class)));
        $c->singleton(WebhookEventRepository::class, static fn (Container $c): WebhookEventRepository => new WebhookEventRepository($c->get(\wpdb::class)));

        // Soporte.
        $c->singleton(Logger::class, static fn (Container $c): DatabaseLogger => new DatabaseLogger(
            $c->get(LogRepository::class),
            $c->get(Clock::class),
        ));

        // Dominio.
        $c->singleton(SubscriptionStateMachine::class, static fn (Container $c): SubscriptionStateMachine => new SubscriptionStateMachine(
            $c->get(SubscriptionRepository::class),
            $c->get(Logger::class),
            $c->get(Clock::class),
        ));

        // HTTP saliente.
        $c->singleton(HttpClient::class, static fn (Container $c): HttpClient => new HttpClient(
            $c->get(Logger::class),
        ));

        // Mercado Pago.
        $c->singleton(MercadoPagoClient::class, static fn (Container $c): MercadoPagoClient => new MercadoPagoClient(
            $c->get(HttpClient::class),
            $c->get(Settings::class),
        ));

        $c->singleton(MercadoPagoWebhookVerifier::class, static fn (Container $c): MercadoPagoWebhookVerifier => new MercadoPagoWebhookVerifier(
            $c->get(Clock::class),
        ));

        $c->singleton(MercadoPagoWebhookHandler::class, static fn (Container $c): MercadoPagoWebhookHandler => new MercadoPagoWebhookHandler(
            $c->get(MercadoPagoClient::class),
            $c->get(OrderRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(PaymentService::class),
            $c->get(SubscriptionStateMachine::class),
            $c->get(Logger::class),
        ));

        $c->singleton(MercadoPagoGateway::class, static fn (Container $c): MercadoPagoGateway => new MercadoPagoGateway(
            $c->get(MercadoPagoClient::class),
            $c->get(MercadoPagoWebhookVerifier::class),
            $c->get(MercadoPagoWebhookHandler::class),
            $c->get(ProductRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(PaymentLinkRepository::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        // Registro de pasarelas (PayPal llega en Fase 3).
        $c->singleton(GatewayRegistry::class, static function (Container $c): GatewayRegistry {
            $registry = new GatewayRegistry();
            $registry->register($c->get(MercadoPagoGateway::class));

            return $registry;
        });

        $c->singleton(PaymentService::class, static fn (Container $c): PaymentService => new PaymentService(
            $c->get(PaymentRepository::class),
            $c->get(OrderRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(PriceRepository::class),
            $c->get(SubscriptionStateMachine::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        $c->singleton(CheckoutService::class, static fn (Container $c): CheckoutService => new CheckoutService(
            $c->get(ProductRepository::class),
            $c->get(PriceRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(OrderRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(GatewayRegistry::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        $c->singleton(SubscriptionService::class, static fn (Container $c): SubscriptionService => new SubscriptionService(
            $c->get(SubscriptionRepository::class),
            $c->get(SubscriptionStateMachine::class),
            $c->get(GatewayRegistry::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        // Webhooks.
        $c->singleton(WebhookProcessor::class, static fn (Container $c): WebhookProcessor => new WebhookProcessor(
            $c->get(WebhookEventRepository::class),
            $c->get(GatewayRegistry::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        // Jobs.
        $c->singleton(Scheduler::class, static fn (Container $c): Scheduler => new Scheduler($c));

        // REST.
        $c->singleton(Validator::class, static fn (): Validator => new Validator());

        $c->singleton(HealthController::class, static fn (Container $c): HealthController => new HealthController(
            $c->get(Logger::class),
            $c->get(Clock::class),
        ));

        $c->singleton(SettingsController::class, static fn (Container $c): SettingsController => new SettingsController(
            $c->get(Logger::class),
            $c->get(Settings::class),
            $c->get(Validator::class),
        ));

        $c->singleton(CheckoutController::class, static fn (Container $c): CheckoutController => new CheckoutController(
            $c->get(Logger::class),
            $c->get(CheckoutService::class),
            $c->get(GatewayRegistry::class),
            $c->get(Validator::class),
        ));

        $c->singleton(OrdersController::class, static fn (Container $c): OrdersController => new OrdersController(
            $c->get(Logger::class),
            $c->get(OrderRepository::class),
            $c->get(ProductRepository::class),
        ));

        $c->singleton(WebhookController::class, static fn (Container $c): WebhookController => new WebhookController(
            $c->get(Logger::class),
            $c->get(GatewayRegistry::class),
            $c->get(WebhookEventRepository::class),
            $c->get(Clock::class),
        ));

        $c->singleton(Router::class, static fn (Container $c): Router => new Router([
            $c->get(HealthController::class),
            $c->get(SettingsController::class),
            $c->get(CheckoutController::class),
            $c->get(OrdersController::class),
            $c->get(WebhookController::class),
        ]));
    }
}
