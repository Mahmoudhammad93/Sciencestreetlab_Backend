<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Alias resource name required by frontend contract. */
final class QuestionResource extends JsonResource
{
    public function __construct($resource, private readonly bool $includeExplanation = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return (new StudentQuestionResource($this->resource, $this->includeExplanation))->toArray($request);
    }
}
