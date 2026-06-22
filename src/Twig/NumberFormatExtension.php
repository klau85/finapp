<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class NumberFormatExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('trim_number', [$this, 'trimNumber']),
            new TwigFilter('money_symbol', [$this, 'moneySymbol']),
        ];
    }

    public function trimNumber(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $number = trim((string) $value);
        $number = str_replace(',', '', $number);

        if (!is_numeric($number)) {
            return (string) $value;
        }

        $negative = str_starts_with($number, '-');
        $number = ltrim($number, '+-');

        if (stripos($number, 'e') !== false) {
            $number = rtrim(rtrim(sprintf('%.4F', (float) ($negative ? '-'.$number : $number)), '0'), '.');

            return $number === '-0' ? '0' : $number;
        }

        $rounded = number_format((float) ($negative ? '-'.$number : $number), 4, '.', '');
        $negative = str_starts_with($rounded, '-');
        $rounded = ltrim($rounded, '+-');

        [$whole, $decimal] = array_pad(explode('.', $rounded, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $decimal = rtrim($decimal, '0');

        $formatted = $decimal === '' ? $whole : $whole.'.'.$decimal;

        return $negative && $formatted !== '0' ? '-'.$formatted : $formatted;
    }

    public function moneySymbol(mixed $value, ?string $currency): string
    {
        $symbol = match (strtoupper((string) $currency)) {
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'USD' => '$',
            default => strtoupper((string) $currency).' ',
        };

        return $symbol.$this->trimNumber($value);
    }
}
