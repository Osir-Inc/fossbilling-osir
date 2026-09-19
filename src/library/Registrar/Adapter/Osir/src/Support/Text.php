<?php

declare(strict_types=1);

namespace Osir\FossBilling\Support;

/**
 * Sanitising helpers for text that crosses a trust boundary (upstream error messages,
 * values written to logs): strips control characters to prevent log forging, redacts
 * secrets, and caps the length.
 */
final class Text
{
    public static function sanitize(string $text, int $maxLength = 500): string
    {
        // Drop invalid UTF-8 rather than passing broken bytes to a log sink or a browser.
        $clean = mb_scrub($text, 'UTF-8');
        // Replace every character that can fake a new log line or reorder what a viewer displays:
        // C0/C1 controls (CR, LF, TAB, …), Unicode line/paragraph separators, bidi embeddings,
        // overrides and isolates, and zero-width characters.
        $clean = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}\x{FEFF}]/u', ' ', $clean);
        $clean = trim((string) preg_replace('/\s{2,}/', ' ', $clean));
        $clean = Redactor::redactText($clean);

        if (mb_strlen($clean, 'UTF-8') > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength, 'UTF-8') . '…';
        }

        return $clean;
    }
}
