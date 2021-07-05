<?php

/**
 * Random utilities for SMS-based OTP.
 *
 * @package tvdijen/simplesamlphp-module-cmdotcom
 */

declare(strict_types=1);

namespace SimpleSAML\Module\cmdotcom\Utils;

class Random
{
    /**
     * Generate a 6-digit random code
     *
     * @return string
     */
    public function generateOneTimePassword(): string
    {
        $code = sprintf("%06d", mt_rand(10000, 999999));
        $padded = str_pad($code, 6, '0', STR_PAD_LEFT);

        return $padded;
    }
}
