<?php

declare(strict_types=1);

namespace Osir\FossBilling\Domain;

use Osir\FossBilling\Exception\ValidationException;

/**
 * An ordered, de-duplicated, validated list of nameserver host names (ASCII form).
 */
final class Nameservers
{
    public const int MIN = 2;
    public const int MAX = 13;

    /** @param list<string> $hosts */
    private function __construct(private readonly array $hosts) {}

    /**
     * Empty entries are ignored (FOSSBilling always passes ns1..ns4, often with blanks).
     *
     * @param iterable<mixed> $values
     *
     * @throws ValidationException
     */
    public static function fromList(iterable $values): self
    {
        $hosts = [];
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                throw new ValidationException('Nameserver value is invalid.');
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if (str_ends_with($value, '.')) {
                $value = substr($value, 0, -1);
            }

            $ascii = DomainName::toAscii(mb_strtolower($value, 'UTF-8'));
            DomainName::assertValidHostname($ascii, 2);

            if (!in_array($ascii, $hosts, true)) {
                $hosts[] = $ascii;
            }
        }

        if (count($hosts) < self::MIN) {
            throw new ValidationException('At least :min different nameservers are required.', [':min' => (string) self::MIN]);
        }
        if (count($hosts) > self::MAX) {
            throw new ValidationException('At most :max nameservers are allowed.', [':max' => (string) self::MAX]);
        }

        return new self($hosts);
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->hosts;
    }
}
