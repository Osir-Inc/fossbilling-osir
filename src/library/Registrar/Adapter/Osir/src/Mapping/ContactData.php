<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

/**
 * Registrant contact as FOSSBilling knows it, decoupled from FOSSBilling's classes so the
 * mapping rules can be tested on their own. Values are raw (unvalidated) here.
 */
final class ContactData
{
    public function __construct(
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $organization,
        public readonly ?string $email,
        public readonly ?string $phoneCountryCode,
        public readonly ?string $phone,
        public readonly ?string $street1,
        public readonly ?string $street2,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $postalCode,
        public readonly ?string $country,
    ) {}
}
