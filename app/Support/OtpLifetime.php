<?php

namespace App\Support;

/**
 * How long a one-time code (OTP) may work. Owner decision 2026-10-24: never more than three minutes, whichever
 * code it is (shop customer sign-up, login and password-reset codes, reseller applications, the IMS join code and
 * the investor portal's e-mailed code). Every code's lifetime constant points here; a code that lives shorter (the
 * investor SMS code, one minute) stays shorter. An emailed reset LINK for staff to send is a link, not a code,
 * and keeps its own longer life (CustomerAccountService::RESET_LINK_MINUTES).
 */
final class OtpLifetime
{
    public const MAX_MINUTES = 3;
}
