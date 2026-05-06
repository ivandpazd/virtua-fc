<?php

namespace App\Modules\Migration\Exceptions;

use RuntimeException;

class InvalidHandoffToken extends RuntimeException
{
    public static function malformed(): self
    {
        return new self('Migration token is malformed.');
    }

    public static function badVersion(): self
    {
        return new self('Migration token has an unsupported version.');
    }

    public static function badPurpose(): self
    {
        return new self('Migration token purpose does not match.');
    }

    public static function badSignature(): self
    {
        return new self('Migration token signature is invalid.');
    }

    public static function expired(): self
    {
        return new self('Migration token has expired.');
    }
}
