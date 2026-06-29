<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class NumberFormatExtension extends AbstractExtension
{
    public function __construct(private readonly ?Security $security = null)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('trim_number', [$this, 'trimNumber']),
            new TwigFilter('money_symbol', [$this, 'moneySymbol']),
        ];
    }

    public function trimNumber(mixed $value): string
    {
        return $this->formatNumber($value, minimumMoneyDecimals: false);
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

        return $symbol.$this->formatNumber($value, minimumMoneyDecimals: true);
    }

    private function formatNumber(mixed $value, bool $minimumMoneyDecimals): string
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
            $formatted = rtrim(rtrim(sprintf('%.2F', (float) ($negative ? '-'.$number : $number)), '0'), '.');

            return $this->localize($formatted === '-0' ? '0' : $formatted, $minimumMoneyDecimals);
        }

        $rounded = number_format((float) ($negative ? '-'.$number : $number), 2, '.', '');
        $negative = str_starts_with($rounded, '-');
        $rounded = ltrim($rounded, '+-');

        [$whole, $decimal] = array_pad(explode('.', $rounded, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $decimal = rtrim($decimal, '0');
        if ($minimumMoneyDecimals && strlen($decimal) === 1) {
            $decimal .= '0';
        }

        $formatted = $decimal === '' ? $whole : $whole.'.'.$decimal;
        $formatted = $negative && $formatted !== '0' ? '-'.$formatted : $formatted;

        return $this->localize($formatted, minimumMoneyDecimals: false);
    }

    private function localize(string $value, bool $minimumMoneyDecimals): string
    {
        if ($minimumMoneyDecimals && str_contains($value, '.')) {
            [$whole, $decimal] = explode('.', $value, 2);
            if (strlen($decimal) === 1) {
                $value = $whole.'.'.$decimal.'0';
            }
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        [$thousandsSeparator, $decimalSeparator] = $this->separators();

        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', $thousandsSeparator, $whole) ?? $whole;
        $formatted = $decimal === '' ? $whole : $whole.$decimalSeparator.$decimal;

        return $negative ? '-'.$formatted : $formatted;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function separators(): array
    {
        $user = $this->security?->getUser();
        $format = $user instanceof User ? $user->getNumberFormat() : User::NUMBER_FORMAT_COMMA_DOT;

        return $format === User::NUMBER_FORMAT_DOT_COMMA ? ['.', ','] : [',', '.'];
    }
}
