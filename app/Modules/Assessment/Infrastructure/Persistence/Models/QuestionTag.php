<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

class QuestionTag extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['slug', 'name'];

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'question_tag');
    }
}
