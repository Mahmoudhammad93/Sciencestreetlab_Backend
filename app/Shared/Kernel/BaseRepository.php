<?php

declare(strict_types=1);

namespace App\Shared\Kernel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository
{
    abstract protected function model(): string;

    protected function query(): Builder
    {
        return $this->model()::query();
    }

    public function findById(int|string $id): ?Model
    {
        return $this->query()->find($id);
    }

    public function findByIdOrFail(int|string $id): Model
    {
        return $this->query()->findOrFail($id);
    }
}
