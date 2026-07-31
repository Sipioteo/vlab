<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal PDF reader used by the tests to look inside a generated document
 * without any external tool: it inflates the per-page content streams and
 * pulls the text out of the `[(...)] TJ` / `(...) Tj` operators.
 *
 * Good enough to assert *what* ended up *on which page* (pagination rules);
 * it is not a general-purpose extractor — non-ASCII glyphs are left as raw
 * WinAnsi bytes, so assertions should use ASCII-safe substrings.
 */
final class PdfInspector
{
    /** @var string[] text of each page, in document order */
    private array $pages;

    private function __construct(array $pages)
    {
        $this->pages = $pages;
    }

    public static function fromBytes(string $pdf): self
    {
        // id => raw object body
        $objects = [];
        if (preg_match_all('/(\d+)\s+0\s+obj\b(.*?)endobj/s', $pdf, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $objects[(int) $match[1]] = $match[2];
            }
        }

        $pages = [];
        foreach ($objects as $id => $body) {
            // `/Type /Page` but not `/Type /Pages`
            if (!preg_match('~/Type\s*/Page(?![s\w])~', $body)) {
                continue;
            }
            $text = '';
            if (preg_match('~/Contents\s+(\d+)\s+0\s+R~', $body, $ref)) {
                $text = self::streamText($objects[(int) $ref[1]] ?? '');
            }
            $pages[$id] = $text;
        }
        ksort($pages);

        return new self(array_values($pages));
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** 1-based. */
    public function pageText(int $page): string
    {
        return $this->pages[$page - 1] ?? '';
    }

    public function text(): string
    {
        return implode("\n", $this->pages);
    }

    /** How many pages contain the given (ASCII) needle. */
    public function pagesContaining(string $needle): int
    {
        $count = 0;
        foreach ($this->pages as $text) {
            if (str_contains($text, $needle)) {
                $count++;
            }
        }
        return $count;
    }

    /** 1-based page numbers containing the needle. @return int[] */
    public function pagesWith(string $needle): array
    {
        $out = [];
        foreach ($this->pages as $index => $text) {
            if (str_contains($text, $needle)) {
                $out[] = $index + 1;
            }
        }
        return $out;
    }

    private static function streamText(string $objectBody): string
    {
        if (!preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $objectBody, $match)) {
            return '';
        }
        $raw = $match[1];
        $inflated = @gzuncompress($raw);
        if ($inflated === false) {
            $inflated = @gzinflate($raw);
        }
        $content = $inflated === false ? $raw : $inflated;

        $out = '';
        if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $content, $strings)) {
            foreach ($strings[0] as $literal) {
                $out .= self::unescape(substr($literal, 1, -1)) . ' ';
            }
        }
        return $out;
    }

    private static function unescape(string $value): string
    {
        return str_replace(
            ['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'],
            ['(', ')', '\\', "\n", "\r", "\t"],
            $value
        );
    }
}
