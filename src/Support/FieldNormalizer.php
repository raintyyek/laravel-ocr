<?php

declare(strict_types=1);

namespace Raintyyek\Ocr\Support;

use Raintyyek\Ocr\Documents\Money;

/**
 * Shared, provider-agnostic normalization of dates, money and currency.
 *
 * Every extractor — heuristic, AWS AnalyzeExpense, Google Document AI — funnels
 * its raw field text through here, so there is exactly one place that knows how
 * to read "RM 1.234,56", "15 Julai 2026" or "2026年7月15日". Keeping it static
 * and stateless makes it trivial to reuse and to unit-test.
 */
final class FieldNormalizer
{
    /** Currency symbols/codes recognised in amount tokens. */
    public const CURRENCY = '(?:RM|MYR|USD|SGD|EUR|GBP|AUD|IDR|THB|PHP|JPY|CNY|HKD|RMB|HK\$|S\$|\$|€|£|¥|元)';

    /** Month tokens (English + Malay + abbreviations) → month number. */
    private const MONTHS = [
        'jan' => 1, 'januari' => 1, 'january' => 1,
        'feb' => 2, 'februari' => 2, 'february' => 2,
        'mar' => 3, 'march' => 3, 'mac' => 3,
        'apr' => 4, 'april' => 4,
        'may' => 5, 'mei' => 5,
        'jun' => 6, 'june' => 6,
        'jul' => 7, 'july' => 7, 'julai' => 7,
        'aug' => 8, 'august' => 8, 'ogos' => 8, 'ogo' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10, 'okt' => 10, 'oktober' => 10,
        'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12, 'dis' => 12, 'disember' => 12,
    ];

    /** Parse a raw money token into a {@see Money} (decimal string + currency). */
    public static function money(string $raw, ?string $fallbackCurrency = null): ?Money
    {
        $amount = self::amount($raw);

        return $amount === null ? null : new Money($amount, self::currency($raw) ?? $fallbackCurrency);
    }

    /**
     * Turn "RM 1,234.50" / "(1.234,56)" / "1234.5元" into a clean decimal string,
     * handling both "1,234.56" and "1.234,56" grouping and negatives.
     */
    public static function amount(string $raw): ?string
    {
        $t = preg_replace('/[^0-9.,()\-]/', '', $raw) ?? '';

        if ($t === '' || ! preg_match('/\d/', $t)) {
            return null;
        }

        $negative = (str_contains($t, '(') && str_contains($t, ')')) || str_starts_with(trim($raw), '-');
        $t        = str_replace(['(', ')', '-'], '', $t);

        $lastComma = strrpos($t, ',');
        $lastDot   = strrpos($t, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $t = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $t))
                : str_replace(',', '', $t);
        } elseif ($lastComma !== false) {
            $t = (substr_count($t, ',') === 1 && preg_match('/,\d{1,2}$/', $t))
                ? str_replace(',', '.', $t)
                : str_replace(',', '', $t);
        } elseif (substr_count($t, '.') > 1) {
            $t = str_replace('.', '', $t);
        }

        if (! is_numeric($t)) {
            return null;
        }

        return ($negative ? '-' : '') . $t;
    }

    /** Detect a currency code from a symbol/code anywhere in the text. */
    public static function currency(string $text): ?string
    {
        if (preg_match('/\b(MYR|USD|SGD|EUR|GBP|AUD|IDR|THB|PHP|JPY|CNY|HKD|RMB)\b/i', $text, $m)) {
            return strtoupper($m[1]) === 'RMB' ? 'CNY' : strtoupper($m[1]);
        }

        return match (true) {
            str_contains($text, 'RM')  => 'MYR',
            str_contains($text, 'HK$') => 'HKD',
            str_contains($text, 'S$')  => 'SGD',
            str_contains($text, '€')   => 'EUR',
            str_contains($text, '£')   => 'GBP',
            str_contains($text, '¥'), str_contains($text, '元'), str_contains($text, '人民币') => 'CNY',
            str_contains($text, '$')   => 'USD',
            default                    => null,
        };
    }

    /**
     * Normalize a date token to Y-m-d, understanding Chinese (年月日), Malay and
     * English month names, and numeric day-first / month-first formats.
     */
    public static function date(string $raw, bool $dayFirst = true): ?string
    {
        $raw = trim($raw);

        if (preg_match('/(\d{4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})/u', $raw, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/\b(\d{1,2})[\s.\-]+(\p{L}+)\.?[\s.\-]+(\d{2,4})\b/u', $raw, $m)) {
            $month = self::monthNumber($m[2]);
            if ($month !== null) {
                return self::ymd(self::year4((int) $m[3]), $month, (int) $m[1]);
            }
        }

        if (preg_match('/\b(\p{L}+)\.?\s+(\d{1,2}),?\s+(\d{4})\b/u', $raw, $m)) {
            $month = self::monthNumber($m[1]);
            if ($month !== null) {
                return self::ymd((int) $m[3], $month, (int) $m[2]);
            }
        }

        $numeric = $dayFirst
            ? ['d/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'j/n/Y', 'j-n-Y']
            : ['m/d/Y', 'm-d-Y', 'm/d/y', 'n/j/Y'];

        foreach (array_merge($numeric, ['Y-m-d', 'Y/m/d', 'Y.m.d']) as $fmt) {
            $dt     = \DateTime::createFromFormat('!' . $fmt, $raw);
            $errors = \DateTime::getLastErrors();

            if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    /** Format a float as a 2-decimal string (money-safe). */
    public static function format(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private static function monthNumber(string $token): ?int
    {
        $token = strtolower(trim($token, " .\t"));

        return self::MONTHS[$token] ?? self::MONTHS[substr($token, 0, 3)] ?? null;
    }

    private static function year4(int $y): int
    {
        return $y < 100 ? 2000 + $y : $y;
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }
}
