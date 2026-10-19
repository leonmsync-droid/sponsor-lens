<?php
declare(strict_types=1);

// Display helpers for templates.
final class Format
{
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function int(?float $n): string
    {
        return $n === null ? '–' : number_format((float) round($n));
    }

    public static function pct(?float $n, bool $signed = false): string
    {
        if ($n === null) {
            return '–';
        }
        $s = number_format($n * 100, 0) . '%';
        return $signed && $n > 0 ? '+' . $s : $s;
    }

    public static function money(float $n): string
    {
        return '€' . number_format($n, $n < 100 ? 2 : 0);
    }

    // Emoji flag from an ISO country code (GB -> 🇬🇧). Empty when unknown.
    public static function flag(?string $cc): string
    {
        if (!$cc || !preg_match('/^[A-Z]{2}$/', $cc)) {
            return '';
        }
        return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
    }

    public static function date(?string $ts): string
    {
        return $ts ? gmdate('j M', strtotime($ts)) : '–';
    }

    public static function dateTime(?string $ts): string
    {
        return $ts ? gmdate('j M, H:i', strtotime($ts)) . ' UTC' : '–';
    }
}
