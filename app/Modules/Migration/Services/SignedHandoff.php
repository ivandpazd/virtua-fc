<?php

namespace App\Modules\Migration\Services;

use App\Modules\Migration\DTOs\VerifiedToken;
use App\Modules\Migration\Exceptions\InvalidHandoffToken;
use App\Modules\Migration\TokenPurpose;
use Carbon\CarbonImmutable;

/**
 * HMAC-signed token mint/verify for the beta→prod migration handoff.
 *
 * Token format:
 *
 *   v1.<purpose>.<user_id>.<expires_at>.<hex_hmac_sha256>
 *
 * The signature covers everything before the trailing dot, so swapping the
 * purpose, user, or expiry invalidates the token. Both sides of the migration
 * must share the same secret (config('migration.handoff_secret')).
 */
class SignedHandoff
{
    private const VERSION = 'v1';

    public function __construct(
        private readonly string $secret,
    ) {
        if ($secret === '') {
            throw new \LogicException(
                'MIGRATION_HANDOFF_SECRET is not set. The migration flow cannot mint or verify tokens without it.'
            );
        }
    }

    public function mint(TokenPurpose $purpose, int $userId, int $ttlSeconds): string
    {
        $expiresAt = CarbonImmutable::now()->addSeconds($ttlSeconds)->getTimestamp();
        $message = $this->message($purpose, $userId, $expiresAt);
        $sig = hash_hmac('sha256', $message, $this->secret);

        return "{$message}.{$sig}";
    }

    /**
     * @throws InvalidHandoffToken
     */
    public function verify(TokenPurpose $purpose, string $token): VerifiedToken
    {
        $parts = explode('.', $token);
        if (count($parts) !== 5) {
            throw InvalidHandoffToken::malformed();
        }

        [$version, $purposeStr, $userIdStr, $expiresAtStr, $sig] = $parts;

        if ($version !== self::VERSION) {
            throw InvalidHandoffToken::badVersion();
        }
        if ($purposeStr !== $purpose->value) {
            throw InvalidHandoffToken::badPurpose();
        }
        if (! ctype_digit($userIdStr) || ! ctype_digit($expiresAtStr)) {
            throw InvalidHandoffToken::malformed();
        }

        $userId = (int) $userIdStr;
        $expiresAt = (int) $expiresAtStr;

        $message = $this->message($purpose, $userId, $expiresAt);
        $expectedSig = hash_hmac('sha256', $message, $this->secret);

        if (! hash_equals($expectedSig, $sig)) {
            throw InvalidHandoffToken::badSignature();
        }

        if (CarbonImmutable::now()->getTimestamp() > $expiresAt) {
            throw InvalidHandoffToken::expired();
        }

        return new VerifiedToken($userId, CarbonImmutable::createFromTimestamp($expiresAt));
    }

    private function message(TokenPurpose $purpose, int $userId, int $expiresAt): string
    {
        return self::VERSION.'.'.$purpose->value.'.'.$userId.'.'.$expiresAt;
    }
}
