<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Support;

use Ttpryg\PaymentEngine\Application\DTO\HttpResponse;
use Ttpryg\PaymentEngine\Contracts\HttpClientInterface;

class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var array<int, HttpResponse> */
    private array $responses = [];

    private ?\Throwable $pendingException = null;

    public function queue(HttpResponse $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function queueJson(array $data, int $statusCode = 200): self
    {
        return $this->queue(new HttpResponse($statusCode, [], json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }

    public function throwOnNextRequest(\Throwable $throwable): self
    {
        $this->pendingException = $throwable;

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        if ($this->pendingException instanceof \Throwable) {
            $exception = $this->pendingException;
            $this->pendingException = null;

            throw $exception;
        }

        return array_shift($this->responses) ?? new HttpResponse(200, [], '{}');
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: ?string}
     */
    public function lastRequest(): array
    {
        $index = array_key_last($this->requests);
        if ($index === null) {
            throw new \RuntimeException('No HTTP request has been recorded.');
        }

        return $this->requests[$index];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastRequestBody(): array
    {
        $body = $this->lastRequest()['body'];
        if ($body === null) {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
