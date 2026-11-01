<?php

namespace App\Reports;

use Closure;

/**
 * One column of a report. A single list of these feeds the on-screen table,
 * CSV, XLSX, PDF and the printable page, so the five can never drift apart.
 */
final class ReportColumn
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const DATE = 'date';

    public const BADGE = 'badge';

    public bool $onScreen = true;

    public bool $inExport = true;

    public bool $summed = false;

    public bool $wrap = false;

    public bool $copyable = false;

    /** @var array<string, string> raw value => Filament colour, for BADGE columns */
    public array $colors = [];

    public string $badgeColor = 'success';

    /** @var (Closure(array<string, mixed>, ReportFilters): ?string)|null where a click on the cell goes, on screen only */
    public ?Closure $link = null;

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, self::TEXT);
    }

    public static function money(string $key, string $label): self
    {
        return new self($key, $label, self::MONEY);
    }

    public static function number(string $key, string $label): self
    {
        return new self($key, $label, self::NUMBER);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, self::DATE);
    }

    public static function badge(string $key, string $label, string $color = 'success'): self
    {
        $column = new self($key, $label, self::BADGE);
        $column->badgeColor = $color;

        return $column;
    }

    /** Detail columns that only make sense in a spreadsheet. */
    public function exportOnly(): self
    {
        $this->onScreen = false;

        return $this;
    }

    /** Columns that only make sense on the screen. */
    public function screenOnly(): self
    {
        $this->inExport = false;

        return $this;
    }

    /** Add this column up in the totals strip and the export footer. */
    public function sum(): self
    {
        $this->summed = true;

        return $this;
    }

    public function wrap(): self
    {
        $this->wrap = true;

        return $this;
    }

    public function copyable(): self
    {
        $this->copyable = true;

        return $this;
    }

    /**
     * @param  Closure(array<string, mixed>, ReportFilters): ?string  $link
     */
    public function linkTo(Closure $link): self
    {
        $this->link = $link;

        return $this;
    }

    /**
     * @param  array<string, string>  $colors
     */
    public function colors(array $colors): self
    {
        $this->colors = $colors;

        return $this;
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, [self::MONEY, self::NUMBER], true);
    }
}
