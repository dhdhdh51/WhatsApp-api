<?php
declare(strict_types=1);

namespace App;

/**
 * Very tolerant parser for the daily report a BC sends inside WhatsApp.
 *
 * Accepts:  "APY: 2", "APY 2", "APY-2", "apy=2", "*APY*: २", Hindi/Gujarati/... digits,
 *           aliases (JJBY, SBY, JDY, Jan Dhan, ...), blank fields (= 0),
 *           and the short form "2 1 3 5" (APY, PMJJBY, PMSBY, PMJDY).
 *
 * parse() returns one of:
 *   ['type' => 'command', 'command' => 'FORM'|'STATUS'|'HELP'|'STOP'|'START']
 *   ['type' => 'yes'] / ['type' => 'no']     (only meaningful when a confirmation is pending)
 *   ['type' => 'ack']                         (ok / thanks / emoji – no reply needed)
 *   ['type' => 'report', 'values' => [...], 'blank' => [...], 'date' => ?string, 'short' => bool]
 *   ['type' => 'error', 'errors' => [...], 'date' => ?string]
 *   ['type' => 'empty_form']                  (form sent back without any number)
 *   ['type' => 'unknown']
 */
final class ReportParser
{
    public const SCHEMES = ['apy', 'pmjjby', 'pmsby', 'pmjdy', 'apy_off', 'pmjjby_off', 'pmsby_off'];
    public const ONLINE = ['apy', 'pmjjby', 'pmsby', 'pmjdy'];
    public const OFFLINE = ['apy_off', 'pmjjby_off', 'pmsby_off'];
    public const LABELS = ['apy' => 'APY', 'pmjjby' => 'PMJJBY', 'pmsby' => 'PMSBY', 'pmjdy' => 'PMJDY',
        'apy_off' => 'APY OFFLINE', 'pmjjby_off' => 'JJBY OFFLINE', 'pmsby_off' => 'SBY OFFLINE'];

    /** Regex alternatives per scheme (input is already lower-cased). Longest forms first. */
    private const OFF = '\\s*[-_]?\\s*(?:off\\s*-?\\s*line|ofline|ऑफ\\s*लाइन|ऑफलाइन|ओफलाइन)';

    private const ALIASES = [
        // offline variants first – "APY OFFLINE" must not be read as "APY" + garbage
        'pmjjby_off' => ['(?:pm\\s*-?\\s*)?jjby' . self::OFF, 'jeevan\\s*jyoti' . self::OFF],
        'pmsby_off'  => ['(?:pm\\s*-?\\s*)?sby' . self::OFF, 'suraksha' . self::OFF],
        'apy_off'    => ['apy' . self::OFF, 'atal\\s*pension' . self::OFF],
        'pmjjby' => ['pm\s*-?\s*jjby', 'jjby', 'jeevan\s*jyoti', 'jivan\s*jyoti', 'जीवन\s*ज्योति'],
        'pmsby'  => ['pm\s*-?\s*sby', 'sby', 'suraksha\s*bima', 'suraksha', 'सुरक्षा\s*बीमा', 'सुरक्षा'],
        'pmjdy'  => ['pm\s*-?\s*jdy', 'jdy', 'jan\s*-?\s*dhan', 'जन\s*-?\s*धन', 'जनधन'],
        'apy'    => ['apy', 'atal\s*pension', 'अटल\s*पेंशन'],
    ];

    private const ZERO_WORDS = ['nil', 'nill', 'nahi', 'nahin', 'nai', 'no', 'none', 'zero', 'shunya', 'sunya',
        'शून्य', 'नहीं', 'नही', 'x', '-', '--', 'na', 'n/a', 'koi nahi'];

    private const COMMANDS = [
        'FORM'   => ['form', 'farm', 'from', 'फॉर्म', 'फार्म', 'form bhejo', 'form do', 'form send', 'report form'],
        'STATUS' => ['status', 'stetus', 'statas', 'स्टेटस', 'स्थिति', 'sthiti', 'meri report'],
        'HELP'   => ['help', 'madad', 'मदद', 'हेल्प', 'menu'],
        'STOP'   => ['stop', 'बंद', 'band', 'band karo', 'unsubscribe'],
        'START'  => ['start', 'शुरू', 'shuru', 'resume', 'chalu'],
    ];

    private const YES = ['haan', 'han', 'ha', 'haa', 'haan ji', 'ha ji', 'haji', 'yes', 'y', 'confirm', 'sahi',
        'sahi hai', 'theek hai', 'thik hai', 'हाँ', 'हां', 'हा', 'सही', 'सही है'];
    private const NO = ['nahi', 'nahin', 'nai', 'no', 'n', 'galat', 'cancel', 'नहीं', 'नही', 'गलत'];

    private const ACK = ['ok', 'okay', 'okk', 'k', 'ji', 'ok ji', 'thanks', 'thank you', 'thankyou', 'thx', 'ty',
        'dhanyavad', 'dhanyawad', 'धन्यवाद', 'shukriya', 'done', 'hello', 'hi', 'namaste', 'नमस्ते', 'good night',
        'good morning', 'gm', 'gn'];

    public function __construct(private int $maxValue = 500)
    {
    }

    public function parse(string $text): array
    {
        $norm = self::normalize($text);

        // Commands / yes / no / ack are only recognised when they are the whole message.
        $plain = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{M}\p{N}\s\/]/u', ' ', $norm)));
        if ($plain === '' && trim($norm) === '?') {
            return ['type' => 'command', 'command' => 'HELP'];
        }
        foreach (self::COMMANDS as $cmd => $words) {
            if (in_array($plain, $words, true)) {
                return ['type' => 'command', 'command' => $cmd];
            }
        }
        if (in_array($plain, self::YES, true)) {
            return ['type' => 'yes'];
        }
        if (in_array($plain, self::NO, true)) {
            return ['type' => 'no'];
        }
        if ($plain === '' || in_array($plain, self::ACK, true)) {
            return ['type' => 'ack'];
        }

        // Pull the date out of a copied form header ("Daily Report - 11-10-2026") and drop those lines.
        $date = null;
        $lines = preg_split('/\R/u', $norm) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/daily\s*report\s*[-:–]*\s*(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/u', $line, $dm)) {
                $y = (int) $dm[3];
                if ($y < 100) {
                    $y += 2000;
                }
                if (checkdate((int) $dm[2], (int) $dm[1], $y)) {
                    $date = sprintf('%04d-%02d-%02d', $y, (int) $dm[2], (int) $dm[1]);
                }
                continue;
            }
            if (preg_match('/^\s*(naam|name|नाम|bc\s*id|bc\s*code)\s*[:\-=]/u', $line)) {
                continue;
            }
            $kept[] = $line;
        }

        $found = $this->parseKeyed($kept);
        if ($found === null) {
            return $this->parseShort(implode("\n", $kept), $date);
        }

        [$values, $blank, $errors] = $found;
        if ($errors) {
            return ['type' => 'error', 'errors' => $errors, 'date' => $date];
        }

        // Missing fields are treated exactly like blank ones.
        foreach (self::SCHEMES as $s) {
            if (!array_key_exists($s, $values)) {
                $blank[$s] = true;
                $values[$s] = 0;
            }
        }
        if (count($blank) === count(self::SCHEMES)) {
            return ['type' => 'empty_form', 'date' => $date];
        }

        $ordered = [];
        foreach (self::SCHEMES as $s) {
            $ordered[$s] = $values[$s];
        }
        $blankList = array_values(array_filter(self::SCHEMES, fn ($s) => isset($blank[$s])));

        return ['type' => 'report', 'values' => $ordered, 'blank' => $blankList, 'date' => $date, 'short' => false];
    }

    /** @return array{0: array<string,int>, 1: array<string,bool>, 2: list<string>}|null  null = no scheme keyword at all */
    private function parseKeyed(array $lines): ?array
    {
        $re = self::keywordRegex();
        $values = [];
        $blank = [];
        $errors = [];
        $any = false;

        foreach ($lines as $line) {
            if (!preg_match_all($re, $line, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }
            $count = count($m);
            for ($i = 0; $i < $count; $i++) {
                $any = true;
                $key = self::schemeFromMatch($m[$i]);
                $start = $m[$i][0][1] + strlen($m[$i][0][0]);
                $end = $i + 1 < $count ? $m[$i + 1][0][1] : strlen($line);
                $seg = substr($line, $start, $end - $start);
                $res = $this->parseSegment($seg);
                $label = self::LABELS[$key];

                if ($res['status'] === 'error') {
                    $errors[$key] = self::errorText($label, $res['reason'], $this->maxValue);
                    continue;
                }
                if ($res['status'] === 'blank') {
                    if (!array_key_exists($key, $values)) {
                        $blank[$key] = true;
                    }
                    continue;
                }
                // value
                $v = $res['value'];
                if (array_key_exists($key, $values) && !isset($blank[$key]) && $values[$key] !== $v) {
                    $errors[$key] = "{$label} do baar alag-alag likha hai ({$values[$key]} aur {$v})";
                    continue;
                }
                unset($blank[$key]);
                $values[$key] = $v;
            }
        }

        if (!$any) {
            return null;
        }
        foreach (array_keys($blank) as $k) {
            $values[$k] = 0;
        }
        // keep error order stable
        $ordered = [];
        foreach (self::SCHEMES as $s) {
            if (isset($errors[$s])) {
                $ordered[] = $errors[$s];
            }
        }
        return [$values, $blank, $ordered];
    }

    /** @return array{status:string, value?:int, reason?:string} */
    private function parseSegment(string $seg): array
    {
        $s = trim($seg);
        // "APY: -2" / "APY = -2" => negative. ("APY-2" / "APY - 2" => 2)
        if (preg_match('/^[:=]+\s*-\s*\d/u', $s)) {
            return ['status' => 'error', 'reason' => 'negative'];
        }
        if (preg_match('/^-\s*-\s*\d/u', $s)) {
            return ['status' => 'error', 'reason' => 'negative'];
        }
        $sep = '/^[\s:=\-.,;|>)\](}]+/u';
        $s = trim((string) preg_replace($sep, '', $s));
        // filler words like "Jan Dhan account: 5", "APY khate - 2"
        $s = trim((string) preg_replace('/^(?:(?:accounts?|a\/c|ac|khat[ae]|khaate|enrol\w*|yojana|scheme|policy|policies|ke|ki|ka|count|total)\b\s*)+/u', '', $s));
        $s = trim((string) preg_replace($sep, '', $s));
        $s = trim((string) preg_replace('/[\s.,;|]+$/u', '', $s));

        if ($s === '') {
            return ['status' => 'blank'];
        }
        if (in_array($s, self::ZERO_WORDS, true)) {
            return ['status' => 'value', 'value' => 0];
        }
        if (!preg_match('/[\p{L}\p{N}]/u', $s)) {
            // only punctuation / emoji, e.g. "PMJDY (Jan Dhan): 5" -> the "(" part
            return ['status' => 'blank'];
        }
        if (preg_match('/^(\d+)(.*)$/su', $s, $mm)) {
            $tail = trim($mm[2]);
            if (preg_match('/^[.,]\s*\d/u', $tail)) {
                return ['status' => 'error', 'reason' => 'decimal'];
            }
            if (preg_match('/\d/u', $tail)) {
                return ['status' => 'error', 'reason' => 'unclear'];
            }
            if (strlen($mm[1]) > 6 || (int) $mm[1] > $this->maxValue) {
                return ['status' => 'error', 'reason' => 'too_large'];
            }
            return ['status' => 'value', 'value' => (int) $mm[1]];
        }
        if (in_array($s, self::ZERO_WORDS, true)) {
            return ['status' => 'value', 'value' => 0];
        }
        return ['status' => 'error', 'reason' => 'unclear'];
    }

    private function parseShort(string $text, ?string $date): array
    {
        $t = trim($text);
        if (preg_match('/^\d+(?:[\s,;\/|\-]+\d+)*$/u', $t)) {
            $nums = preg_split('/[\s,;\/|\-]+/u', $t) ?: [];
            if (count($nums) === 4 || count($nums) === 7) {
                $values = array_fill_keys(self::SCHEMES, 0);
                $errors = [];
                foreach ($nums as $i => $raw) {
                    $s = self::SCHEMES[$i];
                    if (strlen($raw) > 6 || (int) $raw > $this->maxValue) {
                        $errors[] = self::errorText(self::LABELS[$s], 'too_large', $this->maxValue);
                    }
                    $values[$s] = (int) $raw;
                }
                if ($errors) {
                    return ['type' => 'error', 'errors' => $errors, 'date' => $date];
                }
                return ['type' => 'report', 'values' => $values, 'blank' => [], 'date' => $date, 'short' => true];
            }
        }
        if (preg_match('/^-?\d+([\s,;\/|\-]+-?\d+)*$/u', $t)) {
            return ['type' => 'error', 'date' => $date,
                'errors' => ['4 number bhejein (APY PMJJBY PMSBY PMJDY, jaise: 2 1 3 5) ya offline ke saath 7 number (jaise: 2 1 3 5 0 1 0)']];
        }
        if (preg_match('/^\d+[.,]\d+/u', $t)) {
            return ['type' => 'error', 'date' => $date, 'errors' => ['Sirf poore number likhein (jaise 2), point wala nahi']];
        }
        return ['type' => 'unknown'];
    }

    private static function errorText(string $label, string $reason, int $max): string
    {
        return match ($reason) {
            'negative'  => "{$label} mein minus (-) number nahi chalega",
            'decimal'   => "{$label} mein sirf poora number likhein (jaise 2), point wala nahi",
            'too_large' => "{$label} ka number bahut bada hai (max {$max})",
            default     => "{$label} ka number samajh nahi aaya",
        };
    }

    private static ?string $re = null;

    public static function keywordRegex(): string
    {
        if (self::$re === null) {
            $parts = [];
            foreach (self::ALIASES as $key => $alts) {
                $parts[] = '(?<' . $key . '>' . implode('|', $alts) . ')';
            }
            // not preceded by a latin letter/digit, not followed by a latin letter
            self::$re = '/(?<![a-z0-9])(?:' . implode('|', $parts) . ')(?![a-z])/u';
        }
        return self::$re;
    }

    private static function schemeFromMatch(array $match): string
    {
        foreach (array_keys(self::ALIASES) as $key) {
            if (isset($match[$key]) && $match[$key][1] !== -1 && $match[$key][0] !== '') {
                return $key;
            }
        }
        return 'apy';
    }

    /** True when $text contains any scheme keyword (used to validate templates). */
    public static function containsKeyword(string $text): bool
    {
        return (bool) preg_match(self::keywordRegex(), self::normalize($text));
    }

    public static function normalize(string $text): string
    {
        // Indian-language & Arabic-Indic digits -> ASCII
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach ([0x0966, 0x09E6, 0x0A66, 0x0AE6, 0x0B66, 0x0BE6, 0x0C66, 0x0CE6, 0x0D66, 0x0660, 0x06F0, 0xFF10] as $base) {
                for ($d = 0; $d <= 9; $d++) {
                    $map[mb_chr($base + $d, 'UTF-8')] = (string) $d;
                }
            }
            $map += [
                "\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{202F}" => ' ',
                "\u{200B}" => '', "\u{200C}" => '', "\u{200D}" => '', "\u{FEFF}" => '', "\u{2060}" => '',
                "\u{2212}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2010}" => '-', "\u{2011}" => '-',
                "\u{FF1A}" => ':', "\u{FF1D}" => '=', "\u{0964}" => '.',
                '*' => '', '_' => ' ', '~' => '', '`' => '',
            ];
        }
        $text = strtr($text, $map);
        $text = mb_strtolower($text, 'UTF-8');
        return $text;
    }
}
