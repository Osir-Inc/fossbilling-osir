<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

use Osir\FossBilling\Exception\ValidationException;

/**
 * Validates a registrant and converts it to OSIR's contact payload.
 *
 * Strict on purpose: the registrant becomes the legal holder of the domain, so an incomplete contact
 * must never reach the registry, where it could be completed with other data. Every required field
 * is checked here, and an incomplete contact stops the operation with a message naming the missing fields.
 */
final class ContactMapper
{
    /** RFC 5733 postalLineType / e-mail limits (characters). */
    private const int MAX_LINE = 255;
    private const int MAX_POSTAL_CODE = 16;

    /**
     * Country codes whose international numbers KEEP the leading 0 of the national form (Italy,
     * San Marino, Vatican City, Côte d'Ivoire, Gabon, Republic of the Congo). Everywhere else a
     * leading 0 is the national trunk prefix and is dropped: "020 7946 0000" (GB) → +44.2079460000.
     */
    private const array KEEPS_LEADING_ZERO = ['39', '378', '379', '225', '241', '242'];

    /**
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function toRegistrant(ContactData $contact, ?string $externalId = null): array
    {
        $missing = [];
        $firstName = self::field($contact->firstName, 'first name', self::MAX_LINE, true, $missing);
        $lastName = self::field($contact->lastName, 'last name', self::MAX_LINE, true, $missing);
        $street1 = self::field($contact->street1, 'address', self::MAX_LINE, true, $missing);
        $city = self::field($contact->city, 'city', self::MAX_LINE, true, $missing);
        $email = self::email($contact->email, $missing);
        $country = self::country($contact->country, $missing);
        $phone = self::phone($contact->phoneCountryCode, $contact->phone, $missing);
        $optional = [
            'organization' => self::field($contact->organization, 'company', self::MAX_LINE, false, $missing),
            'street2' => self::field($contact->street2, 'address line 2', self::MAX_LINE, false, $missing),
            'state' => self::field($contact->state, 'state', self::MAX_LINE, false, $missing),
            'postalCode' => self::field($contact->postalCode, 'postal code', self::MAX_POSTAL_CODE, false, $missing),
        ];

        if ($missing !== []) {
            throw new ValidationException(
                'The domain contact is incomplete or invalid: :fields. Please update the contact details and try again.',
                [':fields' => implode(', ', $missing)],
            );
        }

        $payload = [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'street1' => $street1,
            'city' => $city,
            'country' => $country,
        ];

        foreach ($optional as $key => $value) {
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }
        if ($externalId !== null) {
            $payload['externalId'] = $externalId;
        }

        return $payload;
    }

    /**
     * Normalises a phone number to the EPP format "+CC.NNNNNNNN".
     * This is the exact format registries expect; normalising here avoids any ambiguity later.
     *
     * @param list<string> $missing
     */
    private static function phone(?string $countryCode, ?string $number, array &$missing): string
    {
        $cc = ltrim(preg_replace('/\D/', '', (string) $countryCode) ?? '', '0');
        $digits = preg_replace('/\D/', '', (string) $number) ?? '';

        // A number typed in full international form ("+44 20 …") while the country code field is empty.
        if ($cc === '' && str_starts_with(trim((string) $number), '+')) {
            $missing[] = 'phone country code';

            return '';
        }
        // The country code repeated inside the number field ("+1 555…" or "001 555…" with cc = 1).
        if ($cc !== '' && str_starts_with($digits, '00' . $cc)) {
            $digits = substr($digits, 2 + strlen($cc));
        } elseif ($cc !== '' && str_starts_with(trim((string) $number), '+') && str_starts_with($digits, $cc)) {
            $digits = substr($digits, strlen($cc));
        }

        if (strlen($cc) < 1 || strlen($cc) > 3) {
            $missing[] = 'phone country code';

            return '';
        }
        if (str_starts_with($digits, '0') && !in_array($cc, self::KEEPS_LEADING_ZERO, true)) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) < 4 || strlen($cc) + strlen($digits) > 15) {
            $missing[] = 'phone number';

            return '';
        }

        return '+' . $cc . '.' . $digits;
    }

    /** @param list<string> $missing */
    private static function email(?string $email, array &$missing): string
    {
        $email = trim((string) $email);
        if (!mb_check_encoding($email, 'UTF-8')) {
            $missing[] = 'e-mail (invalid characters)';

            return '';
        }
        if ($email === '' || strlen($email) > self::MAX_LINE || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $missing[] = 'e-mail';

            return '';
        }

        return $email;
    }

    /** @param list<string> $missing */
    private static function country(?string $country, array &$missing): string
    {
        $country = strtoupper(trim((string) $country));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $missing[] = 'country (two-letter code)';

            return '';
        }

        return $country;
    }

    /**
     * Cleans one text field. Over-long values are reported, never silently truncated: a cut-off
     * address in WHOIS is worse than asking the customer to shorten it.
     *
     * @param list<string> $missing
     */
    private static function field(?string $value, string $label, int $max, bool $required, array &$missing): string
    {
        if (!mb_check_encoding((string) $value, 'UTF-8')) {
            // Reported, not dropped: preg_replace() would silently turn invalid UTF-8 into ''.
            $missing[] = $label . ' (invalid characters)';

            return '';
        }
        // Collapse whitespace and drop control characters: registries reject them, and they are a
        // log/WHOIS injection vector.
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s{2,}/u', ' ', (string) $value));
        if ($clean === '') {
            if ($required) {
                $missing[] = $label;
            }

            return '';
        }
        if (mb_strlen($clean, 'UTF-8') > $max) {
            $missing[] = $label . ' (too long)';

            return '';
        }

        return $clean;
    }
}
