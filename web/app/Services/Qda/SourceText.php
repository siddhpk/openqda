<?php

namespace App\Services\Qda;

use App\Models\Source;
use App\Traits\ValidatesStoragePath;

/**
 * Plain text of converted sources, used to check that segments a client
 * claims to have cut from a source really occur in it.
 *
 * Positions come from the coding editor, whose text model the server does
 * not reproduce exactly (block breaks, embeds). The check is therefore:
 *  - the span length equals the length of the claimed text (UTF-16 units,
 *    as counted in the browser),
 *  - the span lies within the source (with a small allowance for embeds),
 *  - the claimed text, with whitespace normalised, occurs in the source.
 */
class SourceText
{
    use ValidatesStoragePath;

    /** @var array<string, array{text: string, length: int, slack: int}|null> */
    private array $cache = [];

    /**
     * @param  iterable<array{source_id: string, start: int, end: int, text: string}>  $items
     * @return array<int, string> index of every failing item => reason
     */
    public function verify(iterable $items): array
    {
        $failures = [];
        foreach ($items as $i => $item) {
            $reason = $this->check($item['source_id'], (int) $item['start'], (int) $item['end'], (string) $item['text']);
            if ($reason !== null) {
                $failures[$i] = $reason;
            }
        }

        return $failures;
    }

    public function check(string $sourceId, int $start, int $end, string $text): ?string
    {
        $source = $this->load($sourceId);
        if ($source === null) {
            return 'the source has no converted text';
        }
        if ($start < 0 || $end <= $start) {
            return 'invalid span';
        }
        if ($end - $start !== self::utf16Length($text)) {
            return 'span length does not match the text';
        }
        if ($end > $source['length'] + $source['slack']) {
            return 'span lies outside the source';
        }
        $needle = self::normalize($text);
        if ($needle === '' || ! str_contains($source['text'], $needle)) {
            return 'text does not occur in the source';
        }

        return null;
    }

    /**
     * A keyed hash of a segment, so decisions can be matched to segments
     * later without storing their text.
     */
    public static function segmentHash(string $projectId, string $text): string
    {
        return hash_hmac('sha256', $projectId."\n".self::normalize($text), (string) config('app.key'));
    }

    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $text));
    }

    /** length as JavaScript counts it */
    public static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('~<(head|style|script)\b[^>]*>.*?</\1>~is', '', $html);
        $html = (string) preg_replace('~<(br|hr)\b[^>]*>|</(p|div|li|h[1-6]|tr|blockquote|pre|table|ul|ol)>~i', "$0\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return array{text: string, length: int, slack: int}|null
     */
    private function load(string $sourceId): ?array
    {
        if (array_key_exists($sourceId, $this->cache)) {
            return $this->cache[$sourceId];
        }
        $converted = Source::find($sourceId)?->converted;
        $path = $converted && $converted->path ? $this->validateStoragePath($converted->path) : null;
        if ($path === null) {
            return $this->cache[$sourceId] = null;
        }
        $html = (string) file_get_contents($path);
        $plain = self::htmlToText($html);
        $length = self::utf16Length($plain);

        return $this->cache[$sourceId] = [
            'text' => self::normalize($plain),
            'length' => $length,
            // embeds count as one character in the editor; block breaks may differ slightly
            'slack' => substr_count(strtolower($html), '<img') + 16 + intdiv($length, 100),
        ];
    }
}
