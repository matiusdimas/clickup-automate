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
                'Content-Type'  => 'application/json',
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
     * Execute a ClickUp API request with automatic retry on 429 / cURL errors.
     *
     * Throttle strategy — adaptive based on X-RateLimit-Remaining header:
     *
     *   remaining > 50  →  150 ms   fast, plenty of quota left
     *   remaining 21-50 →  400 ms   moderate, starting to slow down
     *   remaining 6-20  →  650 ms   careful, nearly at the limit
     *   remaining ≤ 5   →  pause until X-RateLimit-Reset refills window
     *   header absent   →  250 ms   safe default
     *
     * Effect: small imports (< ~30 tickets) run at nearly full speed,
     * while large imports (1000+ tickets) automatically pace themselves
     * to stay under ClickUp's 100 req/min cap.
     */
    public function requestWithRetry(callable $callback, int $maxRetries = 5, int $initialDelayMs = 1500): Response
    {
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                /** @var Response $response */
                $response = $callback();

                // ── 429 reactive back-off ────────────────────────────────────────
                if ($response->status() === 429 && $attempts < $maxRetries) {
                    $retryAfter = $response->header('Retry-After');
                    $resetTime  = $response->header('X-RateLimit-Reset');

                    if ($retryAfter && is_numeric($retryAfter)) {
                        $sleepSeconds = (int) $retryAfter;
                    } elseif ($resetTime && is_numeric($resetTime)) {
                        $sleepSeconds = max(1, (int) $resetTime - time());
                    } else {
                        $sleepSeconds = ($initialDelayMs * $attempts) / 1000;
                    }

                    // Cap between 5-60 seconds
                    $sleepSeconds = min(60, max(5, (int) ceil($sleepSeconds)));
                    sleep($sleepSeconds);
                    continue;
                }

                // ── Adaptive proactive throttle ──────────────────────────────────
                $remaining = $response->header('X-RateLimit-Remaining');

                if ($remaining !== null && is_numeric($remaining)) {
                    $remaining = (int) $remaining;

                    if ($remaining <= 5) {
                        // Critically low — wait for the window to reset
                        $resetTime   = $response->header('X-RateLimit-Reset');
                        $waitSeconds = ($resetTime && is_numeric($resetTime))
                            ? max(5, (int) $resetTime - time() + 1)
                            : 15;
                        sleep($waitSeconds);
                    } elseif ($remaining <= 20) {
                        usleep(650_000); // 650 ms — nearly at limit
                    } elseif ($remaining <= 50) {
                        usleep(400_000); // 400 ms — slowing down
                    } else {
                        usleep(150_000); // 150 ms — plenty of quota, run fast
                    }
                } else {
                    usleep(250_000); // 250 ms — safe fallback (header absent)
                }
                // ── End adaptive throttle ────────────────────────────────────────

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
