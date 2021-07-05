<?php

/**
 * Utilities for sending SMS
 *
 * @package tvdijen/simplesamlphp-module-cmdotcom
 */

declare(strict_types=1);

namespace SimpleSAML\Module\cmdotcom\Utils;

use CMText\TextClient;
use CMText\TextClientResult;

class TextMessage
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
}
