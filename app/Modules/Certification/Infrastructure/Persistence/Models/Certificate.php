<?php

declare(strict_types=1);

namespace App\Modules\Certification\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Certificate extends Model
{
    protected $fillable = [
        'uuid', 'certificate_number', 'user_id', 'course_id', 'enrollment_id',
        'template_id', 'issued_at', 'pdf_path', 'verification_code', 'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (Certificate $certificate): void {
            if (empty($certificate->uuid)) {
                $certificate->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'template_id');
    }
}
