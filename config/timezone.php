<?php
/**
 * NLETA GLOBAL TIMEZONE CONFIGURATION
 * ------------------------------------
 * Application timezone:
 * Asia/Kolkata (IST / UTC+05:30)
 */

if (!function_exists('nleta_set_timezone')) {

    function nleta_set_timezone(): void
    {
        static $initialized = false;

        if ($initialized) {
            return;
        }

        $initialized = true;

        // NLETA official application timezone
        $timezone = 'Asia/Kolkata';

        // Optional environment override
        $envTimezone = getenv('APP_TIMEZONE');

        if (
            is_string($envTimezone) &&
            trim($envTimezone) !== ''
        ) {
            $envTimezone = trim($envTimezone);

            if (
                in_array(
                    $envTimezone,
                    DateTimeZone::listIdentifiers(),
                    true
                )
            ) {
                $timezone = $envTimezone;
            }
        }

        date_default_timezone_set($timezone);
    }
}


/**
 * Initialize timezone immediately.
 */
nleta_set_timezone();


/**
 * NLETA application timezone name.
 */
if (!defined('NLETA_TIMEZONE')) {
    define('NLETA_TIMEZONE', 'Asia/Kolkata');
}


/**
 * Return current IST date/time.
 *
 * Example:
 * 17/09/2026 10:30 AM IST
 */
if (!function_exists('nleta_now')) {

    function nleta_now(): DateTime
    {
        return new DateTime(
            'now',
            new DateTimeZone(NLETA_TIMEZONE)
        );
    }
}


/**
 * Return current IST datetime for MySQL DATETIME.
 *
 * Example:
 * 2026-09-17 10:30:00
 */
if (!function_exists('nleta_now_mysql')) {

    function nleta_now_mysql(): string
    {
        return nleta_now()->format('Y-m-d H:i:s');
    }
}


/**
 * Format a datetime for display in IST.
 *
 * IMPORTANT:
 *
 * 1. Plain MySQL DATETIME:
 *    2026-09-17 10:30:00
 *    -> treated as IST
 *
 * 2. Explicit UTC timestamp:
 *    2026-09-17T05:00:00Z
 *    -> converted to IST
 *
 * 3. Timestamp with offset:
 *    2026-09-17T05:00:00+00:00
 *    -> converted to IST
 */
if (!function_exists('nleta_format_ist')) {

    function nleta_format_ist(
        $value,
        string $format = 'd / m / Y h:i A'
    ): string {

        if (
            $value === null ||
            trim((string)$value) === ''
        ) {
            return '—';
        }

        try {

            $value = trim((string)$value);

            /*
             * If timezone is explicitly present,
             * PHP knows the source timezone.
             */
            if (
                preg_match(
                    '/(?:Z|[+-]\d{2}:?\d{2})$/i',
                    $value
                )
            ) {

                $date = new DateTime($value);

            } else {

                /*
                 * Plain MySQL DATETIME.
                 *
                 * NLETA stores application dates in IST,
                 * so DO NOT treat this as UTC.
                 */
                $date = new DateTime(
                    $value,
                    new DateTimeZone(NLETA_TIMEZONE)
                );
            }

            // Final display timezone = IST
            $date->setTimezone(
                new DateTimeZone(NLETA_TIMEZONE)
            );

            return $date->format($format) . ' IST';

        } catch (Throwable $e) {

            return htmlspecialchars(
                (string)$value,
                ENT_QUOTES,
                'UTF-8'
            );
        }
    }
}


/**
 * Convert an actual UTC timestamp to IST.
 *
 * Use this ONLY when you know the source value is UTC.
 */
if (!function_exists('nleta_utc_to_ist')) {

    function nleta_utc_to_ist(
        $value,
        string $format = 'd / m / Y h:i A'
    ): string {

        if (
            $value === null ||
            trim((string)$value) === ''
        ) {
            return '—';
        }

        try {

            $date = new DateTime(
                (string)$value,
                new DateTimeZone('UTC')
            );

            $date->setTimezone(
                new DateTimeZone(NLETA_TIMEZONE)
            );

            return $date->format($format) . ' IST';

        } catch (Throwable $e) {

            return htmlspecialchars(
                (string)$value,
                ENT_QUOTES,
                'UTF-8'
            );
        }
    }
}