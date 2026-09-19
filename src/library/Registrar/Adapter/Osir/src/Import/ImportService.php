<?php

declare(strict_types=1);

namespace Osir\FossBilling\Import;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\ApiErrorKind;
use Osir\FossBilling\Exception\ApiException;
use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Http\ApiClient;
use Osir\FossBilling\Http\ApiRequest;
use Osir\FossBilling\Mapping\ContactData;
use Osir\FossBilling\Mapping\DomainInfoParser;
use Osir\FossBilling\Mapping\DomainStatus;
use Osir\FossBilling\Support\SafeLogger;

/**
 * Read-only queries behind the "Import from OSIR" tools: the TLD catalog, what a TLD costs this
 * account, and the domains held in the account. Nothing here changes anything at OSIR or costs
 * money; writing into FOSSBilling is the caller's job.
 */
final class ImportService
{
    private const string P_CATALOG = '/v1/public/catalog/domains';
    private const string P_QUOTE = '/v2/domains/{domain}/quote';
    private const string P_DOMAINS = '/v2/domains';
    private const string P_CONTACTS = '/v2/domains/{domain}/contacts';
    private const int PAGE_SIZE = 100;
    /** OSIR's own fee is a flat amount below this price and a percentage from it (contract §5). */
    private const int PERCENT_FEE_FROM_CENTS = 10_000;
    /** 20 000 domains; a listing that has not ended by then is treated as broken, not followed forever. */
    private const int MAX_PAGES = 200;

    /** @var \Closure(): string */
    private readonly \Closure $randomLabel;

    /** @param (\Closure(): string)|null $randomLabel random a-z0-9 string for price probes (tests inject one) */
    public function __construct(
        private readonly ApiClient $api,
        private readonly SafeLogger $log,
        ?\Closure $randomLabel = null,
    ) {
        $this->randomLabel = $randomLabel ?? static fn(): string => bin2hex(random_bytes(5));
    }

    /**
     * OSIR's TLD catalog, keyed and sorted by TLD. Entries that cannot be imported safely are left
     * out (and logged).
     *
     * @return array<string, CatalogTld>
     */
    public function catalog(): array
    {
        $response = $this->api->send(ApiRequest::get(self::P_CATALOG)->unwrapped());
        $rows = $response->data['extensions'] ?? null;
        if (!is_array($rows) || $rows === []) {
            throw new ApiException(ApiErrorKind::Protocol, $response->status, null, 'Catalog response lacks "extensions".', $response->requestId);
        }

        $catalog = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $tld = is_array($row) ? CatalogTld::fromApi($row) : null;
            if ($tld === null) {
                ++$skipped;

                continue;
            }
            $catalog[$tld->tld] = $tld;
        }
        if ($skipped > 0) {
            $this->log->warning(sprintf('OSIR catalog: %d entries without a usable TLD or price were left out.', $skipped));
        }
        ksort($catalog, SORT_STRING);

        return $catalog;
    }

    /**
     * What one year of registration, renewal and transfer costs this account, fees included.
     * The registration price is OSIR's live quote for a random (standard, unregistered) name, for
     * the TLD's minimum period, divided per year (rounded up).
     *
     * @throws RuleException when OSIR does not give a usable standard price
     */
    public function cost(CatalogTld $tld): TldCost
    {
        $years = $tld->minYears;
        $probe = $this->probeName($tld);
        $quote = $this->api->send(ApiRequest::get(ApiRequest::path(self::P_QUOTE, $probe), ['years' => $years])->unwrapped())->data;

        $total = $quote['totalCost'] ?? null;
        $totalWithFees = $quote['totalFees'] ?? null;
        $standard = $quote['standardTotalCost'] ?? $total;
        $unusable = ($quote['valid'] ?? null) !== true
            || ($quote['premium'] ?? false) === true
            || ($quote['registrationYears'] ?? $years) !== $years
            || !is_int($total) || !is_int($totalWithFees) || !is_int($standard)
            || $total <= 0 || $standard <= 0 || $totalWithFees < $total
            || (is_string($quote['warning'] ?? null) && trim($quote['warning']) !== '');
        if ($unusable) {
            throw new RuleException('OSIR did not return a usable price for :tld. Try again later.', [':tld' => $tld->tld]);
        }

        // Per year, rounded up. A first-year promotion lowers totalCost; FOSSBilling keeps a price
        // for good, so the standard price is used. The fees are whatever OSIR adds on top.
        $perYear = static fn(int $cents): int => intdiv($cents + $years - 1, $years);
        $register = $perYear($standard);
        $fees = $perYear($totalWithFees - $total);
        $estimated = $register !== $tld->registerCents;

        $renew = $tld->renewCents;
        $transfer = $tld->transferCents;
        if ($register > $tld->registerCents) {
            // The registry charges more than OSIR's list says; assume the same for renewal and
            // transfer (rounded up) rather than selling them below cost.
            $renew = intdiv($renew * $register + $tld->registerCents - 1, $tld->registerCents);
            $transfer = intdiv($transfer * $register + $tld->registerCents - 1, $tld->registerCents);
        }
        if (($quote['promoApplied'] ?? false) === true && $standard > $total) {
            // The fees were computed on the promotional price; OSIR's fee can depend on the price.
            $estimated = true;
        }

        return new TldCost(
            $tld,
            $register + $fees,
            $renew + $this->feeFor($renew, $register, $fees, $estimated),
            $transfer + $this->feeFor($transfer, $register, $fees, $estimated),
            $fees,
            $estimated,
        );
    }

    /**
     * The fee on a renewal or transfer. OSIR only quotes registrations for names not in the
     * account. Its fee is flat for ordinary prices but becomes a percentage for expensive ones
     * (from 100 USD), so for a component of that size that is dearer than the registration the fee
     * is scaled up in proportion (never down) and the cost is marked as estimated.
     * Over-estimating costs only margin; under-estimating would sell below cost.
     */
    private function feeFor(int $base, int $register, int $fees, bool &$estimated): int
    {
        if ($base < self::PERCENT_FEE_FROM_CENTS || $base <= $register || $fees === 0) {
            return $fees;
        }
        $estimated = true;

        return intdiv($fees * $base + $register - 1, $register);
    }

    /**
     * Every domain in the OSIR account. Entries OSIR returns in an unexpected shape are skipped
     * and logged; they are never guessed into an order.
     *
     * @return array<string, RemoteDomain> keyed by ASCII domain name, in OSIR's order (by expiry)
     */
    public function domains(): array
    {
        $domains = [];
        for ($page = 0, $pages = 1; $page < $pages; ++$page) {
            if ($page >= self::MAX_PAGES) {
                throw new ApiException(ApiErrorKind::Protocol, 200, null, 'Domain listing did not end.', 'n/a');
            }
            $response = $this->api->send(ApiRequest::get(self::P_DOMAINS, ['page' => $page, 'size' => self::PAGE_SIZE, 'sortBy' => 'expirationDate', 'sortDirection' => 'asc']));
            $items = $response->data['domains'] ?? null;
            $totalPages = $response->data['totalPages'] ?? null;
            if (!is_array($items) || !is_int($totalPages) || $totalPages < 0) {
                throw new ApiException(ApiErrorKind::Protocol, $response->status, null, 'Domain listing lacks "domains"/"totalPages".', $response->requestId);
            }
            $pages = $totalPages;
            foreach ($items as $item) {
                $domain = is_array($item) ? $this->remoteDomain($item) : null;
                if ($domain !== null) {
                    $domains[$domain->name->ascii()] ??= $domain;
                }
            }
            if ($items === []) {
                break;
            }
        }

        return $domains;
    }

    /**
     * The registrant contact stored at OSIR, or null if OSIR has none for the domain.
     */
    public function registrant(DomainName $domain): ?ContactData
    {
        $data = $this->api->send(ApiRequest::get(ApiRequest::path(self::P_CONTACTS, $domain)))->data;
        $c = $data['registrant'] ?? null;
        if (!is_array($c)) {
            return null;
        }
        $s = static fn(string $key): ?string => is_string($c[$key] ?? null) && trim($c[$key]) !== '' ? trim($c[$key]) : null;
        [$phoneCc, $phone] = self::splitPhone($s('phone'));

        return new ContactData(
            firstName: $s('firstName') ?? $s('name'),
            lastName: $s('lastName') ?? $s('surname'),
            organization: $s('organization'),
            email: $s('email'),
            phoneCountryCode: $phoneCc,
            phone: $phone,
            street1: $s('street1'),
            street2: $s('street2'),
            city: $s('city'),
            state: $s('state'),
            postalCode: $s('postalCode'),
            country: ($country = $s('country')) !== null && preg_match('/^[A-Za-z]{2}$/', $country) === 1 ? strtoupper($country) : null,
        );
    }

    /**
     * EPP phone format "+CC.NUMBER" → ["CC", "NUMBER"], as FOSSBilling stores it. Anything else is
     * kept whole in the number field.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitPhone(?string $phone): array
    {
        if ($phone === null) {
            return [null, null];
        }
        if (preg_match('/^\+(\d{1,3})\.(\d{4,14})$/', $phone, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [null, $phone];
    }

    /** @param array<array-key, mixed> $item */
    private function remoteDomain(array $item): ?RemoteDomain
    {
        $name = $item['domain'] ?? null;
        $status = $item['status'] ?? null;
        try {
            if (!is_string($name) || !is_string($status) || $status === '') {
                throw new ValidationException('incomplete entry');
            }
            $domain = DomainName::fromString($name);
        } catch (ValidationException) {
            $this->log->warning('OSIR domain listing: an entry without a valid domain name or status was skipped.');

            return null;
        }

        $nameservers = [];
        foreach (is_array($item['nameservers'] ?? null) ? $item['nameservers'] : [] as $ns) {
            if (is_string($ns) && trim($ns) !== '') {
                $nameservers[] = strtolower(rtrim(trim($ns), '.'));
            }
        }

        return new RemoteDomain(
            name: $domain,
            status: DomainStatus::fromApi($status),
            createdAt: DomainInfoParser::timestamp($item['creationDate'] ?? null),
            expiresAt: DomainInfoParser::timestamp($item['expirationDate'] ?? null),
            nameservers: array_values(array_unique($nameservers)),
            privacy: ($item['privacy'] ?? false) === true,
        );
    }

    /**
     * A random name within the TLD's length limits, e.g. "osirprice3f9a1c0b2d.com". Random so it is
     * (practically) never registered and never premium, and so quotes are not served from a cache.
     */
    private function probeName(CatalogTld $tld): DomainName
    {
        $label = 'osirprice' . strtolower(preg_replace('/[^a-z0-9]/i', '', ($this->randomLabel)()) ?? '');
        $length = max($tld->minCharacters, min($tld->maxCharacters, strlen($label)));
        $label = str_pad(substr($label, 0, $length), $length, 'x');

        return DomainName::fromString($label . $tld->tld);
    }
}
