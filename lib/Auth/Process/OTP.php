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
    private string $productToken;

    // The originator for the SMS
    private string $originator;

    // The attribute containing the user's mobile phone number
    private string $mobilePhoneAttribute;

    // The number of seconds an SMS-code can be used for authentication
    private int $validFor;


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

        $productToken = $config['productToken'] ?? null;
        Assert::notNull(
            $productToken,
            'Missing required REST API key for the cm.com service.',
        );

        $originator = $config['originator'] ?? 'Example';
        Assert::stringNotEmpty($originator);

        if (is_numeric($originator)) {
            Assert::maxLength(
                $originator,
                16,
                'A numeric originator must represent a phonenumber and can contain a maximum of 16 digits.',
            );
        } else {
            Assert::alnum(str_replace(' ', '', $originator));
            Assert::lengthBetween(
                $originator,
                3,
                11,
                'An alphanumeric originator can contain a minimum of 2 and a maximum of 11 characters.',
            );
        }

        $mobilePhoneAttribute = $config['mobilePhoneAttribute'] ?? 'mobile';
        Assert::notEmpty(
            $mobilePhoneAttribute,
            'mobilePhoneAttribute cannot be an empty string.',
        );

        $validFor = $config['validFor'] ?? 600;
        Assert::positiveInteger(
            $validFor,
            'validFor must be a positive integer.',
        );

        $this->productToken = $productToken;
        $this->originator = $originator;
        $this->mobilePhoneAttribute = $mobilePhoneAttribute;
        $this->validFor = $validFor;
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
