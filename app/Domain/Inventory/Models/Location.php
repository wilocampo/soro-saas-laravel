<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/** A stocking location. v1 ships one; the dimension exists everywhere. */
class Location extends Model
{
    protected $table = 'locations';

    protected $guarded = ['id'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    public static function default(): self
    {
        return static::query()->where('is_default', true)->firstOrFail();
    }
}
