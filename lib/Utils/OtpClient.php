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

class OtpClient
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

        $options = [
            'base_uri' => self::API_BASE,
            //'debug' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                self::HEADER => $state['cmdotcom:productToken']
            ],
            'http_errors' => false,
            'timeout' => 3.0,
        ];

        $client = new GuzzleClient($options);
        return $client->request(
            'POST',
            '/v1.0/otp/generate',
            [
                'json' => [
                    'recipient' => $state['cmdotcom:recipient'],
                    'sender' => $state['cmdotcom:originator'],
                    'length' => 6, //$state['cmdotcom:codeLength'],
                    'expiry' => $state['cmdotcom:validFor'] ?? 180,
                    //'message' => '',
                ],
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
        Assert::stringNotEmpty($state['cmdotcom:reference']);

        $options = [
            'base_uri' => self::API_BASE,
            //'debug' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                self::HEADER => $state['cmdotcom:productToken']
            ],
            'http_errors' => false,
            'timeout' => 3.0,
        ];

        $proxy = $this->config->getString('proxy', null);
        if ($proxy !== null) {
            $options += ['proxy' => ['http' => $proxy, 'https' => $proxy]];
        }

        $client = new GuzzleClient($options);
        return $response = $client->request(
            'POST',
            '/v1.0/otp/verify',
            [
                'json' => [
                    'id' => $state['cmdotcom:reference'],
                    'code' => $code,
                ],
            ],
        );
    }
}
