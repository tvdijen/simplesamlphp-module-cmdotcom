<?php

/**
 * SMS Authentication Processing filter
 *
 * Filter for requesting the user's SMS-based OTP.
 *
 * @package tvdijen/simplesamlphp-module-cmdotcom
 */

declare(strict_types=1);

namespace SimpleSAML\Module\cmdotcom\Auth\Process;

use RuntimeException;
use SAML2\Constants;
use SimpleSAML\{Auth, Configuration, Logger, Module, Utils};
use SimpleSAML\Assert\Assert;
use SimpleSAML\Module\cmdotcom\Utils\PhoneNumber as PhoneNumberUtils;
use SimpleSAML\Module\saml\Error;
use UnexpectedValueException;

class OTP extends Auth\ProcessingFilter
{
    // The REST API key for the cm.com SMS service (also called Product Token)
    private ?string $productToken = null;

    // The originator for the SMS
    private string $originator = 'Example';

    // The content of the SMS
    private string $message = '{code}';

    // The attribute containing the user's mobile phone number
    private string $mobilePhoneAttribute = 'mobile';

    // The number of seconds an SMS-code can be used for authentication
    private int $validFor = 180;

    // The number digits to use for the OTP between 4 an 10
    private int $codeLength = 5;

    // Whether or not the OTP-code should be pushed to an app on the device
    private bool $allowPush = false;

    // The app key to be used when allowPush is set to true
    private ?string $appKey = null;


    /**
     * Initialize SMS OTP filter.
     *
     * Validates and parses the configuration.
     *
     * @param array $config Configuration information.
     * @param mixed $reserved For future use.
     *
     * @throws \Exception if the required REST API key is missing.
     */
    public function __construct(array $config, $reserved)
    {
        parent::__construct($config, $reserved);

        // Retrieve the mandatory product token from the configuration
        if (isset($config['productToken'])) {
            $this->productToken = $config['productToken'];
        }

        // Retrieve the optional allowPush from the configuration
        if (isset($config['allowPush'])) {
            $this->allowPush = $config['allowPush'];
        }

        // Retrieve the optional app key from the configuration
        if (isset($config['appKey'])) {
            $this->appKey = $config['appKey'];
        }

        // Retrieve the optional originator from the configuration
        if (isset($config['originator'])) {
            $this->originator = $config['originator'];
        }

        // Retrieve the optional message from the configuration
        if (isset($config['message'])) {
            $this->message = $config['message'];
        }

        // Retrieve the optional code length from the configuration
        if (isset($config['codeLength'])) {
            $this->codeLength = $config['codeLength'];
        }

        // Retrieve the optional attribute name that holds the mobile phone number
        if (isset($config['mobilePhoneAttribute'])) {
            $this->mobilePhoneAttribute = $config['mobilePhoneAttribute'];
        }

        // Retrieve the optional validFor
        if (isset($config['validFor'])) {
            $this->validFor = $config['validFor'];
        }

        Assert::notEmpty(
            $this->mobilePhoneAttribute,
            'mobilePhoneAttribute cannot be an empty string.',
        );
    }


    /**
     * Process a authentication response
     *
     * This function saves the state, and redirects the user to the page where the user can enter the OTP
     * code sent to them.
     *
     * @param array &$state The state of the response.
     */
    public function process(array &$state): void
    {
        // user interaction necessary. Throw exception on isPassive request
        if (isset($state['isPassive']) && $state['isPassive'] === true) {
            throw new Error\NoPassive(
                Constants::STATUS_REQUESTER,
                'Unable to enter verification code on passive request.'
            );
        }

        // Retrieve the user's mobile phone number
        $recipient = $this->getMobilePhoneAttribute($state);

        // Sanitize the user's mobile phone number
        $phoneNumberUtils = new PhoneNumberUtils();
        $recipient = $phoneNumberUtils->sanitizePhoneNumber($recipient);

        $state['cmdotcom:productToken'] = $this->productToken;
        $state['cmdotcom:originator'] = $this->originator;
        $state['cmdotcom:recipient'] = $recipient;
        $state['cmdotcom:validFor'] = $this->validFor;
        $state['cmdotcom:codeLength'] = $this->codeLength;
        $state['cmdotcom:message'] = $this->message;
        $state['cmdotcom:allowPush'] = $this->allowPush;

        if ($this->allowPush === true) {
            $state['cmdotcom:appKey'] = $this->appKey;
        }

        // Save state and redirect
        $id = Auth\State::saveState($state, 'cmdotcom:request');
        $url = Module::getModuleURL('cmdotcom/sendCode');

        $httpUtils = new Utils\HTTP();
        $httpUtils->redirectTrustedURL($url, ['AuthState' => $id]);
    }


    /**
     * Retrieve the mobile phone attribute from the state
     *
     * @param array $state
     * @return string
     * @throws \RuntimeException if no attribute with a mobile phone number is present.
     */
    protected function getMobilePhoneAttribute(array $state): string
    {
        Assert::keyExists($state, 'Attributes');
        Assert::keyExists(
            $state['Attributes'],
            $this->mobilePhoneAttribute,
            sprintf(
                "cmdotcom:OTP: Missing attribute '%s', which is needed to send an SMS.",
                $this->mobilePhoneAttribute,
            ),
        );

        return $state['Attributes'][$this->mobilePhoneAttribute][0];
    }
}
