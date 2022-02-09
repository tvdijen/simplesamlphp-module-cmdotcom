<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\cmdotcom\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleSAML\{Auth, Configuration, Error, Logger, Session, Utils};
use SimpleSAML\Assert\Assert;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Module\cmdotcom\Controller;
use SimpleSAML\Module\cmdotcom\Utils\TextMessage as TextUtils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Set of tests for the controllers in the "cmdotcom" module.
 *
 * @covers \SimpleSAML\Module\cmdotcom\Controller\OTP
 */
class OTPTest extends TestCase
{
    /** @var string|null */
    public static ?string $productToken = null;

    /** @var string|null */
    public static ?string $phoneNumber = null;

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

        $productToken = getenv('CMDOTCOM_PRODUCT_KEY');
        if ($productToken !== false) {
            Assert::stringNotEmpty($productToken);
            self::$productToken = $productToken;
        }

        $phoneNumber = getenv('CMDOTCOM_PHONE_NUMBER');
        if ($phoneNumber !== false) {
            Assert::stringNotEmpty($phoneNumber);
            self::$phoneNumber = $phoneNumber;
        }

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
    public function testValidateCodeIncorrect(): void
    {
        if (self::$productToken === null) {
            $this->markTestSkipped('No productKey available to actually test the CM API.');
            return;
        }

        $request = Request::create(
            '/validateCode?AuthState=someState',
            'POST',
            [
                'otp' => '321',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setHttpUtils($this->httpUtils);
        $c->setLogger(new class () extends Logger {
            public static function warning(string $str): void
            {
                // do nothing
            }
        });
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:productToken' => OTPTest::$productToken,
                    'cmdotcom:reference' => 'abc123',
                    'cmdotcom:notBefore' => (new DateTimeImmutable())->setTimestamp(time() - 1)->format(DateTimeInterface::ATOM),
                    'cmdotcom:notAfter' => (new DateTimeImmutable())->setTimestamp(time() + 1)->format(DateTimeInterface::ATOM),
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
            '/validateCode?AuthState=someState',
            'POST',
            [
                'otp' => '123456',
            ]
        );

        $c = new Controller\OTP($this->config, $this->session);

        $c->setHttpUtils($this->httpUtils);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:validFor' => 600,
                    'cmdotcom:reference' => 'abc123',
                    'cmdotcom:notBefore' => (new DateTimeImmutable())->setTimestamp(time() - 1400)->format(DateTimeInterface::ATOM),
                    'cmdotcom:notAfter' => (new DatetimeImmutable())->setTimestamp(time() - 800)->format(DateTimeInterface::ATOM),
                ];
            }
        });

        $response = $c->validateCode($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals([$this->httpUtils, 'redirectTrustedURL'], $response->getCallable());
        $this->assertEquals('http://localhost/simplesaml/module.php/cmdotcom/promptResend', $response->getArguments()[0]);
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
        if (self::$productToken === null) {
            $this->markTestSkipped('No productKey available to actually test the CM API.');
            return;
        }

        $request = Request::create(
            '/sendCode?AuthState=someState',
            'POST',
            []
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
                    'cmdotcom:productToken' => OTPTest::$productToken,
                    'cmdotcom:recipient' => OTPTest::$phoneNumber,
                    'cmdotcom:originator' => 'PHPUNIT',
                    'cmdotcom:validFor' => 600,
                ];
            }
        });

        $response = $c->sendCode($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals([$this->httpUtils, 'redirectTrustedURL'], $response->getCallable());
        $this->assertEquals(
            'http://localhost/simplesaml/module.php/cmdotcom/enterCode',
            $response->getArguments()[0]
        );
    }


    /**
     */
    public function testsendCodeFailure(): void
    {
        $request = Request::create(
            '/sendCode?AuthState=someState',
            'POST',
            []
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
                    'cmdotcom:productToken' => OTPTest::$phoneNumber,
                    'cmdotcom:recipient' => OTPTest::$phoneNumber,
                    'cmdotcom:originator' => 'PHPUNIT',
                ];
            }
        });

        $response = $c->sendCode($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertEquals([$this->httpUtils, 'redirectTrustedURL'], $response->getCallable());
        $this->assertEquals(
            'http://localhost/simplesaml/module.php/cmdotcom/promptResend',
            $response->getArguments()[0]
        );
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
                    'cmdotcom:sendFailure' => ['something went wrong']
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
