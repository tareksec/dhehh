<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The investor portal's password rule (IMS-V2/ROLES.md §4, old IMS §31):
 * at least 10 characters with letters and numbers.
 */
final class ImsPassword
{
    public static function rule(): Password
    {
        return Password::min(10)->letters()->numbers();
    }
}
