<?php

namespace App\Modules\Migration\DTOs;

use Carbon\CarbonImmutable;

final readonly class VerifiedToken
{
    public function __construct(
        public int $userId,
        public CarbonImmutable $expiresAt,
    ) {
    }
}
