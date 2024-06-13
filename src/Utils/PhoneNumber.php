<?php

/**
 * Phone number utilities for SMS-based OTP.
 *
 * @package tvdijen/simplesamlphp-module-cmdotcom
 */

declare(strict_types=1);

namespace SimpleSAML\Module\cmdotcom\Utils;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

class PhoneNumber
{
    /**
     * Sanitize the mobile phone number for use with the cm.com Rest API
     *
     * @param string $number
     * @param string $defaultRegion
     * @return string
     * @throws \libphonenumber\NumberParseException
     *   if the mobile phone number contains illegal characters or is otherwise invalid.
     */
    public function sanitizePhoneNumber(string $number, string $defaultRegion = 'NL'): string
    {
        $util = PhoneNumberUtil::getInstance();
        $region = strpos($number, '+') === false ? $defaultRegion : 'ZZ';
        $proto = $util->parse($number, $region);

        if (
            $util->isViablePhoneNumber($number) === false
            || $util->isValidNumber($proto) === false
        ) {
            throw new NumberParseException(
                NumberParseException::NOT_A_NUMBER,
                "The string supplied does not seem to be a valid phone number.",
            );
        }

        return '00' . $proto->getCountryCode() . $proto->getNationalNumber();
    }
}
