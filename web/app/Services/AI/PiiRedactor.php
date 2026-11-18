<?php

namespace App\Services\AI;

/**
 * Pseudonymises text before it is sent to an AI provider.
 *
 * Masks e-mail addresses, URLs, phone numbers, IBANs, payment card numbers
 * and IP addresses, plus a project-specific list of terms (participant
 * names, places, organisations ...). Masking is deterministic, so identical
 * input yields identical output (important for embedding caches and for the
 * consistency between training and prediction units in autocoding).
 */
class PiiRedactor
{
    /** @var string[] */
    protected array $terms;

    protected int $count = 0;

    /**
     * @param  string[]  $terms  project-specific terms to mask
     */
    public function __construct(array $terms = [])
    {
        $terms = array_values(array_unique(array_filter(array_map(
            static fn ($t) => trim((string) $t),
            $terms
        ), static fn ($t) => mb_strlen($t) >= 2)));
        // longest first so that "Anna Maria" wins over "Anna"
        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $this->terms = $terms;
    }

    /**
     * Number of replacements made since construction.
     */
    public function count(): int
    {
        return $this->count;
    }

    public function redact(string $text): string
    {
        $patterns = [
            // e-mail
            '/[\p{L}0-9._%+\-]+@[\p{L}0-9.\-]+\.[\p{L}]{2,}/u' => '[EMAIL]',
            // URLs
            '/\b(?:https?:\/\/|www\.)[^\s<>"\']+/iu' => '[URL]',
            // IBAN (e.g. DE89 3704 0044 0532 0130 00)
            '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,7}(?:[ ]?[A-Z0-9]{1,4})?\b/' => '[IBAN]',
            // IPv4
            '/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/' => '[IP]',
            // IPv6 (full or compressed with at least three groups)
            '/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i' => '[IP]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace_callback($pattern, function () use ($replacement) {
                $this->count++;

                return $replacement;
            }, $text) ?? $text;
        }

        // payment cards: 13–19 digits, Luhn-valid
        $text = preg_replace_callback('/\b(?:\d[ \-]?){12,18}\d\b/', function ($m) {
            $digits = preg_replace('/\D/', '', $m[0]);
            if (self::luhn($digits)) {
                $this->count++;

                return '[CARD]';
            }

            return $m[0];
        }, $text) ?? $text;

        // phone numbers: optional +country, at least 7 digits with common separators
        $text = preg_replace_callback('/(?<![\w\/])(?:\+|00)?\d[\d \-\/().]{6,}\d(?![\w\/])/u', function ($m) {
            $digits = preg_replace('/\D/', '', $m[0]);
            // avoid years, ranges and short numbers
            if (strlen($digits) < 7 || preg_match('/^\d{4}\s*[-–]\s*\d{4}$/', trim($m[0]))) {
                return $m[0];
            }
            $this->count++;

            return '[PHONE]';
        }, $text) ?? $text;

        foreach ($this->terms as $term) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu';
            $text = preg_replace_callback($pattern, function () {
                $this->count++;

                return '[REDACTED]';
            }, $text) ?? $text;
        }

        return $text;
    }

    /**
     * @param  string[]  $texts
     * @return string[]
     */
    public function redactAll(array $texts): array
    {
        return array_map(fn ($t) => $this->redact((string) $t), $texts);
    }

    public static function luhn(string $digits): bool
    {
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = $len - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }
}
