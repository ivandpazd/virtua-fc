<?php

namespace App\Modules\Migration;

/**
 * Distinct purposes a signed migration token can have. Mixing them up between
 * mint/verify is a bug; the purpose is part of the HMAC payload so a token
 * minted for one purpose cannot be replayed against another.
 */
enum TokenPurpose: string
{
    /** User-facing handoff: redirect from export side to import side. */
    case HANDOFF = 'handoff';

    /** Server-to-server: import side fetching the user's data from export side. */
    case S2S_EXPORT = 's2s_export';

    /** Server-to-server: import side asking the export side to mark the user as migrated. */
    case S2S_SEAL = 's2s_seal';
}
