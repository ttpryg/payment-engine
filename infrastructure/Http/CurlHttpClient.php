<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Infrastructure\Http;

use Ttpryg\PaymentEngine\Application\DTO\HttpResponse;
use Ttpryg\PaymentEngine\Contracts\HttpClientInterface;
use Ttpryg\PaymentEngine\Domain\Exception\HttpClientException;

class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10
    ) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw HttpClientException::transportError('Unable to initialise the cURL handle.');
        }

        $requestMethod = strtoupper($method);
        if ($requestMethod === '') {
            curl_close($handle);

            throw HttpClientException::transportError('The HTTP method must not be empty.');
        }

        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name.': '.$value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $requestMethod,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $errorMessage = curl_error($handle);
            $errorNumber = curl_errno($handle);
            curl_close($handle);

            throw HttpClientException::transportError("[{$errorNumber}] {$errorMessage}");
        }

        $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return new HttpResponse($statusCode, [], (string) $responseBody);
    }
}
