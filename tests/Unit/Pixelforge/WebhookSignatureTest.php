<?php

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\WebhookSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const BODY = '{"id":"evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A","type":"generation.succeeded"}';

    private const NOW = 1_793_801_009;

    public function test_a_request_signed_with_our_secret_is_accepted(): void
    {
        $this->assertTrue($this->isValid($this->header(self::NOW, self::BODY, self::SECRET)));
    }

    public function test_a_changed_body_is_rejected(): void
    {
        $header = $this->header(self::NOW, self::BODY, self::SECRET);

        $this->assertFalse($this->isValid($header, body: str_replace('succeeded', 'failed', self::BODY)));
    }

    public function test_a_signature_made_with_another_secret_is_rejected(): void
    {
        $this->assertFalse($this->isValid($this->header(self::NOW, self::BODY, 'whsec_someone_else')));
    }

    public function test_a_valid_signature_cannot_be_reused_with_a_newer_timestamp(): void
    {
        $signature = hash_hmac('sha256', (self::NOW - 3600).'.'.self::BODY, self::SECRET);

        $this->assertFalse($this->isValid('t='.self::NOW.",v1={$signature}"));
    }

    public function test_signatures_expire_so_a_captured_request_cannot_be_replayed(): void
    {
        $this->assertTrue($this->isValid($this->header(self::NOW - 300, self::BODY, self::SECRET)));
        $this->assertFalse($this->isValid($this->header(self::NOW - 301, self::BODY, self::SECRET)));
        $this->assertFalse($this->isValid($this->header(self::NOW + 301, self::BODY, self::SECRET)));
    }

    public function test_nothing_is_accepted_while_no_secret_is_configured(): void
    {
        // Correctly signed with an empty key, which is what a missing secret would otherwise allow.
        $header = $this->header(self::NOW, self::BODY, '');

        $this->assertFalse($this->isValid($header, secret: ''));
    }

    #[DataProvider('malformedHeaders')]
    public function test_malformed_headers_are_rejected(string $header): void
    {
        $this->assertFalse($this->isValid($header));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedHeaders(): array
    {
        return [
            'missing' => [''],
            'no timestamp' => ['v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd'],
            'no signature' => ['t='.self::NOW],
            'timestamp is not a number' => ['t=yesterday,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd'],
            'not a signature header at all' => ['Bearer token'],
        ];
    }

    private function header(int $timestamp, string $body, string $secret): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    private function isValid(string $header, string $body = self::BODY, string $secret = self::SECRET): bool
    {
        return WebhookSignature::isValid($header, $body, $secret, tolerance: 300, now: self::NOW);
    }
}
