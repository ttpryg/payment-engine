<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Application\DTO;

use JsonException;

final class HttpResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers = [],
        public readonly string $body = ''
    ) {}

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
