<?php

namespace Tests\Unit;

use App\Services\AI\PiiRedactor;
use PHPUnit\Framework\TestCase;

class PiiRedactorTest extends TestCase
{
    public function test_masks_common_identifiers(): void
    {
        $r = new PiiRedactor;
        $out = $r->redact('Mail anna.schmidt@uni-bremen.de or visit https://example.org/profile?id=3, call +49 421 218-12345. '
            .'IBAN DE89 3704 0044 0532 0130 00, card 4111 1111 1111 1111, server 192.168.10.4.');

        $this->assertStringContainsString('[EMAIL]', $out);
        $this->assertStringContainsString('[URL]', $out);
        $this->assertStringContainsString('[PHONE]', $out);
        $this->assertStringContainsString('[IBAN]', $out);
        $this->assertStringContainsString('[CARD]', $out);
        $this->assertStringContainsString('[IP]', $out);
        $this->assertStringNotContainsString('anna.schmidt', $out);
        $this->assertStringNotContainsString('4111', $out);
        $this->assertGreaterThanOrEqual(6, $r->count());
    }

    public function test_keeps_years_ranges_and_non_luhn_numbers(): void
    {
        $r = new PiiRedactor;
        $text = 'I moved in 2019, worked from 1990 - 2000 and earned 1200 euros. Ref 1234 5678 9012 3456.';
        $out = $r->redact($text);
        $this->assertStringContainsString('2019', $out);
        $this->assertStringContainsString('1990 - 2000', $out);
        $this->assertStringContainsString('1200', $out);
        $this->assertStringNotContainsString('[CARD]', $out);
    }

    public function test_masks_project_terms_with_word_boundaries_case_insensitively(): void
    {
        $r = new PiiRedactor(['Anna', 'Anna Maria', 'Bremen-Nord', ' ', 'x']);
        $out = $r->redact('anna maria from BREMEN-NORD met Annabelle and Anna.');
        $this->assertSame('[REDACTED] from [REDACTED] met Annabelle and [REDACTED].', $out);
    }

    public function test_is_deterministic(): void
    {
        $r = new PiiRedactor(['Ben']);
        $this->assertSame($r->redact('Ben: ben@x.org'), $r->redact('Ben: ben@x.org'));
    }

    public function test_luhn(): void
    {
        $this->assertTrue(PiiRedactor::luhn('4111111111111111'));
        $this->assertFalse(PiiRedactor::luhn('4111111111111112'));
        $this->assertFalse(PiiRedactor::luhn('123'));
    }
}
