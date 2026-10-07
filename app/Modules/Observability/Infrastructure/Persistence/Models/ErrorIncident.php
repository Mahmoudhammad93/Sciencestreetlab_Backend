<?php

declare(strict_types=1);

namespace App\Modules\Observability\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Observability\Application\Services\ErrorIncidentDisplay;
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

    public function display(): ErrorIncidentDisplay
    {
        return app(ErrorIncidentDisplay::class);
    }

    public function displayApplicationClass(): string
    {
        return $this->display()->classBasename($this->application_class);
    }

    public function displayModel(): string
    {
        return $this->display()->text($this->eloquent_model);
    }

    public function formattedTrace(): string
    {
        return $this->display()->trace($this->attributes['trace'] ?? $this->trace);
    }

    public function formattedContext(): string
    {
        $raw = $this->attributes['context'] ?? $this->context;

        return $this->display()->context($raw);
    }

    public function displayUserName(): string
    {
        $user = $this->user;

        return is_object($user) && is_string($user->name) && $user->name !== ''
            ? $user->name
            : 'N/A';
    }

    public function displayUserEmail(): string
    {
        $user = $this->user;

        return is_object($user) && is_string($user->email) && $user->email !== ''
            ? $user->email
            : 'N/A';
    }

    public function displayResolverName(): string
    {
        $resolver = $this->resolver;
        if (is_object($resolver) && is_string($resolver->name) && $resolver->name !== '') {
            return $resolver->name;
        }

        return $this->display()->text($this->resolved_by);
    }

    public function toDebugClipboard(): string
    {
        $display = $this->display();
        $lines = [
            'Error ID: '.$display->text($this->uuid),
            'Request ID: '.$display->text($this->request_id),
            'Fingerprint: '.$display->text($this->fingerprint),
            'Severity: '.$display->text($this->level),
            'Status: '.($this->isResolved() ? 'resolved' : 'open'),
            'Module: '.$display->text($this->module),
            'Source: '.$display->text($this->source),
            'Exception: '.$display->text($this->exception_class),
            'Message: '.$display->text($this->message),
            'File: '.$display->file($this->file),
            'Line: '.$display->text($this->line),
            'Application Class: '.$this->displayApplicationClass(),
            'Method: '.$display->text($this->application_method),
            'Model: '.$this->displayModel(),
            'Route: '.$display->text($this->route_name),
            'HTTP: '.trim($display->text($this->request_method, '').' '.$display->text($this->request_path, '')),
            'HTTP Status: '.$display->text($this->http_status),
            'User Type: '.$display->text($this->user_type),
            'User ID: '.$display->text($this->user_id),
            'User Name: '.$this->displayUserName(),
            'User Email: '.$this->displayUserEmail(),
            'First Seen: '.optional($this->first_seen_at)?->toIso8601String() ?: 'N/A',
            'Last Seen: '.optional($this->last_seen_at)?->toIso8601String() ?: 'N/A',
            'Occurrences: '.$display->text($this->occurrences),
            '',
            'Context:',
            $this->formattedContext(),
            '',
            'Stack:',
            $this->formattedTrace(),
        ];

        return implode("\n", $lines);
    }
}
