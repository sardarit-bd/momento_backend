<?php

namespace App\Services\TGC;

use App\Exceptions\TGC\TGCAuthException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TGCSessionManager
{
    private const CACHE_KEY = 'tgc_session_id';
    private const USER_CACHE_KEY = 'tgc_user_id';

    public function getSessionId(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->authenticate();
    }

    public function flushSession(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::USER_CACHE_KEY);
    }

    public function getDesignerId(): string
    {
        $configuredDesignerId = trim((string) config('services.tgc.designer_id', ''));
        if ($configuredDesignerId !== '') {
            return $configuredDesignerId;
        }

        return (string) Cache::get('tgc_designer_id', '');
    }

    public function getUserId(): string
    {
        $cached = (string) Cache::get(self::USER_CACHE_KEY, '');
        if ($cached !== '') {
            return $cached;
        }

        $this->authenticate();

        return (string) Cache::get(self::USER_CACHE_KEY, '');
    }

    public function authenticate(): string
    {
        $baseUrl = rtrim((string) config('services.tgc.base_url'), '/');
        $ttlHours = (int) config('services.tgc.session_cache_ttl', 12);

        $response = Http::acceptJson()
            ->timeout(30)
            ->retry(2, 200, null, false)
            ->post($baseUrl.'/session', [
                'api_key_id' => config('services.tgc.api_key_id', config('services.tgc.api_key')),
                'username' => config('services.tgc.username'),
                'password' => config('services.tgc.password'),
            ]);

        if (! $response->successful()) {
            throw new TGCAuthException('Unable to authenticate with TGC');
        }

        $sessionId = (string) (
            data_get($response->json(), 'result.id')
            ?? data_get($response->json(), 'session_id')
            ?? ''
        );
        $userId    = (string) (
            data_get($response->json(), 'result.user_id')
            ?? data_get($response->json(), 'user_id')
            ?? ''
        );

        if ($sessionId === '') {
            throw new TGCAuthException('TGC authentication response missing session');
        }

        // Fetch designer_id when possible, but don't block cart/session flows if this fails.
        $designerId = '';
        try {
            $designerResponse = Http::acceptJson()
                ->timeout(30)
                ->get($baseUrl.'/designer', [
                    'session_id' => $sessionId,
                    'user_id'    => $userId,
                ]);

            $designerId = (string) data_get($designerResponse->json(), 'result.id', '');
        } catch (RequestException $e) {
        }

        Cache::put(self::CACHE_KEY, $sessionId, now()->addHours($ttlHours));
        Cache::put(self::USER_CACHE_KEY, $userId, now()->addHours($ttlHours));
        Cache::put('tgc_designer_id', $designerId, now()->addHours($ttlHours));

        return $sessionId;
    }
}
