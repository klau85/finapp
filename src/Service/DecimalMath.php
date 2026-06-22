<?php

declare(strict_types=1);

namespace App\Service;

final class DecimalMath
{
    public const SCALE = 8;

    public static function normalize(string $value, int $scale = self::SCALE): string
    {
        $value = trim($value);
        if ($value === '') {
            return self::zero($scale);
        }

        if (!str_contains($value, '.')) {
            return $value.'.'.str_repeat('0', $scale);
        }

        [$whole, $decimal] = explode('.', $value, 2);
        $decimal = substr($decimal.str_repeat('0', $scale), 0, $scale);

        return ($whole === '' ? '0' : $whole).'.'.$decimal;
    }

    public static function zero(int $scale = self::SCALE): string
    {
        return '0.'.str_repeat('0', $scale);
    }

    public static function cmp(string $left, string $right, int $scale = self::SCALE): int
    {
        return bccomp($left, $right, $scale);
    }

    public static function add(string $left, string $right, int $scale = self::SCALE): string
    {
        return bcadd($left, $right, $scale);
    }

    public static function sub(string $left, string $right, int $scale = self::SCALE): string
    {
        return bcsub($left, $right, $scale);
    }

    public static function mul(string $left, string $right, int $scale = self::SCALE): string
    {
        return bcmul($left, $right, $scale);
    }

    public static function div(string $left, string $right, int $scale = self::SCALE): string
    {
        if (self::cmp($right, self::zero($scale), $scale) === 0) {
            return self::zero($scale);
        }

        return bcdiv($left, $right, $scale);
    }
}
