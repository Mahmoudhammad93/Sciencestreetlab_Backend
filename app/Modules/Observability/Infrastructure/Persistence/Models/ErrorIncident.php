<?php

declare(strict_types=1);

namespace App\Modules\Observability\Infrastructure\Persistence\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ErrorIncident extends Model
{
    protected $fillable = [
        'uuid',
        'fingerprint',
        'level',
        'exception_class',
        'message',
        'http_status',
        'module',
        'source',
        'application_class',
        'application_method',
        'eloquent_model',
        'file',
        'line',
        'route_name',
        'request_method',
        'request_path',
        'user_id',
        'user_type',
        'request_id',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'resolved_by',
        'context',
        'trace',
    ];

    protected static function booted(): void
    {
        static::creating(function (ErrorIncident $incident): void {
            if (empty($incident->uuid)) {
                $incident->uuid = (string) Str::uuid();
            }
            if ($incident->occurrences === null) {
                $incident->occurrences = 1;
            }
            if ($incident->first_seen_at === null) {
                $incident->first_seen_at = now();
            }
            if ($incident->last_seen_at === null) {
                $incident->last_seen_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'line' => 'integer',
            'occurrences' => 'integer',
            'context' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function markResolved(?int $adminId): void
    {
        $this->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $adminId,
        ])->save();
    }

    public function reopen(): void
    {
        $this->forceFill([
            'resolved_at' => null,
            'resolved_by' => null,
        ])->save();
    }

    public function displayApplicationClass(): string
    {
        if (! is_string($this->application_class) || $this->application_class === '') {
            return 'N/A';
        }

        return class_basename($this->application_class);
    }

    public function displayModel(): string
    {
        if (! is_string($this->eloquent_model) || $this->eloquent_model === '') {
            return 'N/A';
        }

        return $this->eloquent_model;
    }

    public function toDebugClipboard(): string
    {
        $lines = [
            'Error ID: '.$this->uuid,
            'Request ID: '.($this->request_id ?: 'N/A'),
            'Fingerprint: '.$this->fingerprint,
            'Severity: '.$this->level,
            'Status: '.($this->isResolved() ? 'resolved' : 'open'),
            'Module: '.($this->module ?: 'N/A'),
            'Source: '.$this->source,
            'Exception: '.$this->exception_class,
            'Message: '.$this->message,
            'File: '.($this->file ?: 'N/A'),
            'Line: '.($this->line !== null ? (string) $this->line : 'N/A'),
            'Application Class: '.$this->displayApplicationClass(),
            'Method: '.($this->application_method ?: 'N/A'),
            'Model: '.$this->displayModel(),
            'Route: '.($this->route_name ?: 'N/A'),
            'HTTP: '.trim(($this->request_method ?? '').' '.($this->request_path ?? '')),
            'HTTP Status: '.($this->http_status !== null ? (string) $this->http_status : 'N/A'),
            'User Type: '.($this->user_type ?: 'N/A'),
            'User ID: '.($this->user_id !== null ? (string) $this->user_id : 'N/A'),
            'First Seen: '.optional($this->first_seen_at)?->toIso8601String(),
            'Last Seen: '.optional($this->last_seen_at)?->toIso8601String(),
            'Occurrences: '.(string) $this->occurrences,
            '',
            'Context:',
            $this->contextToClipboard(),
            '',
            'Stack:',
            (string) ($this->trace ?: 'N/A'),
        ];

        return implode("\n", $lines);
    }

    private function contextToClipboard(): string
    {
        if (! is_array($this->context) || $this->context === []) {
            return 'N/A';
        }

        $json = json_encode($this->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : 'N/A';
    }
}
