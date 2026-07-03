<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Domain;

use Brain\Monkey\Actions;
use ImaginaPay\Domain\Entities\Subscription;
use ImaginaPay\Domain\Enums\SubscriptionStatus;
use ImaginaPay\Domain\Repositories\SubscriptionRepository;
use ImaginaPay\Domain\Services\DunningService;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\Support\FixedClock;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class DunningServiceTest extends TestCase
{
    use EntityFactory;

    private \DateTimeImmutable $now;

    /** @var SubscriptionRepository&MockInterface */
    private SubscriptionRepository $subscriptions;

    private DunningService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = $this->baseDate();

        /** @var SubscriptionRepository&MockInterface $subscriptions */
        $subscriptions = Mockery::mock(SubscriptionRepository::class);
        $this->subscriptions = $subscriptions;

        $this->service = new DunningService(
            $this->subscriptions,
            new FixedClock($this->now),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private function pastDue(?array $meta = null): Subscription
    {
        return $this->makeSubscription(SubscriptionStatus::PastDue, meta: $meta);
    }

    public function testFirstRunSendsDayZeroNoticeAndOpensEpisode(): void
    {
        $subscription = $this->pastDue();

        $this->subscriptions->shouldReceive('findByStatus')->andReturn([$subscription]);
        $this->subscriptions->shouldReceive('updateMeta')
            ->once()
            ->with(5, Mockery::on(fn (array $meta): bool => $meta['dunning']['notices'] === [0]
                && $meta['dunning']['since'] === $this->now->format('Y-m-d H:i:s')), $this->now);

        Actions\expectDone('impay_dunning_notice')
            ->once()
            ->whenHappen(function ($sub, int $day): void {
                $this->assertSame(0, $day);
            });

        $this->service->run();

        $this->assertSame(0, did_action('impay_service_suspend'));
    }

    public function testDayThreeNoticeFiresOnce(): void
    {
        $since = $this->now->modify('-3 days')->format('Y-m-d H:i:s');
        $subscription = $this->pastDue(['dunning' => ['since' => $since, 'notices' => [0]]]);

        $this->subscriptions->shouldReceive('findByStatus')->andReturn([$subscription]);
        $this->subscriptions->shouldReceive('updateMeta')
            ->once()
            ->with(5, Mockery::on(static fn (array $meta): bool => $meta['dunning']['notices'] === [0, 3]), $this->now);

        Actions\expectDone('impay_dunning_notice')->once();

        $this->service->run();
    }

    public function testDaySevenNoticeSuspendsProvisioning(): void
    {
        $since = $this->now->modify('-7 days')->format('Y-m-d H:i:s');
        $subscription = $this->pastDue(['dunning' => ['since' => $since, 'notices' => [0, 3]]]);

        $this->subscriptions->shouldReceive('findByStatus')->andReturn([$subscription]);
        $this->subscriptions->shouldReceive('updateMeta')->once();

        Actions\expectDone('impay_dunning_notice')->once();
        Actions\expectDone('impay_service_suspend')->once();

        $this->service->run();
    }

    public function testNoticesAreNotRepeated(): void
    {
        $since = $this->now->modify('-8 days')->format('Y-m-d H:i:s');
        $subscription = $this->pastDue(['dunning' => ['since' => $since, 'notices' => [0, 3, 7]]]);

        $this->subscriptions->shouldReceive('findByStatus')->andReturn([$subscription]);
        $this->subscriptions->shouldNotReceive('updateMeta');

        $this->service->run();

        $this->assertSame(0, did_action('impay_dunning_notice'));
        $this->assertSame(0, did_action('impay_service_suspend'));
    }

    public function testMissedDaysAreCaughtUpInOneRun(): void
    {
        // El job estuvo caído: día 5 sin ningún aviso previo → envía 0 y 3.
        $since = $this->now->modify('-5 days')->format('Y-m-d H:i:s');
        $subscription = $this->pastDue(['dunning' => ['since' => $since, 'notices' => []]]);

        $this->subscriptions->shouldReceive('findByStatus')->andReturn([$subscription]);
        $this->subscriptions->shouldReceive('updateMeta')
            ->once()
            ->with(5, Mockery::on(static fn (array $meta): bool => $meta['dunning']['notices'] === [0, 3]), $this->now);

        Actions\expectDone('impay_dunning_notice')->twice();

        $this->service->run();
    }
}
