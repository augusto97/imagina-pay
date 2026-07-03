<?php

declare(strict_types=1);

namespace ImaginaPay\Gateways;

use ImaginaPay\Exceptions\GatewayException;

final class GatewayRegistry
{
    /** @var array<string, GatewayInterface> */
    private array $gateways = [];

    public function register(GatewayInterface $gateway): void
    {
        $this->gateways[$gateway->id()] = $gateway;
    }

    public function has(string $id): bool
    {
        return isset($this->gateways[$id]);
    }

    public function get(string $id): GatewayInterface
    {
        if (!isset($this->gateways[$id])) {
            throw new GatewayException(sprintf('Pasarela no disponible: "%s".', $id));
        }

        return $this->gateways[$id];
    }

    /**
     * @return array<string, GatewayInterface>
     */
    public function all(): array
    {
        return $this->gateways;
    }
}
