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
use ImaginaPay\Admin\AdminPage;
use ImaginaPay\Domain\Services\CheckoutService;
use ImaginaPay\Domain\Services\CustomerAccountService;
use ImaginaPay\Domain\Services\DunningService;
use ImaginaPay\Domain\Services\MaintenanceService;
use ImaginaPay\Domain\Services\MetricsService;
use ImaginaPay\Domain\Services\PaymentService;
use ImaginaPay\Domain\Services\ReconciliationService;
use ImaginaPay\Domain\Services\RenewalService;
use ImaginaPay\Domain\Services\SubscriptionService;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\ImaginaPayException;
use ImaginaPay\Gateways\GatewayRegistry;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoClient;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoGateway;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoWebhookHandler;
use ImaginaPay\Gateways\MercadoPago\MercadoPagoWebhookVerifier;
use ImaginaPay\Frontend\Shortcodes;
use ImaginaPay\Gateways\PayPal\PayPalClient;
use ImaginaPay\Gateways\PayPal\PayPalGateway;
use ImaginaPay\Gateways\PayPal\PayPalWebhookHandler;
use ImaginaPay\Domain\Services\ProvisioningService;
use ImaginaPay\Http\HttpClient;
use ImaginaPay\Integrations\ImaginaUpdaterClient;
use ImaginaPay\Jobs\Scheduler;
use ImaginaPay\Mail\EmailNotifications;
use ImaginaPay\Mail\EmailTemplate;
use ImaginaPay\Mail\Mailer;
use ImaginaPay\Rest\Admin\CustomersController as AdminCustomersController;
use ImaginaPay\Rest\Admin\DashboardController as AdminDashboardController;
use ImaginaPay\Rest\Admin\PaymentLinksController as AdminPaymentLinksController;
use ImaginaPay\Rest\Admin\PaymentsController as AdminPaymentsController;
use ImaginaPay\Rest\Admin\ProductsController as AdminProductsController;
use ImaginaPay\Rest\Admin\SubscriptionsController as AdminSubscriptionsController;
use ImaginaPay\Rest\Admin\WebhookEventsController as AdminWebhookEventsController;
use ImaginaPay\Rest\CheckoutController;
use ImaginaPay\Rest\HealthController;
use ImaginaPay\Rest\OrdersController;
use ImaginaPay\Rest\Portal\LoginController;
use ImaginaPay\Rest\Portal\PortalController;
use ImaginaPay\Rest\Portal\ReceiptController;
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

        // Presupuesto de peso cero (sección 1): en requests ajenos al plugin
        // solo se registran hooks; los controllers se construyen en rest_api_init.
        add_action('rest_api_init', static function () use ($container): void {
            $container->get(Router::class)->registerRoutes();
        });

        $container->get(Scheduler::class)->register();
        $container->get(Hooks::class)->register();
        $container->get(Shortcodes::class)->register();

        if (is_admin()) {
            $container->get(AdminPage::class)->register();
        }
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
            $c->get(PaymentLinkRepository::class),
            $c->get(PaymentService::class),
            $c->get(RenewalService::class),
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

        // PayPal.
        $c->singleton(PayPalClient::class, static fn (Container $c): PayPalClient => new PayPalClient(
            $c->get(HttpClient::class),
            $c->get(Settings::class),
        ));

        $c->singleton(PayPalWebhookHandler::class, static fn (Container $c): PayPalWebhookHandler => new PayPalWebhookHandler(
            $c->get(PayPalClient::class),
            $c->get(OrderRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(PaymentLinkRepository::class),
            $c->get(PaymentService::class),
            $c->get(RenewalService::class),
            $c->get(SubscriptionStateMachine::class),
            $c->get(Logger::class),
        ));

        $c->singleton(PayPalGateway::class, static fn (Container $c): PayPalGateway => new PayPalGateway(
            $c->get(PayPalClient::class),
            $c->get(PayPalWebhookHandler::class),
            $c->get(ProductRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(PriceRepository::class),
            $c->get(PaymentLinkRepository::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        // Registro de pasarelas.
        $c->singleton(GatewayRegistry::class, static function (Container $c): GatewayRegistry {
            $registry = new GatewayRegistry();
            $registry->register($c->get(MercadoPagoGateway::class));
            $registry->register($c->get(PayPalGateway::class));

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

        $c->singleton(RenewalService::class, static fn (Container $c): RenewalService => new RenewalService(
            $c,
            $c->get(SubscriptionRepository::class),
            $c->get(OrderRepository::class),
            $c->get(PaymentLinkRepository::class),
            $c->get(PriceRepository::class),
            $c->get(ProductRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(PaymentService::class),
            $c->get(SubscriptionStateMachine::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        $c->singleton(DunningService::class, static fn (Container $c): DunningService => new DunningService(
            $c->get(SubscriptionRepository::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        $c->singleton(ReconciliationService::class, static fn (Container $c): ReconciliationService => new ReconciliationService(
            $c->get(SubscriptionRepository::class),
            $c->get(OrderRepository::class),
            $c->get(PaymentLinkRepository::class),
            $c->get(GatewayRegistry::class),
            $c->get(SubscriptionStateMachine::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        $c->singleton(MaintenanceService::class, static fn (Container $c): MaintenanceService => new MaintenanceService(
            $c->get(LogRepository::class),
            $c->get(WebhookEventRepository::class),
            $c->get(Settings::class),
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

        // Integraciones y provisión.
        $c->singleton(ImaginaUpdaterClient::class, static fn (Container $c): ImaginaUpdaterClient => new ImaginaUpdaterClient(
            $c->get(HttpClient::class),
            $c->get(Settings::class),
            $c->get(Logger::class),
        ));

        $c->singleton(ProvisioningService::class, static fn (Container $c): ProvisioningService => new ProvisioningService(
            $c->get(ProductRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(ImaginaUpdaterClient::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        // Correo transaccional.
        $c->singleton(EmailTemplate::class, static fn (Container $c): EmailTemplate => new EmailTemplate(
            $c->get(Settings::class),
        ));

        $c->singleton(Mailer::class, static fn (Container $c): Mailer => new Mailer(
            $c->get(EmailTemplate::class),
            $c->get(Settings::class),
            $c->get(Logger::class),
        ));

        $c->singleton(EmailNotifications::class, static fn (Container $c): EmailNotifications => new EmailNotifications(
            $c->get(Mailer::class),
            $c->get(CustomerRepository::class),
            $c->get(ProductRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(Clock::class),
        ));

        // Jobs y listeners de dominio.
        $c->singleton(Scheduler::class, static fn (Container $c): Scheduler => new Scheduler($c));
        $c->singleton(Hooks::class, static fn (Container $c): Hooks => new Hooks($c));

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

        // Servicios y controllers del admin.
        $c->singleton(MetricsService::class, static fn (Container $c): MetricsService => new MetricsService(
            $c->get(SubscriptionRepository::class),
            $c->get(PaymentRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(ProductRepository::class),
            $c->get(WebhookEventRepository::class),
            $c->get(Clock::class),
        ));

        $c->singleton(AdminProductsController::class, static fn (Container $c): AdminProductsController => new AdminProductsController(
            $c->get(Logger::class),
            $c->get(ProductRepository::class),
            $c->get(PriceRepository::class),
            $c->get(Validator::class),
            $c->get(Clock::class),
        ));

        $c->singleton(AdminSubscriptionsController::class, static fn (Container $c): AdminSubscriptionsController => new AdminSubscriptionsController(
            $c->get(Logger::class),
            $c->get(SubscriptionRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(ProductRepository::class),
            $c->get(PaymentRepository::class),
            $c->get(SubscriptionService::class),
            $c->get(SubscriptionStateMachine::class),
            $c->get(GatewayRegistry::class),
            $c->get(Clock::class),
        ));

        $c->singleton(AdminCustomersController::class, static fn (Container $c): AdminCustomersController => new AdminCustomersController(
            $c->get(Logger::class),
            $c->get(CustomerRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(PaymentRepository::class),
            $c->get(ProductRepository::class),
        ));

        $c->singleton(AdminPaymentsController::class, static fn (Container $c): AdminPaymentsController => new AdminPaymentsController(
            $c->get(Logger::class),
            $c->get(PaymentRepository::class),
            $c->get(OrderRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(ProductRepository::class),
            $c->get(Clock::class),
        ));

        $c->singleton(AdminPaymentLinksController::class, static fn (Container $c): AdminPaymentLinksController => new AdminPaymentLinksController(
            $c->get(Logger::class),
            $c->get(CustomerRepository::class),
            $c->get(PriceRepository::class),
            $c->get(GatewayRegistry::class),
            $c->get(Validator::class),
        ));

        $c->singleton(AdminWebhookEventsController::class, static fn (Container $c): AdminWebhookEventsController => new AdminWebhookEventsController(
            $c->get(Logger::class),
            $c->get(WebhookEventRepository::class),
            $c->get(LogRepository::class),
            $c->get(WebhookProcessor::class),
        ));

        $c->singleton(AdminDashboardController::class, static fn (Container $c): AdminDashboardController => new AdminDashboardController(
            $c->get(Logger::class),
            $c->get(MetricsService::class),
        ));

        $c->singleton(AdminPage::class, static fn (): AdminPage => new AdminPage());

        // Portal cliente y páginas propias.
        $c->singleton(CustomerAccountService::class, static fn (Container $c): CustomerAccountService => new CustomerAccountService(
            $c->get(CustomerRepository::class),
            $c->get(EmailNotifications::class),
            $c->get(Clock::class),
            $c->get(Logger::class),
        ));

        $c->singleton(Shortcodes::class, static fn (Container $c): Shortcodes => new Shortcodes(
            $c->get(ProductRepository::class),
            $c->get(PriceRepository::class),
        ));

        $c->singleton(PortalController::class, static fn (Container $c): PortalController => new PortalController(
            $c->get(Logger::class),
            $c->get(CustomerRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(PaymentRepository::class),
            $c->get(PaymentLinkRepository::class),
            $c->get(ProductRepository::class),
            $c->get(SubscriptionService::class),
            $c->get(Validator::class),
        ));

        $c->singleton(ReceiptController::class, static fn (Container $c): ReceiptController => new ReceiptController(
            $c->get(Logger::class),
            $c->get(PaymentRepository::class),
            $c->get(CustomerRepository::class),
        ));

        $c->singleton(LoginController::class, static fn (Container $c): LoginController => new LoginController(
            $c->get(Logger::class),
            $c->get(Validator::class),
        ));

        $c->singleton(Router::class, static fn (Container $c): Router => new Router([
            $c->get(HealthController::class),
            $c->get(SettingsController::class),
            $c->get(CheckoutController::class),
            $c->get(OrdersController::class),
            $c->get(WebhookController::class),
            $c->get(AdminProductsController::class),
            $c->get(AdminSubscriptionsController::class),
            $c->get(AdminCustomersController::class),
            $c->get(AdminPaymentsController::class),
            $c->get(AdminPaymentLinksController::class),
            $c->get(AdminWebhookEventsController::class),
            $c->get(AdminDashboardController::class),
            $c->get(PortalController::class),
            $c->get(ReceiptController::class),
            $c->get(LoginController::class),
        ]));
    }
}
