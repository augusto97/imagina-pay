<?php

declare(strict_types=1);

namespace ImaginaPay\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubEscapeFunctions();
        Functions\stubTranslationFunctions();
        Functions\when('wp_strip_all_tags')->alias(
            static fn (string $text): string => trim(strip_tags($text)),
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
