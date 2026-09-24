<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Gateways;

use Ttpryg\PaymentEngine\Contracts\PaymentGatewayInterface;
use Ttpryg\PaymentEngine\Exceptions\GatewayNotFoundException;

class GatewayManager
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function register(PaymentGatewayInterface $gateway): void
    {
        $this->gateways[strtolower($gateway->getName())] = $gateway;
    }

    public function get(string $name): PaymentGatewayInterface
    {
        $key = strtolower($name);
        if (!isset($this->gateways[$key])) {
            throw GatewayNotFoundException::forName($name);
        }
        return $this->gateways[$key];
    }

    public function has(string $name): bool
    {
        return isset($this->gateways[strtolower($name)]);
    }

    /**
     * @return array<string, PaymentGatewayInterface>
     */
    public function all(): array
    {
        return $this->gateways;
    }
}
