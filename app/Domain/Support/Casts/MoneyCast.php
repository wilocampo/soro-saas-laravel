<?php

namespace App\Domain\Support\Casts;

use App\Domain\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast: BIGINT centavos column <-> Money value object.
 * Usage: protected $casts = ['debit_centavos' => MoneyCast::class];
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::fromCentavos((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->centavos();
        }

        if (is_int($value)) {
            return $value; // already centavos
        }

        throw new InvalidArgumentException(
            "[$key] must be a Money instance or integer centavos — never float/string (CLAUDE.md rule 5)."
        );
    }
}
