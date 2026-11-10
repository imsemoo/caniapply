<?php

namespace App\Services\Normalization;

/** Feeds deliver HTML from strangers. Nothing but plain text gets stored. */
class TextCleaner
{
    private const MAX_BYTES = 102400;

    public static function plain(string $html): string
    {
        // Past the regex limits a preg call returns null and the text is lost. Bad bytes do the same under /u.
        $html = mb_scrub(mb_strcut($html, 0, self::MAX_BYTES, 'UTF-8'), 'UTF-8');
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $text);
        $text = preg_replace('#<li\b[^>]*>#i', "\n- ", $text);
        $text = preg_replace('#<br\s*/?>|</(p|div|h[1-6]|ul|ol|li|tr)>#i', "\n", $text);
        $text = strip_tags($text);
        // Doubly encoded markup only becomes markup at this second decode.
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = preg_replace('/ ?\n ?/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /** Arabic-Indic digits, for parsers only. Stored text keeps what the author wrote. */
    public static function westernDigits(string $text): string
    {
        return strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => ',', '٫' => '.']);
    }

    public static function normalizedTitle(string $title): string
    {
        $title = mb_strtolower($title);
        $title = preg_replace('/\((m|f|w|d|x|all genders)[^)]*\)|\b(remote|hybrid|on-?site|worldwide|anywhere)\b/u', ' ', $title);
        $title = preg_replace('/[^\p{L}\p{N}#+.]+/u', ' ', $title);

        return trim(preg_replace('/\s+/', ' ', $title));
    }

    public static function normalizedCompany(?string $company): ?string
    {
        if ($company === null || trim($company) === '') {
            return null;
        }

        $name = mb_strtolower($company);
        $name = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $name);
        $name = preg_replace('/\b(inc|llc|ltd|limited|gmbh|uab|bv|b v|ag|sa|srl|plc|corp|corporation|co|company|group|technologies|technology|labs|software|solutions|io)\b/u', ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return $name !== '' ? $name : mb_strtolower(trim($company));
    }
}
