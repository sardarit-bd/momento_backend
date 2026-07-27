<?php

namespace App\Services\PaymentGateway;

use App\Models\SecretKey;

class StripeConfigService
{
    protected static ?string $publishableKey = null;

    protected static ?string $secretKey = null;

    protected static ?string $webhookSigningSecret = null;

    public static function getPublishableKey(): ?string
    {
        if (self::$publishableKey === null) {
            self::$publishableKey = self::resolveKey('stripe_publishable_key');
        }

        return self::$publishableKey;
    }

    public static function getSecretKey(): ?string
    {
        if (self::$secretKey === null) {
            self::$secretKey = self::resolveKey('stripe_secret_key');
        }

        return self::$secretKey;
    }

    public static function getWebhookSigningSecret(): ?string
    {
        if (self::$webhookSigningSecret === null) {
            self::$webhookSigningSecret = self::resolveKey('stripe_webhook_key');
        }

        return self::$webhookSigningSecret;
    }

    public static function refresh(): void
    {
        self::$publishableKey = null;
        self::$secretKey = null;
        self::$webhookSigningSecret = null;
    }

    protected static function resolveKey(string $dbColumn): ?string
    {
        $envKey = match ($dbColumn) {
            'stripe_publishable_key' => 'STRIPE_KEY',
            'stripe_secret_key' => 'STRIPE_SECRET',
            'stripe_webhook_key' => 'STRIPE_WEBHOOK_SECRET',
            default => null,
        };

        if (app()->environment('local', 'testing') && env($envKey)) {
            return env($envKey);
        }

        return SecretKey::where('is_active', true)
            ->first()?->{$dbColumn};
    }
}
