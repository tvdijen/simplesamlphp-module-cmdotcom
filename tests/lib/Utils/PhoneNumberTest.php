<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\cmdotcom\Utils;

use libphonenumber\NumberParseException;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Module\cmdotcom\Utils\PhoneNumber as PhoneNumberUtils;

/**
 * Set of tests for the PhoneNumber utilities in the "cmdotcom" module.
 *
 * @covers \SimpleSAML\Module\cmdotcom\Utils\PhoneNumber
 */
class PhoneNumberTest extends TestCase
{
    /** @var \SimpleSAML\Module\cmdotcom\Utils\PhoneNumber */
    private PhoneNumberUtils $phoneNumberUtils;


    /**
     * Set up for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->phoneNumberUtils = new PhoneNumberUtils();
    }


    /**
     * @dataProvider validPhoneNumberProvider
     *
     * @param string $input
     * @param string $output
     */
    public function testValidPhoneNumberIsSanitizedToE164Format(string $input, string $output): void
    {
        $result = $this->phoneNumberUtils->sanitizePhoneNumber($input);
        $this->assertEquals($result, $output);
    }


    /**
     * @dataProvider invalidPhoneNumberProvider
     *
     * @param string $input
     */
    public function testInvalidPhoneNumberThrowsAnException(string $input): void
    {
        $this->expectException(NumberParseException::class);
        $this->phoneNumberUtils->sanitizePhoneNumber($input);
    }


    /**
     * @return array
     */
    public function validPhoneNumberProvider(): array
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
    public function invalidPhoneNumberProvider(): array
    {
        return [
            ['1234'],
            ['abc123'],
        ];
    }
}
