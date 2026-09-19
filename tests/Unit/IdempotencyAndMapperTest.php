<?php

declare(strict_types=1);

namespace Osir\FossBilling\Tests\Unit;

use Osir\FossBilling\Config\Environment;
use Osir\FossBilling\Domain\DomainName;
use Osir\FossBilling\Exception\ValidationException;
use Osir\FossBilling\Mapping\FossBillingMapper;
use Osir\FossBilling\Service\IdempotencyKeys;
use Osir\FossBilling\Service\OrderRef;
use PHPUnit\Framework\TestCase;

final class IdempotencyAndMapperTest extends TestCase
{
    public function testKeysDifferPerEnvironmentActionAndAttempt(): void
    {
        $d = DomainName::fromString('example.com');
        $o = new OrderRef('5', null);
        $keys = [
            IdempotencyKeys::register('i', Environment::Live, $o, $d, 1),
            IdempotencyKeys::register('i', Environment::Sandbox, $o, $d, 1),
            IdempotencyKeys::register('i', Environment::Live, $o, $d, 1, 2),
            IdempotencyKeys::transfer('i', Environment::Live, $o, $d),
            IdempotencyKeys::renew('i', Environment::Live, $o, $d, 1, 1820000000),
            IdempotencyKeys::renew('i', Environment::Live, $o, $d, 1, 1851536000),
        ];
        self::assertSame($keys, array_unique($keys));
    }

    public function testLongDomainKeysStayWithinOsirLimitAndStayUnique(): void
    {
        $long = DomainName::fromString(str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.com');
        $long2 = DomainName::fromString(str_repeat('a', 63) . '.' . str_repeat('b', 62) . 'c.com');
        $o = new OrderRef('12345678901234567890', null);
        $k1 = IdempotencyKeys::renew('abc123def456', Environment::Sandbox, $o, $long, 10, 1820000000, 5);
        $k2 = IdempotencyKeys::renew('abc123def456', Environment::Sandbox, $o, $long2, 10, 1820000000, 5);
        self::assertLessThanOrEqual(255, strlen($k1));
        self::assertNotSame($k1, $k2);
    }

    public function testOrderRefFromDoctrineStyleEntity(): void
    {
        $entity = new class {
            public function getId(): int
            {
                return 77;
            }

            public function getCreatedAt(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-19T10:00:00Z');
            }

            public function getExpiresAt(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2027-09-19T10:00:00Z');
            }
        };
        $ref = FossBillingMapper::orderRef($entity);
        self::assertNotNull($ref);
        self::assertSame('77', $ref->id);
        self::assertSame(1789812000, $ref->createdAt);
        self::assertSame(1821348000, $ref->expiresAt);
    }

    public function testOrderRefFromRedBeanModelReadsExpiry(): void
    {
        $bean = new \RedBeanPHP\OODBBean();
        $bean->initializeForDispense('client_order');
        $bean->setProperty('id', 5);
        $bean->setProperty('created_at', '2026-09-19 10:00:00');
        $bean->setProperty('expires_at', '2027-09-19 10:00:00');
        $order = new \Model_ClientOrder();
        $order->loadBean($bean);
        $ref = FossBillingMapper::orderRef($order);
        self::assertNotNull($ref);
        self::assertSame(strtotime('2027-09-19 10:00:00'), $ref->expiresAt);
    }

    public function testOrderRefRejectsNonNumericIds(): void
    {
        self::assertNull(FossBillingMapper::orderRef(new class {
            public string $id = '12; DROP';
        }));
        self::assertNull(FossBillingMapper::orderRef(null));
    }

    public function testYearsDefaultsOnlyWhenMissing(): void
    {
        $d = new \Registrar_Domain();
        self::assertSame(1, FossBillingMapper::years($d));
        $d->setRegistrationPeriod(3);
        self::assertSame(3, FossBillingMapper::years($d));
        $d->setRegistrationPeriod(0);
        $this->expectException(ValidationException::class);
        FossBillingMapper::years($d);
    }
}
