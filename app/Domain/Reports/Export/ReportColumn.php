<?php

namespace App\Domain\Reports\Export;

/**
 * One column of a report. `money` columns hold INTEGER CENTAVOS in the row
 * data and are converted once, per format — as a real number in a
 * spreadsheet so the accountant can sum it, and as formatted text on paper.
 */
class ReportColumn
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const DATE = 'date';

    public const NUMBER = 'number';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TEXT,
    ) {}

    public function isNumeric(): bool
    {
        return $this->type === self::MONEY || $this->type === self::NUMBER;
    }

    /** Pesos as a float for spreadsheets; centavos never leave the app as one. */
    public function numericValue(mixed $raw): float|int|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->type === self::MONEY ? ((int) $raw) / 100 : (int) $raw;
    }

    /** Human form, used by PDF/HTML and by CSV. */
    public function displayValue(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if ($this->type === self::MONEY) {
            $centavos = (int) $raw;

            return number_format(abs($centavos) / 100, 2).($centavos < 0 ? ' CR' : '');
        }

        if (is_array($raw)) {
            return implode(', ', $raw);
        }

        return (string) $raw;
    }
}
