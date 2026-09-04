<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Support;

/**
 * Reads back the text TCPDF wrote onto a page, so a test can assert on what
 * a PDF actually says rather than on the calls that produced it.
 *
 * Page content streams are deflated, and with the Unicode font the app
 * embeds every string is UTF-16BE — so an ASCII label comes back intact once
 * the NUL bytes are dropped. Non-ASCII characters do not, so assert on
 * ASCII-only labels.
 */
trait ReadsPdfText {
    private function pdfText(string $pdf): string {
        $text = '';
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $text .= $inflated === false ? $stream : $inflated;
        }
        return str_replace("\0", '', $text);
    }

    private function requireTcpdf(): void {
        if (!class_exists('TCPDF')) {
            $this->markTestSkipped('TCPDF is not loaded');
        }
    }
}
