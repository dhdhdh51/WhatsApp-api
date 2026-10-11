<?php
declare(strict_types=1);

namespace App;

/**
 * The daily form block + editable message templates with rotating wording variants.
 *
 * Placeholders: {form} (required except in "welcome"), {name}, {date}, {bc_code}, {cutoff}, {form_time}
 */
final class Templates
{
    public const TYPES = [
        'form'           => ['Daily form', 'दैनिक फॉर्म'],
        'reminder'       => ['Reminder', 'रिमाइंडर'],
        'final_reminder' => ['Final reminder', 'अंतिम रिमाइंडर'],
        'welcome'        => ['Welcome (new BC)', 'स्वागत संदेश (नया बीसी)'],
    ];

    /** Types that are copied back by the BC and therefore must contain exactly one {form}. */
    public const FORM_TYPES = ['form', 'reminder', 'final_reminder'];

    public const INSTRUCTION = '👆 Is message ko copy karke numbers bharein aur bhej dein. Kuch nahi kiya to 0 likhein.';

    public const FALLBACK = [
        'form'           => "{form}\n\n" . self::INSTRUCTION,
        'reminder'       => "⏰ {name} ji, aaj ki report abhi baaki hai.\n\n{form}\n\n" . self::INSTRUCTION,
        'final_reminder' => "⚠️ {name} ji, aakhri reminder! Report {cutoff} tak bhej dein.\n\n{form}\n\n" . self::INSTRUCTION,
        'welcome'        => "🙏 Namaste {name} ji!\n\nAapko *BC Daily Report* system mein jod diya gaya hai ✅\nBC ID: {bc_code}\n\n📌 *Kaise kaam karega*\n1️⃣ Roz {form_time} baje isi number se aapko ek form aayega.\n2️⃣ Form copy karein, har line ke aage aaj ka number likhein aur bhej dein:\nAPY: 2\nPMJJBY: 1\nPMSBY: 3\nPMJDY: 5\n3️⃣ Kuch nahi kiya to 0 likhein.\n4️⃣ Chhota tareeka: sirf 4 number bhejein – 2 1 3 5 (kram: APY, PMJJBY, PMSBY, PMJDY)\n5️⃣ Galti ho jaye to {cutoff} tak form dobara bhej dein – report update ho jayegi.\n\n🔤 *Commands*\nFORM – aaj ka form mangwayein\nSTATUS – aaj ki report dekhein\nHELP – madad\n\n👉 Kripya is number ko apne phone mein save kar lein. Dhanyavad 🙏",
    ];

    /** The blank form block the BC copies. */
    public static function formBlock(string $name, string $date): string
    {
        return "📋 Daily Report - " . date('d-m-Y', strtotime($date)) . "\n"
            . "Naam: {$name}\n\n"
            . "APY:\nPMJJBY:\nPMSBY:\nPMJDY:";
    }

    /** Full blank form message as used in error replies / FORM command. */
    public static function blankFormMessage(string $name, string $date): string
    {
        return self::formBlock($name, $date) . "\n\n" . self::INSTRUCTION;
    }

    public static function render(string $body, array $bc, string $date): string
    {
        $vars = [
            '{form}'    => self::formBlock((string) $bc['name'], $date),
            '{name}'    => (string) $bc['name'],
            '{date}'    => date('d-m-Y', strtotime($date)),
            '{bc_code}' => (string) ($bc['bc_code'] ?? ''),
            '{cutoff}'  => fmt_time12(Settings::padTime(Settings::get('cutoff_time') ?: '23:59')),
            '{form_time}' => fmt_time12(Settings::padTime(Settings::get('form_time') ?: '18:00')),
        ];
        return strtr($body, $vars);
    }

    /** Picks a variant – rotates by day and BC so neighbours don't receive identical text. */
    public static function pick(string $type, int $bcId, string $date): string
    {
        $rows = DB::all('SELECT body FROM templates WHERE type = ? AND is_active = 1 ORDER BY variant_no', [$type]);
        if (!$rows) {
            return self::FALLBACK[$type] ?? self::FALLBACK['form'];
        }
        $dayIndex = (int) floor(strtotime($date) / 86400);
        return $rows[($dayIndex + $bcId) % count($rows)]['body'];
    }

    public static function build(string $type, array $bc, string $date): string
    {
        return self::render(self::pick($type, (int) $bc['id'], $date), $bc, $date);
    }

    /** @return list<string> validation errors for a template body */
    public static function validate(string $body, string $type = 'form'): array
    {
        $errors = [];
        if (trim($body) === '') {
            $errors[] = 'Template is empty / खाली है';
            return $errors;
        }
        if (!in_array($type, self::FORM_TYPES, true)) {
            // welcome message: free text, not copied back as a report
            if (mb_strlen($body) > 3000) {
                $errors[] = 'Template too long (max 3000 chars)';
            }
            return $errors;
        }
        if (substr_count($body, '{form}') !== 1) {
            $errors[] = 'Template must contain {form} exactly once / {form} एक बार होना चाहिए';
        }
        if (ReportParser::containsKeyword(str_replace('{form}', '', $body))) {
            $errors[] = 'Do not write scheme names (APY, PMJJBY, PMSBY, PMJDY, Jan Dhan …) outside {form} – it would confuse the parser when the BC copies the message / {form} के बाहर योजना का नाम न लिखें';
        }
        if (mb_strlen($body) > 3000) {
            $errors[] = 'Template too long (max 3000 chars)';
        }
        return $errors;
    }
}
