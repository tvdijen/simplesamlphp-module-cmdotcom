<?php

/**
 * Utilities for SMS-based OTP.
 *
 * @package tvdijen/simplesamlphp-module-cmdotcom
 */

declare(strict_types=1);

namespace SimpleSAML\Module\cmdotcom\Utils;

use CMText\TextClient;
use CMText\TextClientResult;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use SimpleSAML\Assert\Assert;
use UnexpectedValueException;

class OTP
{
    /**
     * Send OTP SMS
     *
     * @param string $code
     * @param string $recipient
     * @return \CMText\TextClientResult
     */
    public function sendMessage(string $api_key, string $code, string $recipient, string $originator): TextClientResult
    {
        $client = new TextClient($api_key);
        $result = $client->SendMessage($code, $originator, [$recipient]);
        return $result;
    }


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
