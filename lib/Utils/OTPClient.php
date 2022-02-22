<?php

/**
 * Utilities for sending OTP text messages
 *
 * @package tvdijen/simplesamlphp-module-cmdotcom
 */

declare(strict_types=1);

namespace SimpleSAML\Module\cmdotcom\Utils;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface;
use SimpleSAML\{Configuration, Session};
use SimpleSAML\Assert\Assert;

class OTPClient
{
    /** @var string */
    public const API_BASE = 'https://api.cmtelecom.com';

    /** @var string */
    public const HEADER = 'X-CM-ProductToken';

    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;


    /**
     * @param \SimpleSAML\Configuration $config The configuration to use.
     */
    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }


    /**
     * Send OTP code
     *
     * @param array $state
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendCode(array $state): ResponseInterface
    {
        Assert::keyExists($state, 'cmdotcom:productToken', 'Missing required REST API key for the cm.com service.');
        Assert::keyExists($state, 'cmdotcom:recipient');
        Assert::keyExists($state, 'cmdotcom:originator');
        Assert::keyExists($state, 'cmdotcom:codeLength');
        Assert::keyExists($state, 'cmdotcom:validFor');
        Assert::keyExists($state, 'cmdotcom:message');
        Assert::keyExists($state, 'cmdotcom:allowPush');

        // Validate product token
        $productToken = $state['cmdotcom:productToken'];
        Assert::notNull(
            $productToken,
            'Missing required REST API key for the cm.com service.',
        );
        Assert::uuid($productToken);

        // Validate appKey
        $allowPush = $state['cmdotcom:allowPush'];
        $appKey = null;
        if ($allowPush === true) {
            $appKey = $state['cmdotcom:appKey'];
            Assert::notNull(
                $appKey,
                'Missing required appKey for use with push notification.',
            );
            Assert::uuid($appKey);
        }

        // Validate originator
        $originator = $state['cmdotcom:originator'];
        if (preg_match('/[0-9]+/', $originator)) {
            Assert::maxLength(
                $originator,
                16,
                'A numeric originator must represent a phonenumber and can contain a maximum of 16 digits.',
            );
        } else {
            // TODO: figure out what characters are allowed and write a regex.
            // So far 'A-Z', 'a-z', '0-9', ' ' and '-' are known to be accepted
            //Assert::alnum(str_replace(' ', '', $originator));
            Assert::lengthBetween(
                $originator,
                3,
                11,
                'An alphanumeric originator can contain a minimum of 2 and a maximum of 11 characters.',
            );
        }

        // Validate OTP length
        $codeLength = $state['cmdotcom:codeLength'];
        Assert::range($codeLength, 4, 10);

        // Validate recipient
        $recipient = $state['cmdotcom:recipient'];
        Assert::numeric($recipient);
        Assert::maxLength(
            $recipient,
            16,
            'A recipient must represent a phonenumber and can contain a maximum of 16 digits.',
        );

        // Validate validFor
        $validFor = $state['cmdotcom:validFor'];
        Assert::positiveInteger(
            $validFor,
            'validFor must be a positive integer.',
        );

        // Validate message
        $message = $state['cmdotcom:message'];
        Assert::contains($message, '{code}');

        $options = [
            'base_uri' => self::API_BASE,
            //'debug' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                self::HEADER => $productToken,
            ],
            'http_errors' => false,
            'timeout' => 3.0,
        ];

        $client = new GuzzleClient($options);
        $json = [
            'recipient' => $recipient,
            'sender' => $originator,
            'length' => $codeLength,
            'expiry' => $validFor,
            'message' => $message,
        ];

        if ($allowPush === true) {
            $json += ['allowPush' => $allowPush, 'appKey' => $appKey];
        }

        return $client->request(
            'POST',
            '/v1.0/otp/generate',
            [
                'json' => $json,
            ],
        );
    }


    /**
     * Verify OTP code
     *
     * @param array $state
     * @param string $code
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function verifyCode(array $state, string $code): ResponseInterface
    {
        Assert::keyExists($state, 'cmdotcom:reference');
        Assert::keyExists($state, 'cmdotcom:productToken');

        // Validate reference
        $reference = $state['cmdotcom:reference'];
        Assert::uuid($reference);

        // Validate product token
        $productToken = $state['cmdotcom:productToken'];
        Assert::notNull(
            $productToken,
            'Missing required REST API key for the cm.com service.',
        );
        Assert::uuid($productToken);

        $options = [
            'base_uri' => self::API_BASE,
            //'debug' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                self::HEADER => $productToken,
            ],
            'http_errors' => false,
            'timeout' => 3.0,
        ];

        $proxy = $this->config->getString('proxy', null);
        if ($proxy !== null) {
            $options += ['proxy' => ['http' => $proxy, 'https' => $proxy]];
        }

        $client = new GuzzleClient($options);
        $json = [
            'id' => $reference,
            'code' => $code,
        ];

        return $client->request(
            'POST',
            '/v1.0/otp/verify',
            [
                'json' => $json,
            ],
        );
    }
}
