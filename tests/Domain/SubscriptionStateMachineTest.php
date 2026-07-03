<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use Brain\Monkey\Actions;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\StateMachine\SubscriptionStateMachine;
use ImaginaPay\Exceptions\InvalidTransitionException;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class SubscriptionStateMachineTest extends TestCase
{
    private \DateTimeImmutable $now;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $repository;

    private SubscriptionStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new \DateTimeImmutable('2026-07-03 12:00:00', new \DateTimeZone('UTC'));

        /** @var SubscriptionRepository&MockInterface $repository */
        $repository = Mockery::mock(SubscriptionRepository::class);
        $this->repository = $repository;

        $this->stateMachine = new SubscriptionStateMachine(
            $this->repository,
            new NullLogger(),
            new FixedClock($this->now),
        );
    }

    private function subscription(SubscriptionStatus $status): Subscription
    {
        return new Subscription(
            id: 7,
            uuid: '3b241101-e2bb-4255-8caf-4136c566a962',
            customerId: 1,
            productId: 2,
            priceId: 3,
            gateway: 'mercadopago',
            gatewaySubId: 'preapproval-123',
            status: $status,
            currentPeriodStart: null,
            currentPeriodEnd: null,
            cancelAtPeriodEnd: false,
            cancelledAt: null,
            failedPayments: 0,
            meta: null,
            createdAt: $this->now->modify('-1 month'),
            updatedAt: $this->now->modify('-1 day'),
        );
    }

    public function testPendingToActivePersistsFiresHookAndReturnsUpdated(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Pending);

        $this->repository
            ->shouldReceive('updateStatus')
            ->once()
            ->with(7, SubscriptionStatus::Active, $this->now, null);

        Actions\expectDone('impay_subscription_active')
            ->once()
            ->whenHappen(function (Subscription $updated, array $context): void {
                $this->assertSame(SubscriptionStatus::Active, $updated->status);
                $this->assertSame(['source' => 'webhook'], $context);
            });

        $updated = $this->stateMachine->transition($subscription, SubscriptionStatus::Active, ['source' => 'webhook']);

        $this->assertSame(SubscriptionStatus::Active, $updated->status);
        $this->assertSame($this->now, $updated->updatedAt);
        $this->assertNull($updated->cancelledAt);
    }

    public function testCancellingSetsCancelledAt(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Active);

        $this->repository
            ->shouldReceive('updateStatus')
            ->once()
            ->with(7, SubscriptionStatus::Cancelled, $this->now, $this->now);

        Actions\expectDone('impay_subscription_cancelled')->once();

        $updated = $this->stateMachine->transition($subscription, SubscriptionStatus::Cancelled);

        $this->assertSame(SubscriptionStatus::Cancelled, $updated->status);
        $this->assertSame($this->now, $updated->cancelledAt);
    }

    /**
     * @return array<string, array{SubscriptionStatus, SubscriptionStatus}>
     */
    public static function validTransitions(): array
    {
        return [
            'pending → active' => [SubscriptionStatus::Pending, SubscriptionStatus::Active],
            'pending → cancelled' => [SubscriptionStatus::Pending, SubscriptionStatus::Cancelled],
            'active → past_due' => [SubscriptionStatus::Active, SubscriptionStatus::PastDue],
            'active → paused' => [SubscriptionStatus::Active, SubscriptionStatus::Paused],
            'active → cancelled' => [SubscriptionStatus::Active, SubscriptionStatus::Cancelled],
            'active → expired' => [SubscriptionStatus::Active, SubscriptionStatus::Expired],
            'past_due → active' => [SubscriptionStatus::PastDue, SubscriptionStatus::Active],
            'past_due → cancelled' => [SubscriptionStatus::PastDue, SubscriptionStatus::Cancelled],
            'paused → active' => [SubscriptionStatus::Paused, SubscriptionStatus::Active],
            'paused → cancelled' => [SubscriptionStatus::Paused, SubscriptionStatus::Cancelled],
            'expired → active' => [SubscriptionStatus::Expired, SubscriptionStatus::Active],
        ];
    }

    /**
     * @dataProvider validTransitions
     */
    public function testValidTransitionsAreAllowed(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        $this->assertTrue($this->stateMachine->canTransition($from, $to));

        $this->repository->shouldReceive('updateStatus')->once();
        Actions\expectDone('impay_subscription_' . $to->value)->once();

        $updated = $this->stateMachine->transition($this->subscription($from), $to);

        $this->assertSame($to, $updated->status);
    }

    /**
     * @return array<string, array{SubscriptionStatus, SubscriptionStatus}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'pending → past_due' => [SubscriptionStatus::Pending, SubscriptionStatus::PastDue],
            'pending → paused' => [SubscriptionStatus::Pending, SubscriptionStatus::Paused],
            'pending → expired' => [SubscriptionStatus::Pending, SubscriptionStatus::Expired],
            'active → pending' => [SubscriptionStatus::Active, SubscriptionStatus::Pending],
            'past_due → paused' => [SubscriptionStatus::PastDue, SubscriptionStatus::Paused],
            'expired → paused' => [SubscriptionStatus::Expired, SubscriptionStatus::Paused],
            'cancelled → active (terminal)' => [SubscriptionStatus::Cancelled, SubscriptionStatus::Active],
            'cancelled → pending (terminal)' => [SubscriptionStatus::Cancelled, SubscriptionStatus::Pending],
        ];
    }

    /**
     * @dataProvider invalidTransitions
     */
    public function testInvalidTransitionsThrowAndDoNotPersist(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        $this->assertFalse($this->stateMachine->canTransition($from, $to));

        $this->repository->shouldNotReceive('updateStatus');

        $this->expectException(InvalidTransitionException::class);
        $this->stateMachine->transition($this->subscription($from), $to);
    }

    public function testSameStateIsIdempotentNoOp(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Active);

        $this->repository->shouldNotReceive('updateStatus');

        // Los webhooks se repiten: transición al mismo estado no persiste ni dispara hooks.
        $result = $this->stateMachine->transition($subscription, SubscriptionStatus::Active);

        $this->assertSame($subscription, $result);
        $this->assertSame(0, did_action('impay_subscription_active'));
    }
}
