<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Infrastructure\Persistence\Models;

use App\Modules\Gamification\Domain\Enums\AchievementCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Achievement extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'slug', 'category', 'points', 'icon_path', 'badge_color', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => AchievementCategory::class,
            'is_active' => 'boolean',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AchievementRule::class);
    }
}
