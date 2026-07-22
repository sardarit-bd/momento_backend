<?php

namespace App\Console\Commands;

use App\Services\TGC\TGCService;
use Illuminate\Console\Command;

class RegisterTGCWebhook extends Command
{
    protected $signature = 'tgc:webhook:register {event=ReceiptShipped}';

    protected $description = 'Register a TGC webhook subscription for the given event if not already subscribed';

    public function handle(TGCService $tgc): int
    {
        $event = $this->argument('event');
        $callbackUri = config('services.tgc.webhook_callback_url');

        if (! $callbackUri) {
            $this->error('TGC_WEBHOOK_CALLBACK_URL is not set in .env');

            return self::FAILURE;
        }

        $this->info("Checking existing subscriptions for event [{$event}]...");

        $existing = collect(data_get($tgc->listWebhooks(), 'result', []))
            ->first(fn ($hook) => data_get($hook, 'event') === $event
                && data_get($hook, 'callback_uri') === $callbackUri);

        if ($existing) {
            $this->info('Already registered. Webhook id: '.data_get($existing, 'id'));

            return self::SUCCESS;
        }

        $response = $tgc->subscribeWebhook($event, $callbackUri);
        $webhookId = data_get($response, 'result.id') ?? data_get($response, 'id');

        $this->info("Subscribed successfully. Webhook id: {$webhookId}");

        return self::SUCCESS;
    }
}
