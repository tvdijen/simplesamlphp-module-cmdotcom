<?php

namespace SimpleSAML\Module\cmdotcom\Controller;

use CMText\TextClientStatusCodes;
use RuntimeException;
use SimpleSAML\Assert\Assert;
use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Logger;
use SimpleSAML\Module;
use SimpleSAML\Module\cmdotcom\Utils\OTP as OTPUtils;
use SimpleSAML\Session;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use UnexpectedValueException;

/**
 * Controller class for the cmdotcom module.
 *
 * This class serves the verification code and error views available in the module.
 *
 * @package SimpleSAML\Module\cmdotcom
 */
class OTP
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Logger */
    protected Logger $logger;

    /** @var \SimpleSAML\Configuration */
    protected Configuration $moduleConfig;

    /** @var \SimpleSAML\Session */
    protected Session $session;

    /** @var \SimpleSAML\Utils\HTTP */
    protected Utils\HTTP $httpUtils;

    /** @var \SimpleSAML\Module\cmdotcom\Utils\OTP */
    protected OTPUtils $otpUtils;

    /**
     * @var \SimpleSAML\Auth\State|string
     * @psalm-var \SimpleSAML\Auth\State|class-string
     */
    protected $authState = Auth\State::class;


    /**
     * OTP Controller constructor.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use.
     * @param \SimpleSAML\Session $session The current user session.
     */
    public function __construct(Configuration $config, Session $session)
    {
        $this->config = $config;
        $this->httpUtils = new Utils\HTTP();
        $this->otpUtils = new OTPUtils();
        $this->moduleConfig = Configuration::getConfig('module_cmdotcom.php');
        $this->session = $session;
    }


    /**
     * Inject the \SimpleSAML\Logger dependency.
     *
     * @param \SimpleSAML\Logger $logger
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }


    /**
     * Inject the \SimpleSAML\Utils\HTTP dependency.
     *
     * @param \SimpleSAML\Utils\HTTP $httpUtils
     */
    public function setHttpUtils(Utils\HTTP $httpUtils): void
    {
        $this->httpUtils = $httpUtils;
    }


    /**
     * Inject the \SimpleSAML\Module\cmdotcom\Utils\OTP dependency.
     *
     * @param \SimpleSAML\Module\cmdotcom\Utils\OTP $otpUtils
     */
    public function setOtpUtils(OTPUtils $otpUtils): void
    {
        $this->otpUtils = $otpUtils;
    }


    /**
     * Inject the \SimpleSAML\Auth\State dependency.
     *
     * @param \SimpleSAML\Auth\State $authState
     */
    public function setAuthState(Auth\State $authState): void
    {
        $this->authState = $authState;
    }


    /**
     * Display the page where the validation code should be entered.
     *
     * @return \SimpleSAML\XHTML\Template
     */
    public function enterCode(Request $request): Template
    {
        $id = $request->get('AuthState', null);
        if ($id === null) {
            throw new Error\BadRequest('Missing AuthState parameter.');
        }

        $this->authState::loadState($id, 'cmdotcom:request');

        $t = new Template($this->config, 'cmdotcom:entercode.twig');
        $t->data = [
            'AuthState' => $id,
            'stateparams' => [],
        ];

        return $t;
    }


    /**
     * Process the entered validation code.
     *
     * @return \SimpleSAML\HTTP\RunnableResponse
     */
    public function validateCode(Request $request): RunnableResponse
    {
        $id = $request->get('AuthState', null);
        if ($id === null) {
            throw new Error\BadRequest('Missing AuthState parameter.');
        }

        $state = $this->authState::loadState($id, 'cmdotcom:request');

        Assert::keyExists($state, 'cmdotcom:timestamp');
        Assert::positiveInteger($state['cmdotcom:timestamp']);

        $timestamp = $state['cmdotcom:timestamp'];
        $validUntil = $timestamp + $this->moduleConfig->getInteger('validUntil', 600);

        // Verify that code was entered within a reasonable amount of time
        if (time() > $validUntil) {
            $state['cmdotcom:expired'] = true;

            $id = Auth\State::saveState($state, 'codotcom:request');
            $url = Module::getModuleURL('cmdotcom/resendCode');

            return new RunnableResponse([$this->httpUtils, 'redirectTrustedURL'], [$url, ['AuthState' => $id]]);
        }

        Assert::keyExists($state, 'cmdotcom:hash');
        Assert::stringNotEmpty($state['cmdotcom:hash']);

        $cryptoUtils = new Utils\Crypto();
        if ($cryptoUtils->pwValid($state['cmdotcom:hash'], $request->get('otp'))) {
            // The user has entered the correct verification code
            return new RunnableResponse([Auth\ProcessingChain::class, 'resumeProcessing'], [$state]);
        } else {
            $state['cmdotcom:invalid'] = true;

            $id = Auth\State::saveState($state, 'cmdotcom:request');
            $url = Module::getModuleURL('cmdotcom/enterCode');

            return new RunnableResponse([$this->httpUtils, 'redirectTrustedURL'], [$url, ['AuthState' => $id]]);
        }
    }


    /**
     * Display the page where the user can trigger sending a new SMS.
     *
     * @return \SimpleSAML\XHTML\Template
     */
    public function promptResend(Request $request): Template
    {
        $id = $request->get('AuthState', null);
        if ($id === null) {
            throw new Error\BadRequest('Missing AuthState parameter.');
        }

        $state = $this->authState::loadState($id, 'cmdotcom:request');

        $t = new Template($this->config, 'cmdotcom:promptresend.twig');
        $t->data = [
            'AuthState' => $id,
        ];

        if (isset($state['cmdotcom:expired']) && ($state['cmdotcom:expired'] === true)) {
            $t->data['message'] = 'Your verification code has expired.';
        } elseif (isset($state['cmdotcom:sendFailure'])) {
            Assert::stringNotEmpty($state['cmdotcom:sendFailure']);
            $t->data['message'] = $state['cmdotcom:sendFailure'];
        } elseif (isset($state['cmdotcom:resendRequested']) && ($state['cmdotcom:resendRequested'] === true)) {
            $t->data['message'] = '';
        } else {
           throw new RuntimeException('Unknown request for SMS resend.');
        }

        return $t;
    }


    /**
     * Send an SMS and redirect to either the validation page or the resend-prompt
     *
     * @return \SimpleSAML\HTTP\RunnableResponse
     */
    public function sendCode(Request $request): RunnableResponse
    {
        $id = $request->get('AuthState', null);
        if ($id === null) {
            throw new Error\BadRequest('Missing AuthState parameter.');
        }

        $state = $this->authState::loadState($id, 'cmdotcom:request');

        // Generate the OTP
        $code = $this->otpUtils->generateOneTimePassword();

        Assert::digits($code, UnexpectedValueException::class);
        Assert::length($code, 6, UnexpectedValueException::class);

        $api_key = $this->moduleConfig->getString('api_key', null);
        Assert::notNull(
            $api_key,
            'Missing required REST API key for the cm.com service.',
            Error\ConfigurationError::class
        );

        Assert::keyExists($state, 'cmdotcom:recipient');
        Assert::keyExists($state, 'cmdotcom:originator');

        // Send SMS
        $response = $this->otpUtils->sendMessage(
            $api_key,
            $code,
            $state['cmdotcom:recipient'],
            $state['cmdotcom:originator'],
        );

        if ($response->statusCode === TextClientStatusCodes::OK) {
            $this->logger::info("Message with ID " . $response->details[0]["reference"] . " was send successfully!");

            // Salt & hash it
            $cryptoUtils = new Utils\Crypto();
            $hash = $cryptoUtils->pwHash($code);

            // Store hash & time
            $state['cmdotcom:hash'] = $hash;
            $state['cmdotcom:timestamp'] = time();

            // Save state and redirect
            $id = Auth\State::saveState($state, 'cmdotcom:request');
            $url = Module::getModuleURL('cmdotcom/enterCode');
        } else {
            $msg = [
                "Message could not be send:",
                "Response: " . $response->statusMessage . " (" . $response->statusCode . ")"
            ];

            foreach ($msg as $line) {
                $this->logger::error($line);
            }
            $state['cmdotcom:sendFailure'] = $msg;

            // Save state and redirect
            $id = Auth\State::saveState($state, 'cmdotcom:request');
            $url = Module::getModuleURL('cmdotcom/promptResend');
        }

        return new RunnableResponse([$this->httpUtils, 'redirectTrustedURL'], [$url, ['AuthState' => $id]]);
    }
}
