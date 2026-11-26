<?php

declare(strict_types=1);

namespace Osir\FossBilling\Import;

use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\RuleException;
use Osir\FossBilling\Support\SafeLogger;

/**
 * OSIR's price list in the shape ClientFence's TLD price page asks an adapter for
 * ({@see \Registrar_TldPrices}): the cost of one year per action, in the registrar's currency.
 *
 * The platform applies the markup, the rounding and the currency conversion itself, so what is
 * returned here is pure cost — the same figures the plugin's own import page shows under "cost".
 *
 * TLDs are priced a slice at a time because each one needs a live quote from OSIR: the caller
 * passes {@see slice()} what is left until nothing is, which is also how it survives a page that
 * would otherwise time out.
 */
final class TldPriceList
{
    /** TLDs per call. Each costs one quote request, plus one catalog request for the whole slice. */
    public const int SLICE = 10;

    /** @var array<string, CatalogTld>|null memo for this request only */
    private ?array $catalog = null;

    public function __construct(
        private readonly ImportService $import,
        private readonly SafeLogger $log,
    ) {}

    /**
     * One slice of the price list.
     *
     * @param list<string> $tlds the TLDs still to price, with or without the leading dot; [] = all
     *
     * @return array{currency: string, prices: array<string, array{register: float|null, renew: float|null, transfer: float|null}>, remaining: list<string>}
     */
    public function slice(array $tlds): array
    {
        $catalog = $this->catalog ??= $this->import->catalog();
        $wanted = $tlds === [] ? array_keys($catalog) : self::known($tlds, $catalog, $this->log);

        $now = array_slice($wanted, 0, self::SLICE);
        $prices = [];
        $estimated = [];
        foreach ($now as $tld) {
            try {
                $cost = $this->import->cost($catalog[$tld]);
            } catch (RuleException $e) {
                // OSIR lists the TLD but will not quote a standard name on it (a registry change,
                // usually): left out rather than reported as free or as no longer sold, so that
                // the price this TLD already sells at is never overwritten with a guess.
                $this->log->warning(sprintf('OSIR has no usable price for %s right now; it is left out of the price list. %s', $tld, $e->getMessage()));

                continue;
            }
            if ($cost->estimated) {
                $estimated[] = $tld;
            }
            $prices[$tld] = [
                'register' => $cost->registerCents / 100,
                'renew' => $cost->renewCents / 100,
                'transfer' => $cost->transferCents / 100,
            ];
        }
        if ($estimated !== []) {
            // OSIR quotes registrations only, so renewal and transfer are the catalog price plus
            // the same fees. The platform's price page has nowhere to show that, so the TLDs whose
            // figures were worked out rather than quoted are named here instead.
            $this->log->warning(sprintf(
                'OSIR did not quote every price for %s: the renewal and transfer costs are worked out from its price list and may be low. Check them before selling at a thin margin.',
                implode(', ', $estimated),
            ));
        }

        return [
            // OSIR invoices resellers in USD; the platform converts with its own rate.
            'currency' => 'USD',
            'prices' => $prices,
            'remaining' => array_values(array_slice($wanted, count($now))),
        ];
    }

    /**
     * The requested TLDs that OSIR actually sells, normalised to the catalog's spelling. Anything
     * else is dropped — left in, it would be asked for again on every call and never be priced —
     * and named in the log, because a TLD the shop sells that OSIR no longer lists would otherwise
     * just stop being re-priced with nobody told.
     *
     * @param list<string>            $tlds
     * @param array<string, CatalogTld> $catalog
     *
     * @return list<string>
     */
    private static function known(array $tlds, array $catalog, SafeLogger $log): array
    {
        $known = [];
        $unknown = [];
        foreach ($tlds as $tld) {
            $name = self::normalise($tld);
            if ($name !== null && isset($catalog[$name])) {
                if (!in_array($name, $known, true)) {
                    $known[] = $name;
                }
            } elseif (!in_array($tld, $unknown, true)) {
                $unknown[] = $tld;
            }
        }
        if ($unknown !== []) {
            $log->warning(sprintf('OSIR does not list %s; they are left out of the price list.', implode(', ', array_map(static fn(string $t): string => mb_substr($t, 0, 40), $unknown))));
        }

        return $known;
    }

    /** ".CO.UK" and "co.uk" are the same TLD; a name that cannot be one at all is dropped. */
    private static function normalise(string $tld): ?string
    {
        $name = trim(strtolower(trim($tld)), '.');

        try {
            // Keyed exactly as the catalog keys it ({@see CatalogTld::fromApi()}), which also
            // turns an IDN TLD into the punycode the catalog uses.
            return '.' . DomainName::fromString('a.' . $name)->tld();
        } catch (\Throwable) {
            return null;
        }
    }
}
