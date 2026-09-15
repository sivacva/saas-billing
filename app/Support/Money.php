<?php

namespace App\Support;

/**
 * The one place money gets rounded with bcmath. Previously duplicated
 * inside InvoiceCalculator, where a missing scale argument on an
 * intermediate step once silently truncated cents before rounding could
 * even run - see git history / InvoiceCalculatorTest for that bug. Shared
 * here so a second caller (DashboardCalculator) can't reintroduce it by
 * copy-pasting a slightly different version.
 */
final class Money
{
    private const int CALC_SCALE = 6;

    /**
     * bcmath has no rounding function - shift the target scale off, round
     * half-up on the resulting integer, shift back. Every intermediate step
     * must carry an explicit scale: without one, bcmath falls back to its
     * ini default (scale 0) and truncates to a whole number immediately,
     * before rounding ever gets a chance to run. Assumes a non-negative
     * input, which is all money/quantity values in this app ever are.
     */
    public static function round(string $value, int $scale = 2): string
    {
        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($value, $factor, self::CALC_SCALE);
        $rounded = bcadd($shifted, '0.5', self::CALC_SCALE);
        $truncated = bcdiv($rounded, '1', 0);

        return bcdiv($truncated, $factor, $scale);
    }
}
