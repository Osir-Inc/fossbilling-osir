<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Mapping\ContactMapper;
use Osir\FossBilling\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactMapperTest extends TestCase
{
    public function testMapsCompleteContact(): void
    {
        self::assertSame([
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'email' => 'ada@example.org',
            'phone' => '+44.2079460000',
            'street1' => '1 Engine Street',
            'city' => 'London',
            'country' => 'GB',
            'organization' => 'Analytical Ltd',
            'postalCode' => 'N1 9GU',
            'externalId' => 'fb:x:example.com',
        ], ContactMapper::toRegistrant(Fixtures::contact(), 'fb:x:example.com'));
    }

    public function testListsEveryMissingFieldAndNoValues(): void
    {
        try {
            ContactMapper::toRegistrant(Fixtures::contact(['firstName' => ' ', 'city' => null, 'email' => 'not-an-email', 'country' => 'Germany']));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('first name', $e->getMessage());
            self::assertStringContainsString('city', $e->getMessage());
            self::assertStringContainsString('e-mail', $e->getMessage());
            self::assertStringContainsString('country', $e->getMessage());
            self::assertStringNotContainsString('not-an-email', $e->getMessage(), 'values must not be echoed');
        }
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function phones(): iterable
    {
        yield 'plain' => ['1', '555-555-0100', '+1.5555550100'];
        yield 'plus in cc' => ['+1', '(555) 555 0100', '+1.5555550100'];
        yield 'cc repeated with plus' => ['1', '+1 555 555 0100', '+1.5555550100'];
        yield 'cc repeated with 00' => ['44', '0044 20 7946 0000', '+44.2079460000'];
        yield 'national trunk 0 dropped (GB)' => ['44', '020 7946 0000', '+44.2079460000'];
        yield 'national trunk 0 dropped (DE)' => ['49', '030 123456', '+49.30123456'];
        yield 'Italy keeps its leading 0' => ['39', '06 1234 5678', '+39.0612345678'];
        yield 'three-digit cc' => ['355', '69 123 4567', '+355.691234567'];
    }

    #[DataProvider('phones')]
    public function testNormalisesPhoneToEppFormat(?string $cc, ?string $number, string $expected): void
    {
        self::assertSame($expected, ContactMapper::toRegistrant(Fixtures::contact(['phoneCountryCode' => $cc, 'phone' => $number]))['phone']);
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function badPhones(): iterable
    {
        yield 'no cc' => [null, '5555550100'];
        yield 'international number without cc field' => [null, '+44 20 7946 0000'];
        yield 'cc too long' => ['12345', '5555550100'];
        yield 'too short' => ['1', '123'];
        yield 'too long' => ['1', '1234567890123456'];
    }

    #[DataProvider('badPhones')]
    public function testRejectsBadPhones(?string $cc, ?string $number): void
    {
        $this->expectException(ValidationException::class);
        ContactMapper::toRegistrant(Fixtures::contact(['phoneCountryCode' => $cc, 'phone' => $number]));
    }

    public function testInvalidUtf8IsReportedNotDropped(): void
    {
        try {
            ContactMapper::toRegistrant(Fixtures::contact(['street2' => "Flat \xC3\x28 2"]));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertStringContainsString('address line 2 (invalid characters)', $e->getMessage());
        }
    }

    public function testStripsControlCharactersAndCollapsesWhitespace(): void
    {
        $payload = ContactMapper::toRegistrant(Fixtures::contact(['street1' => "1 Engine\r\nStreet\t\t", 'firstName' => "Ada\0"]));
        self::assertSame('1 Engine Street', $payload['street1']);
        self::assertSame('Ada', $payload['firstName']);
    }

    public function testRejectsOverlongInsteadOfTruncating(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('postal code (too long)');
        ContactMapper::toRegistrant(Fixtures::contact(['postalCode' => str_repeat('9', 17)]));
    }

    public function testOmitsEmptyOptionalFields(): void
    {
        $payload = ContactMapper::toRegistrant(Fixtures::contact(['organization' => '', 'postalCode' => null]));
        self::assertArrayNotHasKey('organization', $payload);
        self::assertArrayNotHasKey('postalCode', $payload);
        self::assertArrayNotHasKey('externalId', $payload);
    }
}
