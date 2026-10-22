<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Contracts;

use Ttpryg\PaymentEngine\Application\DTO\HttpResponse;

interface HttpClientInterface
{
    /**
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
