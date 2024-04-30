<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\cmdotcom\Utils;

use libphonenumber\NumberParseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Module\cmdotcom\Utils\PhoneNumber as PhoneNumberUtils;

/**
 * Set of tests for the PhoneNumber utilities in the "cmdotcom" module.
 */
#[CoversClass(PhoneNumberUtils::class)]
class PhoneNumberTest extends TestCase
{
    /** @var \SimpleSAML\Module\cmdotcom\Utils\PhoneNumber */
    private static PhoneNumberUtils $phoneNumberUtils;


    /**
     * Set up before class.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$phoneNumberUtils = new PhoneNumberUtils();
    }


    /**
     * @param string $input
     * @param string $output
     */
    #[DataProvider('validPhoneNumberProvider')]
    public function testValidPhoneNumberIsSanitizedToE164Format(string $input, string $output): void
    {
        $result = self::$phoneNumberUtils->sanitizePhoneNumber($input);
        $this->assertEquals($result, $output);
    }


    /**
     * @param string $input
     */
    #[DataProvider('invalidPhoneNumberProvider')]
    public function testInvalidPhoneNumberThrowsAnException(string $input): void
    {
        $this->expectException(NumberParseException::class);
        self::$phoneNumberUtils->sanitizePhoneNumber($input);
    }


    /**
     * @return array
     */
    public static function validPhoneNumberProvider(): array
    {
        return [
            ['0031612345678', '0031612345678'],
            ['+31612345678', '0031612345678'],
            ['+32093302323', '003293302323'],
            ['0612345678', '0031612345678'],
        ];
    }


    /**
     * @return array
     */
    public static function invalidPhoneNumberProvider(): array
    {
        return [
            ['1234'],
            ['abc123'],
        ];
    }
}
