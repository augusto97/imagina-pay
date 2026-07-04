<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use ImaginaPay\Domain\Entities\Product;
use ImaginaPay\Domain\Enums\ProductStatus;
use ImaginaPay\Domain\Enums\ProductType;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Repositories\ProductRepository;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\ProvisioningService;
use ImaginaPay\Integrations\ImaginaUpdaterClient;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class ProvisioningServiceTest extends TestCase
{
    use EntityFactory;

    private \DateTimeImmutable $now;

    /** @var ProductRepository&MockInterface */
    private ProductRepository $products;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    /** @var CustomerRepository&MockInterface */
    private CustomerRepository $customers;

    /** @var ImaginaUpdaterClient&MockInterface */
    private ImaginaUpdaterClient $updater;

    private ProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_json_encode')->alias('json_encode');

        $this->now = $this->baseDate();

        /** @var ProductRepository&MockInterface $products */
        $products = Mockery::mock(ProductRepository::class);
        $this->products = $products;

        /** @var SubscriptionRepository&MockInterface $subscriptions */
        $subscriptions = Mockery::mock(SubscriptionRepository::class);
        $this->subscriptions = $subscriptions;

        /** @var CustomerRepository&MockInterface $customers */
        $customers = Mockery::mock(CustomerRepository::class);
        $this->customers = $customers;

        /** @var ImaginaUpdaterClient&MockInterface $updater */
        $updater = Mockery::mock(ImaginaUpdaterClient::class);
        $this->updater = $updater;

        $this->service = new ProvisioningService(
            $this->products,
            $this->subscriptions,
            $this->customers,
            $this->updater,
            new FixedClock($this->now),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed>|null $provisioning
     */
    private function productWith(?array $provisioning): Product
    {
        return new Product(
            id: 2,
            uuid: '11111111-1111-4111-8111-111111111111',
            name: 'Licencia Imagina Forms',
            slug: 'imagina-forms',
            type: ProductType::Subscription,
            description: null,
            features: null,
            imageUrl: null,
            status: ProductStatus::Active,
            provisioning: $provisioning,
            customFields: null,
            createdAt: $this->baseDate(),
            updatedAt: $this->baseDate(),
        );
    }

    public function testUpdaterLicenseIsCreatedAndStoredInMeta(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active);

        $this->products->shouldReceive('find')->andReturn(
            $this->productWith(['type' => 'updater_license', 'updater_product_id' => 12]),
        );
        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());

        $this->updater->shouldReceive('createLicense')
            ->once()
            ->with(Mockery::type(\ImaginaPay\Domain\Entities\Customer::class), 12, $subscription->uuid)
            ->andReturn('LIC-ABC-123');

        $this->subscriptions->shouldReceive('updateMeta')
            ->once()
            ->with(5, Mockery::on(static fn (array $meta): bool => $meta['license_key'] === 'LIC-ABC-123'), $this->now);

        Actions\expectDone('impay_license_created')->once();

        $this->service->provision($subscription);
    }

    public function testExistingLicenseIsReactivatedNotRecreated(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active, meta: ['license_key' => 'LIC-VIEJA']);

        $this->products->shouldReceive('find')->andReturn(
            $this->productWith(['type' => 'updater_license', 'updater_product_id' => 12]),
        );

        $this->updater->shouldReceive('activateLicense')->once()->with('LIC-VIEJA');
        $this->updater->shouldNotReceive('createLicense');

        $this->service->provision($subscription);
    }

    public function testFailedLicenseCreationFallsBackToManualTask(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active);

        $this->products->shouldReceive('find')->andReturn(
            $this->productWith(['type' => 'updater_license', 'updater_product_id' => 12]),
        );
        $this->customers->shouldReceive('find')->andReturn($this->makeCustomer());

        $this->updater->shouldReceive('createLicense')->andThrow(new \RuntimeException('API caída'));

        $this->subscriptions->shouldReceive('updateMeta')
            ->once()
            ->with(5, Mockery::on(static fn (array $meta): bool => ($meta['manual_task']['status'] ?? '') === 'pending'), $this->now);

        Actions\expectDone('impay_manual_task')->once();

        $this->service->provision($subscription);
    }

    public function testHookTypeFiresImpayProvision(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active);
        $product = $this->productWith(['type' => 'hook']);

        $this->products->shouldReceive('find')->andReturn($product);

        Actions\expectDone('impay_provision')
            ->once()
            ->whenHappen(function ($sub, $prod) use ($subscription, $product): void {
                $this->assertSame($subscription, $sub);
                $this->assertSame($product, $prod);
            });

        $this->service->provision($subscription);
    }

    public function testManualTypeCreatesPendingTask(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active);

        $this->products->shouldReceive('find')->andReturn($this->productWith(['type' => 'manual']));

        $this->subscriptions->shouldReceive('updateMeta')
            ->once()
            ->with(5, Mockery::on(static fn (array $meta): bool => ($meta['manual_task']['status'] ?? '') === 'pending'), $this->now);

        Actions\expectDone('impay_manual_task')->once();

        $this->service->provision($subscription);
    }

    public function testProductWithoutProvisioningDoesNothing(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::Active);

        $this->products->shouldReceive('find')->andReturn($this->productWith(null));
        $this->subscriptions->shouldNotReceive('updateMeta');

        $this->service->provision($subscription);
    }

    public function testSuspendDeactivatesLicense(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::PastDue, meta: ['license_key' => 'LIC-1']);

        $this->products->shouldReceive('find')->andReturn(
            $this->productWith(['type' => 'updater_license', 'updater_product_id' => 12]),
        );

        $this->updater->shouldReceive('deactivateLicense')->once()->with('LIC-1');

        $this->service->suspend($subscription);
    }

    public function testSuspendWithoutLicenseIsNoOp(): void
    {
        $subscription = $this->makeSubscription(SubscriptionStatus::PastDue);

        $this->products->shouldReceive('find')->andReturn(
            $this->productWith(['type' => 'updater_license', 'updater_product_id' => 12]),
        );

        $this->updater->shouldNotReceive('deactivateLicense');

        $this->service->suspend($subscription);
    }
}
