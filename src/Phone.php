<?php
declare(strict_types=1);

namespace App;

/** Indian mobile number helpers. Stored form: 10 digits, starting with 6-9. */
final class Phone
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $d = preg_replace('/\D+/', '', ReportParser::normalize($raw)) ?? '';
        if (strlen($d) === 13 && str_starts_with($d, '091')) {
            $d = substr($d, 3);
        } elseif (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
    }

    public static function toChatId(string $tenDigits): string
    {
        return '91' . $tenDigits . '@c.us';
    }

    /** "919876543210@c.us" / "919876543210@s.whatsapp.net" -> "9876543210" (null for @lid, groups …). */
    public static function fromChatId(?string $jid): ?string
    {
        if (!$jid || !preg_match('/^(\d+)(?::\d+)?@(c\.us|s\.whatsapp\.net)$/', $jid, $m)) {
            return null;
        }
        return self::normalize($m[1]);
    }

    public static function display(?string $tenDigits): string
    {
        if (!$tenDigits) {
            return '';
        }
        return '+91 ' . substr($tenDigits, 0, 5) . ' ' . substr($tenDigits, 5);
    }
}
