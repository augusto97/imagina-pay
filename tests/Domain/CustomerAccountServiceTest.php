<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use ImaginaPay\Domain\Entities\Customer;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Domain\Services\CustomerAccountService;
use ImaginaPay\Mail\EmailNotifications;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class CustomerAccountServiceTest extends TestCase
{
    use EntityFactory;

    /** @var CustomerRepository&MockInterface */
    private CustomerRepository $customers;

    private CustomerAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CustomerRepository&MockInterface $customers */
        $customers = Mockery::mock(CustomerRepository::class);
        $this->customers = $customers;

        /** @var EmailNotifications&MockInterface $mail */
        $mail = Mockery::mock(EmailNotifications::class);

        $this->service = new CustomerAccountService(
            $this->customers,
            $mail,
            new FixedClock($this->baseDate()),
            new NullLogger(),
        );
    }

    /**
     * Customer ya con usuario WP: ensureWpUser es un no-op y el test se
     * concentra en la actualización diferida de perfil.
     */
    private function customerWithWpUser(): Customer
    {
        return new Customer(
            id: 1,
            uuid: '33333333-3333-4333-8333-333333333333',
            wpUserId: 55,
            email: 'cliente@example.com',
            fullName: 'Cliente de Prueba',
            company: null,
            taxIdType: null,
            taxId: null,
            country: 'CO',
            phone: null,
            gatewayRefs: null,
            createdAt: $this->baseDate(),
            updatedAt: $this->baseDate(),
        );
    }

    public function testAppliesPendingProfileUpdateWhenOrderIsPaid(): void
    {
        $customer = $this->customerWithWpUser();
        $this->customers->shouldReceive('find')->with(1)->andReturn($customer);

        $order = $this->makeOrder(meta: [
            'pending_customer_update' => ['full_name' => 'Nombre Cambiado', 'company' => 'ACME SAS'],
        ]);

        $this->customers->shouldReceive('update')
            ->once()
            ->with(1, ['full_name' => 'Nombre Cambiado', 'company' => 'ACME SAS'], Mockery::type(\DateTimeImmutable::class));

        $this->service->onOrderPaid($order);
    }

    public function testIgnoresOrdersWithoutPendingUpdate(): void
    {
        $this->customers->shouldReceive('find')->with(1)->andReturn($this->customerWithWpUser());
        $this->customers->shouldNotReceive('update');

        $this->service->onOrderPaid($this->makeOrder());
    }

    public function testIgnoresUnknownKeysAndNonStringValuesInPendingUpdate(): void
    {
        $this->customers->shouldReceive('find')->with(1)->andReturn($this->customerWithWpUser());

        $order = $this->makeOrder(meta: [
            'pending_customer_update' => [
                'email' => 'otro@example.com',   // no permitido: el email identifica al customer
                'wp_user_id' => 999,             // no permitido
                'full_name' => ['array'],        // tipo inválido
                'phone' => '3001234567',         // válido
            ],
        ]);

        $this->customers->shouldReceive('update')
            ->once()
            ->with(1, ['phone' => '3001234567'], Mockery::type(\DateTimeImmutable::class));

        $this->service->onOrderPaid($order);
    }
}
