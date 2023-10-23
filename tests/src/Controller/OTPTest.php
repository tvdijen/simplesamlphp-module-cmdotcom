<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\cmdotcom\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleSAML\{Auth, Configuration, Error, Logger, Session, Utils};
use SimpleSAML\Assert\Assert;
use SimpleSAML\Module\cmdotcom\Controller;
use SimpleSAML\Module\cmdotcom\Utils\TextMessage as TextUtils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\{Request, RedirectResponse};

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
    protected static Configuration $config;

    /** @var \SimpleSAML\Utils\HTTP */
    protected static Utils\HTTP $httpUtils;

    /** @var \SimpleSAML\Session */
    protected static Session $session;


    /**
     * Set up before class.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$config = Configuration::loadFromArray(
            [
                'module.enable' => [
                    'cmdotcom' => true,
                ],
            ],
            '[ARRAY]',
            'simplesaml',
        );

        self::$httpUtils = new Utils\HTTP();
        self::$session = Session::getSessionFromRequest();
    }


    /**
     */
    public function testEnterCodeMissingState(): void
    {
        $request = Request::create(
            '/enterCode',
            'GET',
        );

        $c = new Controller\OTP(self::$config, self::$session);

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

        $c = new Controller\OTP(self::$config, self::$session);

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

        $c = new Controller\OTP(self::$config, self::$session);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage('Missing AuthState parameter.');

        $response = $c->validateCode($request);
    }


    /**
     */
    public function testValidateCodeIncorrect(): void
    {
        if (getenv('CMDOTCOM_PRODUCT_KEY') === false) {
            $this->markTestSkipped('No productKey available to actually test the CM API.');
            return;
        }

        $_SERVER['REQUEST_URI'] = '/validateCode?AuthState=someState';
        $request = Request::create(
            '/validateCode?AuthState=someState',
            'POST',
            [
                'otp' => '321',
            ]
        );

        $c = new Controller\OTP(self::$config, self::$session);

        $c->setHttpUtils(self::$httpUtils);
        $c->setLogger(new class () extends Logger {
            public static function warning(string $string): void
            {
                // do nothing
            }
        });
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:productToken' => getenv('CMDOTCOM_PRODUCT_KEY'),
                    'cmdotcom:codeLength' => 6,
                    'cmdotcom:reference' => '00000000-0000-0000-0000-000000000000',
                    'cmdotcom:notBefore' => (new DateTimeImmutable())
                        ->setTimestamp(time() - 1)
                        ->format(DateTimeInterface::ATOM),
                    'cmdotcom:notAfter' => (new DateTimeImmutable())
                        ->setTimestamp(time() + 1)
                        ->format(DateTimeInterface::ATOM),
                ];
            }
        });

        $response = $c->validateCode($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue($response->isRedirect());
        $this->assertStringStartsWith(
            'http://localhost/simplesaml/module.php/cmdotcom/enterCode?AuthState=_',
            $response->getTargetUrl(),
        );
    }


    /**
     */
    public function testValidateCodeExpired(): void
    {
        $_SERVER['REQUEST_URI'] = '/validateCode?AuthState=someState';
        $request = Request::create(
            '/validateCode?AuthState=someState',
            'POST',
            [
                'otp' => '123456',
            ]
        );

        $c = new Controller\OTP(self::$config, self::$session);

        $c->setHttpUtils(self::$httpUtils);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:validFor' => 600,
                    'cmdotcom:reference' => '00000000-0000-0000-0000-000000000000',
                    'cmdotcom:notBefore' => (new DateTimeImmutable())
                        ->setTimestamp(time() - 1400)
                        ->format(DateTimeInterface::ATOM),
                    'cmdotcom:notAfter' => (new DatetimeImmutable())
                        ->setTimestamp(time() - 800)
                        ->format(DateTimeInterface::ATOM),
                ];
            }
        });

        $response = $c->validateCode($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue($response->isRedirect());
        $this->assertStringStartsWith(
            'http://localhost/simplesaml/module.php/cmdotcom/promptResend?AuthState=_',
            $response->getTargetUrl()
        );
    }


    /**
     */
    public function testsendCodeMissingState(): void
    {
        $request = Request::create(
            '/sendCode',
            'GET',
        );

        $c = new Controller\OTP(self::$config, self::$session);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage('Missing AuthState parameter.');

        $response = $c->sendCode($request);
    }


    /**
     */
    public function testsendCodeSuccess(): void
    {
        if (getenv('CMDOTCOM_PRODUCT_KEY') === false) {
            $this->markTestSkipped('No productKey available to actually test the CM API.');
            return;
        }

        $request = Request::create(
            '/sendCode?AuthState=someState',
            'POST',
            []
        );

        $c = new Controller\OTP(self::$config, self::$session);

        $c->setLogger(new class () extends Logger {
            public static function info(string $string): void
            {
                // do nothing
            }
        });

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:productToken' => getenv('CMDOTCOM_PRODUCT_KEY'),
                    'cmdotcom:recipient' => getenv('CMDOTCOM_PHONE_NUMBER'),
                    'cmdotcom:originator' => 'PHPUNIT',
                    'cmdotcom:validFor' => 600,
                    'cmdotcom:codeLength' => 6,
                    'cmdotcom:allowPush' => false,
                ];
            }
        });

        $response = $c->sendCode($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue($response->isRedirect());
        $this->assertStringStartsWith(
            'http://localhost/simplesaml/module.php/cmdotcom/enterCode?AuthState=_',
            $response->getTargetUrl()
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

        $c = new Controller\OTP(self::$config, self::$session);

        $c->setLogger(new class () extends Logger {
            public static function error(string $string): void
            {
                // do nothing
            }
        });

        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'cmdotcom:productToken' => '00000000-0000-0000-0000-000000000000',
                    'cmdotcom:recipient' => getenv('CMDOTCOM_PHONE_NUMBER'),
                    'cmdotcom:originator' => 'PHPUNIT',
                    'cmdotcom:codeLength' => 6,
                    'cmdotcom:validFor' => 600,
                    'cmdotcom:allowPush' => false,
                ];
            }
        });

        $response = $c->sendCode($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue($response->isRedirect());
        $this->assertStringStartsWith(
            'http://localhost/simplesaml/module.php/cmdotcom/promptResend',
            $response->getTargetUrl()
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

        $c = new Controller\OTP(self::$config, self::$session);

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

        $c = new Controller\OTP(self::$config, self::$session);

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

        $c = new Controller\OTP(self::$config, self::$session);

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
    public function testPromptResendUnknownReason(): void
    {
        $request = Request::create(
            '/promptResend',
            'GET',
            [
                'AuthState' => 'someState',
            ]
        );

        $c = new Controller\OTP(self::$config, self::$session);

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
