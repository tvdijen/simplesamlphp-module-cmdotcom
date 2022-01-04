<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\cmdotcom\Controller;

use CMText\TextClient;
use CMText\TextClientResult;
use CMText\TextClientStatusCodes;;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Logger;
use SimpleSAML\Module\cmdotcom\Controller;
use SimpleSAML\Module\cmdotcom\Utils\TextMessage as TextUtils;
use SimpleSAML\Session;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Set of tests for the controllers in the "cmdotcom" module.
 *
 * @covers \SimpleSAML\Module\cmdotcom\Controller\OTP
 */
class OTPTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Utils\HTTP */
    protected Utils\HTTP $httpUtils;

    /** @var \SimpleSAML\Session */
    protected Session $session;

    /** @var string $otpHash */
    protected string $otpHash;


    /**
     * Set up for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => [
                    'cmdotcom' => true,
                ],
            ],
            '[ARRAY]',
            'simplesaml',
        );

        $this->session = Session::getSessionFromRequest();

        Configuration::setPreLoadedConfig(
            Configuration::loadFromArray(
                [
                    'api_key' => 'secret',
                ],
                '[ARRAY]',
                'simplesaml',
            ),
            'module_cmdotcom.php',
            'simplesaml',
        );

        $this->httpUtils = new Utils\HTTP();
    }


    /**
     */
    public function testEnterCodeMissingState(): void
    {
        $request = Request::create(
            '/enterCode',
            'GET',
        );

        $c = new Controller\OTP($this->config, $this->session);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage('Missing AuthState parameter.');

        $response = $c->enterCode($request);
    }


    /**
     */
    public function testEnterCode(): void
    {
        $request = Request::create(
            '/enterCode',
            'GET',
            [
                'AuthState' => 'someState',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [];
            }
        });

        $response = $c->enterCode($request);

        $this->assertInstanceOf(Template::class, $response);
        $this->assertTrue($response->isSuccessful());
    }


    /**
     */
    public function testValidateCodeMissingState(): void
    {
        $request = Request::create(
            '/validateCode',
            'GET',
        );

        $c = new Controller\OTP($this->config, $this->session);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage('Missing AuthState parameter.');

        $response = $c->validateCode($request);
    }


    /**
     */
    public function testValidateCodeCorrect(): void
    {
        $request = Request::create(
            '/validateCode',
            'POST',
            [
                'AuthState' => 'someState',
                'otp' => '123456',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:hash' => '$2y$10$X9n7ylaGdlwomlxR7Amix.FThsOdglyNO1RYYveoshKldom49U1tC', // 123456
                    'cmdotcom:timestamp' => time() - 1,
                ];
            }
        });

        $response = $c->validateCode($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals([Auth\ProcessingChain::class, 'resumeProcessing'], $response->getCallable());
    }


    /**
     */
    public function testValidateCodeIncorrect(): void
    {
        $request = Request::create(
            '/validateCode',
            'POST',
            [
                'AuthState' => 'someState',
                'otp' => '654321',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setHttpUtils($this->httpUtils);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:hash' => '$2y$10$X9n7ylaGdlwomlxR7Amix.FThsOdglyNO1RYYveoshKldom49U1tC', // 123456
                    'cmdotcom:timestamp' => time() - 1,
                ];
            }
        });

        $response = $c->validateCode($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals([$this->httpUtils, 'redirectTrustedURL'], $response->getCallable());
        $this->assertEquals('http://localhost/simplesaml/module.php/cmdotcom/enterCode', $response->getArguments()[0]);
    }


    /**
     */
    public function testValidateCodeExpired(): void
    {
        $request = Request::create(
            '/validateCode',
            'POST',
            [
                'AuthState' => 'someState',
                'otp' => '123456',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setHttpUtils($this->httpUtils);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:hash' => '$2y$10$X9n7ylaGdlwomlxR7Amix.FThsOdglyNO1RYYveoshKldom49U1tC', // 123456
                    'cmdotcom:timestamp' => time() - 800, // They expire after 600 by default
                ];
            }
        });

        $response = $c->validateCode($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals([$this->httpUtils, 'redirectTrustedURL'], $response->getCallable());
        $this->assertEquals('http://localhost/simplesaml/module.php/cmdotcom/resendCode', $response->getArguments()[0]);
    }


    /**
     */
    public function testsendCodeMissingState(): void
    {
        $request = Request::create(
            '/sendCode',
            'GET',
        );

        $c = new Controller\OTP($this->config, $this->session);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage('Missing AuthState parameter.');

        $response = $c->sendCode($request);
    }


    /**
     */
    public function testsendCodeSuccess(): void
    {
        $request = Request::create(
            '/sendCode',
            'POST',
            [
                'AuthState' => 'someState',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setLogger(new class () extends Logger {
            public static function info(string $str): void
            {
                // do nothing
            }
        });

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:recipient' => '0031612345678',
                    'cmdotcom:originator' => 'PHPUNIT',
                ];
            }
        });

        $c->setTextUtils(new class () extends TextUtils {
            public function sendMessage(string $api_key, string $code, string $recipient, string $originator): TextClientResult
            {
                $result = new TextClientResult(TextClientStatusCodes::OK, json_encode(["bogus value"]));
                $result->statusCode = TextClientStatusCodes::OK;
                $result->details = [
                    0 => [
                        "reference" => "Example_Reference",
                        "status" => "Accepted",
                        "to" => "Example_PhoneNumber",
                        "parts" => 1,
                        "details" => null
                    ],
                ];
                return $result;
            }
        });

        $response = $c->sendCode($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals([$this->httpUtils, 'redirectTrustedURL'], $response->getCallable());
        $this->assertEquals('http://localhost/simplesaml/module.php/cmdotcom/enterCode', $response->getArguments()[0]);
    }


    /**
     */
    public function testsendCodeFailure(): void
    {
        $request = Request::create(
            '/sendCode',
            'POST',
            [
                'AuthState' => 'someState',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setLogger(new class () extends Logger {
            public static function error(string $str): void
            {
                // do nothing
            }
        });

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:recipient' => '0031612345678',
                    'cmdotcom:originator' => 'PHPUNIT',
                ];
            }
        });

        $c->setTextUtils(new class () extends TextUtils {
            public function sendMessage(string $api_key, string $code, string $recipient, string $originator): TextClientResult
            {
                $result = new TextClientResult(TextClientStatusCodes::REJECTED, json_encode(["bogus value"]));
                $result->statusCode = TextClientStatusCodes::REJECTED;
                return $result;
            }
        });

        $response = $c->sendCode($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals([$this->httpUtils, 'redirectTrustedURL'], $response->getCallable());
        $this->assertEquals('http://localhost/simplesaml/module.php/cmdotcom/promptResend', $response->getArguments()[0]);
    }


    /**
     */
    public function testPromptResendMissingState(): void
    {
        $request = Request::create(
            '/promptResend',
            'GET',
        );

        $c = new Controller\OTP($this->config, $this->session);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage('Missing AuthState parameter.');

        $response = $c->promptResend($request);
    }


    /**
     */
    public function testPromptResendExpired(): void
    {
        $request = Request::create(
            '/promptResend',
            'GET',
            [
                'AuthState' => 'someState',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:expired' => true
                ];
            }
        });

        $response = $c->promptResend($request);

        $this->assertInstanceOf(Template::class, $response);
        $this->assertTrue($response->isSuccessful());
    }


    /**
     */
    public function testPromptResendSendFailure(): void
    {
        $request = Request::create(
            '/promptResend',
            'GET',
            [
                'AuthState' => 'someState',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:sendFailure' => 'something went wrong'
                ];
            }
        });

        $response = $c->promptResend($request);

        $this->assertInstanceOf(Template::class, $response);
        $this->assertTrue($response->isSuccessful());
    }


    /**
     */
    public function testPromptResendRequested(): void
    {
        $request = Request::create(
            '/promptResend',
            'GET',
            [
                'AuthState' => 'someState',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:resendRequested' => true
                ];
            }
        });

        $response = $c->promptResend($request);

        $this->assertInstanceOf(Template::class, $response);
        $this->assertTrue($response->isSuccessful());
    }


    /**
     */
    public function testPromptResendUnknownReason(): void
    {
        $request = Request::create(
            '/promptResend',
            'GET',
            [
                'AuthState' => 'someState',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                ];
            }
        });

        $this->expectException(RuntimeException::class);
        $c->promptResend($request);
    }
}
