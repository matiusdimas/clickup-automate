<?php

namespace App\Services\ClickUp;

use Carbon\Carbon;
use Throwable;

class DateFormattingService
{
    /**
     * Standardize any date format (eBesha ISO with microseconds, MySQL timestamps, Unix ms, SDP strings)
     * into standard SDP format: "Jul 29, 2026 10:24 AM" (M d, Y h:i A).
     */
    public static function format(?string $dateString): string
    {
        if (blank($dateString) || $dateString === '-' || strtolower(trim((string) $dateString)) === 'not assigned') {
            return '';
        }

        $trimmed = trim((string) $dateString);

        // If numeric unix timestamp (milliseconds or seconds)
        if (is_numeric($trimmed)) {
            $timestamp = (int) $trimmed;
            if (strlen((string) $timestamp) > 11) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            return Carbon::createFromTimestamp($timestamp)->format('M d, Y h:i A');
        }

        try {
            return Carbon::parse($trimmed)->format('M d, Y h:i A');
        } catch (Throwable $e) {
            return $trimmed;
        }
    }

    /**
     * Safely parse any date string format into a Carbon instance, returning null on failure.
     */
    public static function parseToCarbon(?string $dateString): ?Carbon
    {
        if (blank($dateString) || $dateString === '-' || strtolower(trim((string) $dateString)) === 'not assigned') {
            return null;
        }

        $trimmed = trim((string) $dateString);

        if (is_numeric($trimmed)) {
            $timestamp = (int) $trimmed;
            if (strlen((string) $timestamp) > 11) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            return Carbon::createFromTimestamp($timestamp);
        }

        try {
            return Carbon::parse($trimmed);
        } catch (Throwable $e) {
            return null;
        }
    }
}
