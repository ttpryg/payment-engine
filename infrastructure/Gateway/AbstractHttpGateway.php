<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Infrastructure\Gateway;

use JsonException;
use Ttpryg\PaymentEngine\Application\DTO\HttpResponse;
use Ttpryg\PaymentEngine\Contracts\HttpClientInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentGatewayInterface;
use Ttpryg\PaymentEngine\Domain\Exception\GatewayException;
use Ttpryg\PaymentEngine\Domain\Exception\HttpClientException;
use Ttpryg\PaymentEngine\Infrastructure\Http\CurlHttpClient;

abstract class AbstractHttpGateway implements PaymentGatewayInterface
{
    protected readonly HttpClientInterface $httpClient;

    protected function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new CurlHttpClient;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, string>  $headers
     */
    protected function request(string $method, string $url, array $headers = [], ?array $payload = null): HttpResponse
    {
        $headers = ['Accept' => 'application/json'] + $headers;
        $body = null;

        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';

            try {
                $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $jsonException) {
                throw GatewayException::failed($this->getName(), 'Failed to encode the gateway request payload.', $jsonException);
            }
        }

        try {
            return $this->httpClient->request($method, $url, $headers, $body);
        } catch (HttpClientException $httpClientException) {
            throw GatewayException::failed($this->getName(), $httpClientException->getMessage(), $httpClientException);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(HttpResponse $response): array
    {
        try {
            return $response->json();
        } catch (JsonException $jsonException) {
            throw GatewayException::failed($this->getName(), 'Malformed JSON response received from the gateway.', $jsonException);
        }
    }

    protected function basicAuth(string $username, string $password = ''): string
    {
        return 'Basic '.base64_encode($username.':'.$password);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    protected function header(array $headers, string $name): ?string
    {
        $needle = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $needle) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            return $value === null ? null : (string) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractErrorMessage(array $data): ?string
    {
        if (isset($data['error_messages']) && is_array($data['error_messages'])) {
            $messages = array_filter(array_map(
                static fn ($message): string => is_scalar($message) ? (string) $message : '',
                $data['error_messages']
            ));

            if ($messages !== []) {
                return implode(', ', $messages);
            }
        }

        foreach (['message', 'status_message', 'error_code', 'failure_code'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    protected function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return null;
    }
}
