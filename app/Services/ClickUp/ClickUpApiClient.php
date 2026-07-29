<?php

namespace App\Services\ClickUp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClickUpApiClient
{
    private const BASE_URL = 'https://api.clickup.com/api/v2';
    private string $apiKey;

    public function __construct()
    {
        $apiKey = config('services.clickup.api_key');
        if (! $apiKey) {
            throw new RuntimeException('CLICKUP_API_KEY belum diatur di config/services.php.');
        }
        $this->apiKey = $apiKey;
    }

    public function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->timeout(60)
            ->acceptJson()
            ->withoutVerifying()
            ->withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ]);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return self::BASE_URL;
    }

    /**
     * Helper to execute ClickUp API request with retry on 429 Rate Limit Exceeded and cURL timeouts
     */
    public function requestWithRetry(callable $callback, int $maxRetries = 5, int $initialDelayMs = 1500): Response
    {
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                /** @var Response $response */
                $response = $callback();

                if ($response->status() === 429 && $attempts < $maxRetries) {
                    $retryAfter = $response->header('Retry-After');
                    $resetTime = $response->header('X-RateLimit-Reset');

                    if ($retryAfter && is_numeric($retryAfter)) {
                        $sleepSeconds = (int) $retryAfter;
                    } elseif ($resetTime && is_numeric($resetTime)) {
                        $sleepSeconds = max(1, (int) $resetTime - time());
                    } else {
                        $sleepSeconds = ($initialDelayMs * $attempts) / 1000;
                    }

                    $sleepSeconds = min(10, max(1, (int) round($sleepSeconds)));
                    sleep($sleepSeconds);
                    continue;
                }

                // Micro-throttle requests slightly to respect rate limits
                usleep(150000);
                return $response;

            } catch (\Throwable $e) {
                if ($attempts < $maxRetries) {
                    sleep(2);
                    continue;
                }
                throw $e;
            }
        }
    }
}
