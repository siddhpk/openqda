<?php

namespace App\Services\Desktop;

use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;

/**
 * Converts uploaded documents to HTML inside PHP, so the laptop edition
 * needs neither the converter container nor a 200 MB pandoc binary.
 * Uses PhpWord (already an OpenQDA dependency) for Word, OpenDocument and
 * RTF files and CommonMark (part of Laravel) for Markdown.
 *
 * Fidelity is aimed at interview transcripts: paragraphs, headings, lists,
 * tables, bold/italic. For complex layouts set PANDOC_PATH instead.
 */
class DocumentConverter
{
    private const READERS = [
        'docx' => 'Word2007',
        'docm' => 'Word2007',
        'odt' => 'ODText',
        'rtf' => 'RTF',
        'doc' => 'MsDoc',
    ];

    public function supports(string $path): bool
    {
        return in_array($this->extension($path), array_merge(array_keys(self::READERS), ['md', 'markdown', 'html', 'htm']), true);
    }

    public function toHtml(string $path): string
    {
        $ext = $this->extension($path);
        if (in_array($ext, ['md', 'markdown'], true)) {
            return '<html><body>'.Str::markdown((string) file_get_contents($path), ['html_input' => 'strip', 'allow_unsafe_links' => false]).'</body></html>';
        }
        if (in_array($ext, ['html', 'htm'], true)) {
            return (string) file_get_contents($path);
        }
        $reader = self::READERS[$ext] ?? throw new RuntimeException("Unsupported document type: .{$ext}");

        $previous = libxml_use_internal_errors(true);
        try {
            $document = IOFactory::load($path, $reader);
            $writer = IOFactory::createWriter($document, 'HTML');
            $html = method_exists($writer, 'getContent') ? $writer->getContent() : $this->viaFile($writer);
        } catch (\Throwable $e) {
            throw new RuntimeException('The document could not be read: '.$e->getMessage(), 0, $e);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // PhpWord writes a full page; keep the body and drop its stylesheet
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
            $html = '<html><body>'.trim($m[1]).'</body></html>';
        }

        return $html;
    }

    private function viaFile($writer): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'oqd');
        try {
            $writer->save($tmp);

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
