<?php

namespace Tests\Unit\Migration;

use App\Modules\Migration\Exceptions\InvalidHandoffToken;
use App\Modules\Migration\Services\SignedHandoff;
use App\Modules\Migration\TokenPurpose;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SignedHandoffTest extends TestCase
{
    private const SECRET = 'a'.PHP_EOL.'b'; // arbitrary; used for both mint and verify
    private const ALT_SECRET = 'different-secret';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_mint_then_verify_round_trips(): void
    {
        $svc = new SignedHandoff(self::SECRET);

        $token = $svc->mint(TokenPurpose::HANDOFF, 42, 60);
        $verified = $svc->verify(TokenPurpose::HANDOFF, $token);

        $this->assertSame(42, $verified->userId);
        $this->assertGreaterThan(CarbonImmutable::now()->getTimestamp(), $verified->expiresAt->getTimestamp());
    }

    public function test_token_minted_for_one_purpose_does_not_verify_for_another(): void
    {
        $svc = new SignedHandoff(self::SECRET);
        $token = $svc->mint(TokenPurpose::HANDOFF, 42, 60);

        $this->expectException(InvalidHandoffToken::class);
        $svc->verify(TokenPurpose::S2S_EXPORT, $token);
    }

    public function test_token_signed_with_a_different_secret_fails_verification(): void
    {
        $minter = new SignedHandoff(self::SECRET);
        $token = $minter->mint(TokenPurpose::HANDOFF, 42, 60);

        $verifier = new SignedHandoff(self::ALT_SECRET);

        $this->expectException(InvalidHandoffToken::class);
        $verifier->verify(TokenPurpose::HANDOFF, $token);
    }

    public function test_expired_token_fails_verification(): void
    {
        $svc = new SignedHandoff(self::SECRET);

        CarbonImmutable::setTestNow('2026-05-06 12:00:00');
        $token = $svc->mint(TokenPurpose::HANDOFF, 42, 60);

        CarbonImmutable::setTestNow('2026-05-06 12:01:01'); // 61s later, past 60s TTL

        $this->expectException(InvalidHandoffToken::class);
        $svc->verify(TokenPurpose::HANDOFF, $token);
    }

    public function test_tampering_with_user_id_breaks_signature(): void
    {
        $svc = new SignedHandoff(self::SECRET);
        $token = $svc->mint(TokenPurpose::HANDOFF, 42, 60);

        // Swap user_id 42 → 43 in place; signature was computed for 42, so this must fail.
        $tampered = preg_replace('/\.42\./', '.43.', $token, 1);

        $this->expectException(InvalidHandoffToken::class);
        $svc->verify(TokenPurpose::HANDOFF, $tampered);
    }

    public function test_malformed_token_fails_verification(): void
    {
        $svc = new SignedHandoff(self::SECRET);

        $this->expectException(InvalidHandoffToken::class);
        $svc->verify(TokenPurpose::HANDOFF, 'not-a-token');
    }

    public function test_empty_secret_throws_at_construction(): void
    {
        $this->expectException(\LogicException::class);
        new SignedHandoff('');
    }
}
