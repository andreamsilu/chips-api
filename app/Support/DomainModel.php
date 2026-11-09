<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @phpstan-consistent-constructor */
abstract class DomainModel extends Model
{
    use HasUlids, \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $guarded = ['*'];

    /** @param array<string,mixed> $attributes */
    public static function record(array $attributes): static
    {
        $model = new static;
        $model->forceFill($attributes)->save();

        return $model;
    }
}
