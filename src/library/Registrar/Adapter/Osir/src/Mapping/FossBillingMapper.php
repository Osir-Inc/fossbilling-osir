<?php

declare(strict_types=1);

namespace Osir\FossBilling\Mapping;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Domain\Nameservers;
use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Service\OrderRef;

/**
 * Translates between FOSSBilling's registrar objects and the adapter's own types.
 * This is the only class (besides the adapter) that touches Registrar_Domain / Registrar_Domain_Contact.
 */
final class FossBillingMapper
{
    /** @throws ValidationException */
    public static function domainName(\Registrar_Domain $domain): DomainName
    {
        return DomainName::fromParts(self::str($domain->getSld()), self::str($domain->getTld()));
    }

    /** @throws ValidationException */
    public static function nameservers(\Registrar_Domain $domain): Nameservers
    {
        return Nameservers::fromList([$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()]);
    }

    /** @throws ValidationException */
    public static function contact(\Registrar_Domain $domain): ContactData
    {
        $c = $domain->getContactRegistrar();
        if (!$c instanceof \Registrar_Domain_Contact) {
            throw new ValidationException('The domain has no registrant contact.');
        }

        return new ContactData(
            firstName: self::str($c->getFirstName()),
            lastName: self::str($c->getLastName()),
            organization: self::str($c->getCompany()),
            email: self::str($c->getEmail()),
            phoneCountryCode: self::str($c->getTelCc()),
            phone: self::str($c->getTel()),
            street1: self::str($c->getAddress1()),
            street2: self::str($c->getAddress2()),
            city: self::str($c->getCity()),
            state: self::str($c->getState()),
            postalCode: self::str($c->getZip()),
            country: self::str($c->getCountry()),
        );
    }

    /**
     * Copies registry state onto the domain object FOSSBilling passed in. Contacts are left as
     * FOSSBilling has them: OSIR stores the contact FOSSBilling sent, so FOSSBilling's copy is the
     * authoritative one, and OSIR's placeholder values must never overwrite a customer's data.
     *
     * @param bool $keepExpiry leave FOSSBilling's expiry date as it is (see Registrar_Adapter_Osir::getDomainDetails)
     */
    public static function applyInfo(\Registrar_Domain $domain, DomainInfo $info, bool $keepExpiry = false): \Registrar_Domain
    {
        if ($info->createdAt !== null) {
            $domain->setRegistrationTime($info->createdAt);
        }
        if ($info->expiresAt !== null && !$keepExpiry) {
            $domain->setExpirationTime($info->expiresAt);
        }
        if ($info->nameservers !== []) {
            $ns = array_pad(array_slice($info->nameservers, 0, 4), 4, null);
            $domain->setNs1($ns[0]);
            $domain->setNs2($ns[1]);
            $domain->setNs3($ns[2]);
            $domain->setNs4($ns[3]);
        }
        if ($info->status !== DomainStatus::PendingTransfer) {
            $domain->setLocked($info->locked);
            $domain->setPrivacyEnabled($info->privacy);
        }
        // FOSSBilling serialises this object into its database; never let an auth code ride along.
        $domain->setEpp(null);

        return $domain;
    }

    /**
     * The FOSSBilling order, across the 0.8 (RedBean model) and newer (Doctrine entity) APIs.
     * FOSSBilling writes `created_at` with date('Y-m-d H:i:s') in PHP's default timezone, which is
     * therefore the zone it is read back in.
     */
    public static function orderRef(?object $order): ?OrderRef
    {
        if ($order === null) {
            return null;
        }
        $id = method_exists($order, 'getId') ? $order->getId() : ($order->id ?? null);
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        $created = method_exists($order, 'getCreatedAt') ? $order->getCreatedAt() : ($order->created_at ?? null);
        $expires = method_exists($order, 'getExpiresAt') ? $order->getExpiresAt() : ($order->expires_at ?? null);
        $status = method_exists($order, 'getStatus') ? $order->getStatus() : ($order->status ?? null);

        $price = method_exists($order, 'getPrice') ? $order->getPrice() : ($order->price ?? null);
        $currency = method_exists($order, 'getCurrency') ? $order->getCurrency() : ($order->currency ?? null);

        return new OrderRef(
            (string) $id,
            self::timestamp($created),
            self::timestamp($expires),
            is_string($status) ? $status : null,
            self::minorUnits($price),
            is_string($currency) && $currency !== '' ? strtoupper($currency) : null,
        );
    }

    /** FOSSBilling stores order prices as a decimal amount; OSIR quotes in cents. */
    private static function minorUnits(mixed $price): ?int
    {
        if (is_string($price) && is_numeric($price)) {
            $price = (float) $price;
        }
        if (!is_int($price) && !is_float($price)) {
            return null;
        }
        if (!is_finite((float) $price) || $price < 0) {
            return null;
        }

        return (int) round((float) $price * 100);
    }

    private static function timestamp(mixed $value): ?int
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->getTimestamp(),
            is_string($value) && $value !== '' => ($t = strtotime($value)) !== false ? $t : null,
            default => null,
        };
    }

    /**
     * Registration period in years. FOSSBilling stores it as an integer; an empty period is treated
     * as FOSSBilling's own default of 1 year, a non-positive one is refused on money paths.
     *
     * @throws ValidationException
     */
    public static function years(\Registrar_Domain $domain): int
    {
        $period = $domain->getRegistrationPeriod();
        if ($period === null) {
            return 1;
        }
        if ($period < 1) {
            throw new ValidationException('The domain has an invalid registration period.');
        }

        return $period;
    }

    public static function expiry(\Registrar_Domain $domain): ?int
    {
        $value = $domain->getExpirationTime();

        return is_int($value) && $value > 0 ? $value : (is_numeric($value) && (int) $value > 0 ? (int) $value : null);
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
