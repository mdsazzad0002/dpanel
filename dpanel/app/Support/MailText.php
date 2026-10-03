<?php

namespace App\Support;

/**
 * Turns raw MIME text into clean UTF-8 for the webmail client.
 *
 * Message parts arrive in whatever charset the sender declared (or none at
 * all), and a single invalid byte makes the JSON response fail with
 * "Malformed UTF-8 characters", so every string leaving the IMAP layer goes
 * through here.
 */
class MailText
{
    public static function toUtf8(string $value, ?string $charset = null): string
    {
        if ($value === '') {
            return '';
        }

        $charset = self::normalizeCharset($charset);
        if ($charset !== null && $charset !== 'UTF-8') {
            $converted = self::convert($value, $charset);
            if ($converted !== null) {
                $value = $converted;
            }
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Undeclared or wrongly declared 8-bit text is almost always Windows-1252.
        if ($charset === null || $charset === 'UTF-8') {
            $converted = self::convert($value, 'Windows-1252');
            if ($converted !== null && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return mb_scrub($value, 'UTF-8');
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|li|h[1-6]|table|blockquote)\s*>#i', "\n", $html) ?? $html;
        $html = preg_replace_callback(
            '#<a\b[^>]*\bhref\s*=\s*(["\'])(https?://[^"\']+)\1[^>]*>(.*?)</a\s*>#is',
            static function (array $match): string {
                $label = trim(strip_tags($match[3]));

                return $label === '' || $label === $match[2] ? $match[2] : $label.' ('.$match[2].')';
            },
            $html
        ) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * The charset an HTML part declares in its own <meta> tag.
     */
    public static function htmlMetaCharset(string $html): ?string
    {
        if (preg_match('#<meta[^>]+charset\s*=\s*["\']?\s*([A-Za-z0-9._:-]+)#i', $html, $match)) {
            return $match[1];
        }

        return null;
    }

    private static function normalizeCharset(?string $charset): ?string
    {
        $charset = strtoupper(trim((string) $charset, " \t\"'"));
        if ($charset === '' || $charset === 'DEFAULT' || $charset === 'X-UNKNOWN') {
            return null;
        }

        return match ($charset) {
            'UTF8', 'UTF-8', 'US-ASCII', 'ASCII', 'ANSI_X3.4-1968' => 'UTF-8',
            'LATIN1', 'ISO-8859-1', 'ISO8859-1' => 'Windows-1252',
            'KS_C_5601-1987', 'KS_C_5601' => 'CP949',
            'GB2312', 'GBK' => 'GB18030',
            default => $charset,
        };
    }

    private static function convert(string $value, string $charset): ?string
    {
        try {
            if (in_array(strtolower($charset), array_map('strtolower', mb_list_encodings()), true)) {
                return mb_convert_encoding($value, 'UTF-8', $charset);
            }
        } catch (\ValueError) {
            // Fall through to iconv, which knows more charset aliases.
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $value);

        return $converted === false ? null : $converted;
    }
}
