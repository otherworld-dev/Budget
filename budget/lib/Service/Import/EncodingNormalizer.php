<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

/**
 * Normalizes an uploaded statement to UTF-8 (#371).
 *
 * The old inline version tried ISO-8859-1, then Windows-1252, then
 * ISO-8859-15 — but ISO-8859-1 assigns a character to all 256 byte values, so
 * `mb_check_encoding($content, 'ISO-8859-1')` can never fail and the two
 * fallbacks behind it were unreachable. Every non-UTF-8 upload was decoded as
 * Latin-1 whatever it really was, which turned a Windows-1251 statement into
 * "Ïÿò¸ðî÷êà" and a Shift-JIS one into "ûÀ;út;àz", silently.
 *
 * Single-byte encodings cannot be told apart by validity — they all accept
 * every byte — so guessing between them is not possible, and sniffing the
 * multi-byte encodings turned out to be worse than useless: measured against
 * Windows-1252 samples, GB18030 accepts ordinary German, Spanish and French
 * text and BIG-5 accepts Spanish and French, so a "try CJK first" pass would
 * corrupt Western statements that import correctly today. That leaves two
 * things this can do honestly: believe a file that states its own encoding,
 * and pick the better default for everything else.
 *
 * A file whose encoding is neither declared nor UTF-8 nor Windows-1252 still
 * needs the user to say so; that wants an encoding picker on the import
 * screen, which is deliberately not attempted here.
 */
class EncodingNormalizer {
    /**
     * Spellings that appear in real statement headers, mapped to the names
     * mbstring knows. An OFX `CHARSET:` is often a bare code page number.
     */
    private const ALIASES = [
        'UTF8' => 'UTF-8',
        'UTF-8' => 'UTF-8',
        '1252' => 'Windows-1252',
        'CP1252' => 'Windows-1252',
        'WINDOWS-1252' => 'Windows-1252',
        '1251' => 'Windows-1251',
        'CP1251' => 'Windows-1251',
        'WINDOWS-1251' => 'Windows-1251',
        '8859-1' => 'ISO-8859-1',
        'ISO-8859-1' => 'ISO-8859-1',
        'LATIN1' => 'ISO-8859-1',
        '8859-15' => 'ISO-8859-15',
        'ISO-8859-15' => 'ISO-8859-15',
    ];

    /**
     * Windows-1252 rather than ISO-8859-1 for undeclared content: the two
     * agree everywhere except 0x80-0x9F, where Latin-1 has unusable C1
     * controls and Windows-1252 has the punctuation bank exports actually
     * contain — curly quotes, en dashes, the euro sign.
     */
    private const DEFAULT_ENCODING = 'Windows-1252';

    /**
     * Encodings offered in the import screen's picker, in the order they are
     * shown, as name => label. Filtered against what this PHP build actually
     * has: mbstring ships no Windows-1250, -1253, -1255 or -1256, and offering
     * an encoding it cannot convert from would fail silently.
     *
     * @return array<string,string>
     */
    public function supportedEncodings(): array {
        $candidates = [
            'UTF-8' => 'Unicode (UTF-8)',
            'Windows-1252' => 'Western European (Windows-1252)',
            'ISO-8859-1' => 'Western European (ISO-8859-1)',
            'ISO-8859-15' => 'Western European (ISO-8859-15)',
            'ISO-8859-2' => 'Central European (ISO-8859-2)',
            'Windows-1251' => 'Cyrillic (Windows-1251)',
            'ISO-8859-7' => 'Greek (ISO-8859-7)',
            'Windows-1254' => 'Turkish (Windows-1254)',
            'ISO-8859-8' => 'Hebrew (ISO-8859-8)',
            'SJIS' => 'Japanese (Shift-JIS)',
            'EUC-JP' => 'Japanese (EUC-JP)',
            'GB18030' => 'Simplified Chinese (GB18030)',
            'BIG-5' => 'Traditional Chinese (Big5)',
            'EUC-KR' => 'Korean (EUC-KR)',
        ];

        $known = array_map('strtoupper', mb_list_encodings());

        return array_filter(
            $candidates,
            static fn($name) => in_array(strtoupper($name), $known, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Whether an encoding name may be used as an explicit override.
     */
    public function isSupported(string $encoding): bool {
        return isset($this->supportedEncodings()[$encoding]);
    }

    /**
     * Convert content to UTF-8, leaving it untouched if it already is.
     *
     * `$encoding` is the user's explicit choice from the import screen, for
     * the files that cannot be identified any other way — an undeclared
     * Windows-1251 CSV is indistinguishable from a Windows-1252 one, since
     * every single-byte encoding accepts every byte. A choice is obeyed even
     * where auto-detection would have decided otherwise; that is the point of
     * having it. Null means detect (#371).
     */
    public function toUtf8(string $content, ?string $encoding = null): string {
        if ($encoding !== null && $this->isSupported($encoding)) {
            if ($encoding === 'UTF-8') {
                return mb_check_encoding($content, 'UTF-8')
                    ? $content
                    : $this->retagXmlDeclaration(mb_convert_encoding($content, 'UTF-8', 'UTF-8'));
            }
            return $this->retagXmlDeclaration(mb_convert_encoding($content, 'UTF-8', $encoding));
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        return $this->retagXmlDeclaration(
            mb_convert_encoding($content, 'UTF-8', $this->detectedEncoding($content))
        );
    }

    /**
     * The encoding auto-detection settles on, so the import screen can show
     * the user what was assumed before they decide whether to override it.
     */
    public function detectedEncoding(string $content): string {
        if (mb_check_encoding($content, 'UTF-8')) {
            return 'UTF-8';
        }

        $declared = $this->declaredEncoding($content);
        if ($declared !== null && $declared !== 'UTF-8' && mb_check_encoding($content, $declared)) {
            return $declared;
        }

        return self::DEFAULT_ENCODING;
    }

    /**
     * The encoding a file states for itself, or null if it states none.
     *
     * Two formats say so: XML (camt.053 and OFX 2.x) in its declaration, and
     * OFX 1.x in its SGML header, where `ENCODING:` names the character set
     * family and `CHARSET:` the code page within it.
     */
    public function declaredEncoding(string $content): ?string {
        $head = substr($content, 0, 1024);

        if (preg_match('/<\?xml\b[^>]*?\bencoding\s*=\s*["\']([A-Za-z0-9._-]+)["\']/i', $head, $m) === 1) {
            return $this->resolveName($m[1]);
        }

        // CHARSET is the specific one, so it wins where both are present.
        // CHARSET:NONE means "no code page", not an encoding.
        if (preg_match('/^\s*CHARSET\s*:\s*([A-Za-z0-9._-]+)/mi', $head, $m) === 1) {
            $resolved = $this->resolveName($m[1]);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if (preg_match('/^\s*ENCODING\s*:\s*([A-Za-z0-9._-]+)/mi', $head, $m) === 1) {
            return $this->resolveName($m[1]);
        }

        return null;
    }

    /**
     * Map a declared name onto an encoding mbstring can convert from, or null
     * when it is not one (`CHARSET:NONE`, `ENCODING:USASCII`, a typo).
     */
    private function resolveName(string $raw): ?string {
        $key = strtoupper(trim($raw));

        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        // USASCII carries no high bytes by definition, so a file declaring it
        // and holding some is lying — fall through to the CHARSET or default
        if ($key === 'NONE' || $key === 'USASCII' || $key === 'US-ASCII' || $key === 'ASCII') {
            return null;
        }

        foreach (mb_list_encodings() as $known) {
            if (strtoupper($known) === $key) {
                return $known;
            }
        }

        return null;
    }

    /**
     * Point an XML declaration at UTF-8 once the bytes behind it are UTF-8.
     *
     * Without this, converting a `<?xml ... encoding="ISO-8859-1"?>` statement
     * hands DOMDocument UTF-8 bytes still labelled Latin-1, and it decodes
     * them as Latin-1 all over again — mojibake produced by the very step
     * meant to prevent it.
     */
    private function retagXmlDeclaration(string $content): string {
        if (!str_starts_with(ltrim($content, "\xEF\xBB\xBF \t\r\n"), '<?xml')) {
            return $content;
        }

        $retagged = preg_replace(
            '/(<\?xml\b[^>]*?\bencoding\s*=\s*)(["\'])[A-Za-z0-9._-]+\2/i',
            '${1}${2}UTF-8${2}',
            $content,
            1
        );

        return $retagged ?? $content;
    }
}
