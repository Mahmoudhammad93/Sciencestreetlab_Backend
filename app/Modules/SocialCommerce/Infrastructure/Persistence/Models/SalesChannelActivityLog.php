<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesChannelActivityLog extends Model
{
    protected $fillable = [
        'integration_id',
        'level',
        'event_code',
        'message_key',
        'message_params',
        'technical_context',
    ];

    protected function casts(): array
    {
        return [
            'message_params' => 'array',
            'technical_context' => 'array',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(SalesChannelIntegration::class, 'integration_id');
    }

    public function humanMessage(): string
    {
        return (string) __($this->message_key, $this->message_params ?? []);
    }
}
