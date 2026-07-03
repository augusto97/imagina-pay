<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Mail;

use Brain\Monkey\Functions;
use ImaginaPay\Domain\Enums\PaymentStatus;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Gateways\GatewayPayment;
use ImaginaPay\Mail\EmailNotifications;
use ImaginaPay\Mail\Mailer;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class EmailNotificationsTest extends TestCase
{
    use EntityFactory;

    private \DateTimeImmutable $now;

    /** @var Mailer&MockInterface */
    private Mailer $mailer;

    /** @var CustomerRepository&MockInterface */
    private CustomerRepository $customers;

    /** @var ProductRepository&MockInterface */
    private ProductRepository $products;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    private EmailNotifications $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn(0);
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://site.test' . $path);

        $this->now = $this->baseDate();

        /** @var Mailer&MockInterface $mailer */
        $mailer = Mockery::mock(Mailer::class);
        $this->mailer = $mailer;

        /** @var CustomerRepository&MockInterface $customers */
        $customers = Mockery::mock(CustomerRepository::class);
        $this->customers = $customers;

        /** @var ProductRepository&MockInterface $products */
        $products = Mockery::mock(ProductRepository::class);
        $this->products = $products;

        /** @var SubscriptionRepository&MockInterface $subscriptions */
        $subscriptions = Mockery::mock(SubscriptionRepository::class);
        $this->subscriptions = $subscriptions;

        $this->notifications = new EmailNotifications(
            $this->mailer,
            $this->customers,
            $this->products,
            $this->subscriptions,
            new FixedClock($this->now),
        );
    }

    public function testWelcomeIncludesLicenseAndMarksFlag(): void
    {
        $stale = $this->makeSubscription(SubscriptionStatus::Active);
        $fresh = $this->makeSubscription(
            SubscriptionStatus::Active,
            currentPeriodEnd: $this->now->modify('+1 month'),
            meta: ['license_key' => 'LIC-777'],
        );

        $this->subscriptions->shouldReceive('find')->with(5)->andReturn($fresh);
        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());
        $this->products->shouldReceive('find')->andReturn($this->makeProduct());

        $this->mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (string $to, string $subject, string $heading, array $paragraphs): bool {
                $this->assertSame('cliente@example.com', $to);
                $body = implode(' ', $paragraphs);
                $this->assertStringContainsString('LIC-777', $body);
                $this->assertStringContainsString('VPS Mensual', $body);

                return true;
            });

        $this->subscriptions->shouldReceive('updateMeta')
            ->once()
            ->with(5, Mockery::on(static fn (array $meta): bool => $meta['welcome_sent'] === true
                && $meta['license_key'] === 'LIC-777'), $this->now);

        $this->notifications->welcome($stale);
    }

    public function testWelcomeIsSentOnlyOnce(): void
    {
        $fresh = $this->makeSubscription(SubscriptionStatus::Active, meta: ['welcome_sent' => true]);

        $this->subscriptions->shouldReceive('find')->andReturn($fresh);
        $this->mailer->shouldNotReceive('send');

        $this->notifications->welcome($fresh);
    }

    public function testReceiptFormatsAmountInSpanish(): void
    {
        $this->customers->shouldReceive('find')->with(1)->andReturn($this->makeCustomer());

        $payment = new GatewayPayment(
            gateway: 'mercadopago',
            gatewayPaymentId: 'pay-1',
            status: PaymentStatus::Approved,
            currency: 'COP',
            amount: 4990000,
            method: 'pse',
            paidAt: $this->now,
            raw: [],
        );

        $this->mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (string $to, string $subject, string $heading, array $paragraphs): bool {
                $this->assertStringContainsString('$ 49.900 COP', implode(' ', $paragraphs));

                return true;
            });

        $this->notifications->receipt($payment, 1);
    }

    public function testDunningDayZeroAlsoNotifiesAdmin(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::PastDue);

        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());
        $this->products->shouldReceive('find')->andReturn($this->makeProduct());

        $this->mailer->shouldReceive('send')->once()->andReturn(true);
        $this->mailer->shouldReceive('sendToAdmin')->once()->andReturn(true);

        $this->notifications->dunningNotice($subscription, 0);
    }

    public function testDunningDaySevenDoesNotRepeatAdminNotice(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::PastDue);

        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());
        $this->products->shouldReceive('find')->andReturn($this->makeProduct());

        $this->mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (string $to, string $subject, string $heading, array $paragraphs): bool {
                $this->assertStringContainsString('suspendido', implode(' ', $paragraphs));

                return true;
            });
        $this->mailer->shouldNotReceive('sendToAdmin');

        $this->notifications->dunningNotice($subscription, 7);
    }

    public function testRenewalReminderUsesLinkAsCta(): void
    {
        $subscription = $this->makeSubscription(
            SubscriptionStatus::Active,
            currentPeriodEnd: $this->now->modify('+15 days'),
            gatewaySubId: null,
        );

        $link = new \ImaginaPay\Domain\Entities\PaymentLink(
            id: 4,
            uuid: '77777777-7777-4777-8777-777777777777',
            customerId: 1,
            subscriptionId: 5,
            priceId: 3,
            gateway: 'mercadopago',
            gatewayRef: null,
            url: 'https://mp.test/renovar',
            status: \ImaginaPay\Domain\Enums\PaymentLinkStatus::Open,
            expiresAt: null,
            paidOrderId: null,
            createdAt: $this->baseDate(),
        );

        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());
        $this->products->shouldReceive('find')->andReturn($this->makeProduct());

        $this->mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (string $to, string $subject, string $heading, array $p, ?string $ctaLabel, ?string $ctaUrl): bool {
                $this->assertSame('https://mp.test/renovar', $ctaUrl);
                $this->assertStringContainsString('15 días', $subject);

                return true;
            });

        $this->notifications->renewalReminder($subscription, $link, 15);
    }
}
