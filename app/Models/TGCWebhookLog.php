<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TGCWebhookLog extends Model
{
    protected $table = 'tgc_webhook_logs';

    protected $fillable = [
        'tgc_webhook_event_id',
        'type',
        'event',
        'signature_valid',
        'payload',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'processed_at' => 'datetime',
    ];
}