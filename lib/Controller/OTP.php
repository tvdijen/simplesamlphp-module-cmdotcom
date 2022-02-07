<?php

namespace SimpleSAML\Module\cmdotcom\Controller;

use GuzzleHttp\Client as GuzzleClient;
use RuntimeException;
use SimpleSAML\Assert\Assert;
use SimpleSAML\{Auth, Configuration, Error, Logger, Module, Session, Utils};
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\{RedirectResponse, Request};
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
    public const API_BASE = 'https://api.cmtelecom.com';
    public const HEADER = 'X-CM-ProductToken';

    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Logger */
    protected Logger $logger;

    /** @var \SimpleSAML\Session */
    protected Session $session;

    /** @var \SimpleSAML\Utils\HTTP */
    protected Utils\HTTP $httpUtils;

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
        $this->logger = new Logger();
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
     * Display the page where the validation code should be entered.
     *
     * @return \SimpleSAML\XHTML\Template
     */
    public function enterCode(Request $request): Template
    {
        $id = $request->query->get('AuthState', null);
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
        $id = $request->query->get('AuthState', null);
        if ($id === null) {
            throw new Error\BadRequest('Missing AuthState parameter.');
        }

        $state = $this->authState::loadState($id, 'cmdotcom:request');

        Assert::keyExists($state, 'cmdotcom:notBefore');
        $notBefore = strtotime($state['cmdotcom:notBefore']);
        Assert::positiveInteger($notBefore);

        Assert::keyExists($state, 'cmdotcom:notAfter');
        $notAfter = strtotime($state['cmdotcom:notAfter']);
        Assert::positiveInteger($notAfter);

        // Verify that code was entered within a reasonable amount of time
        if (time() < $notBefore || time() > $notAfter) {
            $state['cmdotcom:expired'] = true;

            $id = Auth\State::saveState($state, 'cmdotcom:request');
            $url = Module::getModuleURL('cmdotcom/promptResend');

            return new RunnableResponse([$this->httpUtils, 'redirectTrustedURL'], [$url, ['AuthState' => $id]]);
        }

        Assert::keyExists($state, 'cmdotcom:reference');
        Assert::stringNotEmpty($state['cmdotcom:reference']);

        $options = [
            'base_uri' => self::API_BASE,
            //'debug' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                self::HEADER => $state['cmdotcom:productToken']
            ],
            'proxy' => [
                'http'
            ],
            'timeout' => 3.0,
        ];

        $proxy = $this->config->getString('proxy', null);
        if ($proxy !== null) {
            $options += ['proxy' => ['http' => $proxy, 'https' => $proxy]];
        }

        $client = new GuzzleClient($options);
        $response = $client->request(
            'POST',
            '/v1.0/otp/verify',
            [
                'json' => [
                    'id' => $state['cmdotcom:referece'],
                    'code' => $request->request->get('otp'),
                ],
            ],
        );

        $responseMsg = json_decode($response->getBody());
        if ($response->getStatusCode() === 200 && $responseMsg->valid === true) {
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
        $id = $request->query->get('AuthState', null);
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
            Assert::isArray($state['cmdotcom:sendFailure']);
            $t->data['message'] = $state['cmdotcom:sendFailure'];
        } elseif (isset($state['cmdotcom:resendRequested']) && ($state['cmdotcom:resendRequested'] === true)) {
            $t->data['message'] = [];
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
        $id = $request->query->get('AuthState', null);
        if ($id === null) {
            throw new Error\BadRequest('Missing AuthState parameter.');
        }

        $state = $this->authState::loadState($id, 'cmdotcom:request');

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
            'proxy' => [
                'http'
            ],
            'timeout' => 3.0,
        ];

        $proxy = $this->config->getString('proxy', null);
        if ($proxy !== null) {
            $options += ['proxy' => ['http' => $proxy, 'https' => $proxy]];
        }

        // Send SMS
        $client = new GuzzleClient($options);
        $response = $client->request(
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

        $responseMsg = json_decode($response->getBody());
        if ($response->getStatusCode() === 200) {
            $this->logger::info("Message with ID " . $responseMsg->id . " was send successfully!");

            $state['cmdotcom:reference'] = $responseMsg->id;
            $state['cmdotcom:notBefore'] = $responseMsg->createdAt;
            $state['cmdotcom:notAfter'] = $responseMsg->expireAt;

            // Save state and redirect
            $id = Auth\State::saveState($state, 'cmdotcom:request');
            $url = Module::getModuleURL('cmdotcom/enterCode');
        } else {
            $msg = [
                sprintf(
                    "Message could not be send: HTTP/%d %s",
                    $response->getStatusCode(),
                    $response->getReasonPhrase()
                ),
                sprintf("Response: %s (%d)", $responseMsg->message, $response->status),
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
