<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\DataProvider;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Util\Hasher;
use Swag\PlatformDemoData\Helper\DemoDateHelper;

/**
 * Gives every demo customer a membership: a growth value, the tier that growth
 * value unlocks and the point balance that the ledger of
 * {@see CustomerPointTransactionProvider} produces.
 *
 * A customer might already own a loyalty row, for example because the shop has
 * used the program before the demo data was imported. `customer_id` is unique,
 * so that existing row is reused instead of provoking a duplicate key error.
 */
#[Package('fundamentals@after-sales')]
class CustomerLoyaltyProvider extends DemoDataProvider
{
    /**
     * The growth value of every demo customer, in the order the customers are
     * defined. The value decides which tier the customer is assigned to and is
     * spread so that all four tiers are used.
     *
     * @var list<int>
     */
    private const GROWTH_VALUES = [
        120, 3400, 860, 9000, 120, 24000, 3400, 760, 15000, 350,
        5200, 90, 6800, 1900, 31000, 150, 4800, 2200, 700, 11000,
        260, 1600, 7500, 430, 45000, 340, 2600, 6100, 130, 8800,
    ];

    /**
     * Point balance of the customers that have no bookings in the demo ledger.
     * Customers with a ledger take the balance that the ledger produces.
     */
    private const POINTS_PER_GROWTH_DIVISOR = 10;

    private Connection $connection;

    private ClockInterface $clock;

    /**
     * @var array<string, string>|null existing loyalty row id by customer id
     */
    private ?array $existingRowIds = null;

    public function __construct(Connection $connection, ClockInterface $clock)
    {
        $this->connection = $connection;
        $this->clock = $clock;
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'customer_loyalty';
    }

    /**
     * The membership references the customers, so it runs after them, and the
     * tiers, which run earlier. On delete it is removed before the customers
     * and before the tiers.
     *
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => -1,
            'delete' => 6,
        ];
    }

    public static function loyaltyId(string $customerId): string
    {
        return Hasher::hash('shopwell-demo-data:customer-loyalty:' . $customerId, 'md5');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPayload(): array
    {
        $customerIds = CustomerProvider::getDemoCustomerIds();
        $ledgerBalances = CustomerPointTransactionProvider::balancesByCustomer();
        $now = $this->clock->now();

        $payload = [];

        foreach ($customerIds as $index => $customerId) {
            $growthValue = self::GROWTH_VALUES[$index % \count(self::GROWTH_VALUES)];

            $payload[] = [
                'id' => $this->resolveId($customerId),
                'customerId' => $customerId,
                'pointsBalance' => $ledgerBalances[$index] ?? \intdiv($growthValue, self::POINTS_PER_GROWTH_DIVISOR) + 30,
                'growthValue' => $growthValue,
                'membershipTierId' => LoyaltyTierProvider::tierId($this->tierIndexFor($growthValue)),
                // The membership runs for a month from the day it was started,
                // like a monthly membership program does.
                'membershipValidUntil' => $now->modify(\sprintf('+%d days', 30 - $index % 10)),
                'createdAt' => DemoDateHelper::at($this->clock, 'customer-loyalty', $index),
            ];
        }

        return $payload;
    }

    /**
     * Removing the plugin also removes the memberships, including the rows the
     * shop already had for the demo customers, so that no demo balance is left
     * behind on a deactivated shop.
     *
     * @return list<array<string, mixed>>
     */
    public function getDeletePayload(): array
    {
        $ids = CustomerProvider::getDemoCustomerIds();
        $ids = \array_map(
            fn(string $customerId): string => $this->resolveId($customerId),
            $ids,
        );

        return \array_map(
            static fn(string $id): array => ['id' => $id],
            \array_values(\array_unique($ids)),
        );
    }

    /**
     * The index of the highest tier whose growth value threshold the customer
     * has reached.
     */
    private function tierIndexFor(int $growthValue): int
    {
        $tierIndex = 0;
        for ($index = 0; $index < LoyaltyTierProvider::tierCount(); ++$index) {
            if (LoyaltyTierProvider::minGrowthValue($index) <= $growthValue) {
                $tierIndex = $index;
            }
        }

        return $tierIndex;
    }

    /**
     * The id of the loyalty row of the given customer: the row that already
     * exists in the shop, or the deterministic demo id when there is none.
     */
    private function resolveId(string $customerId): string
    {
        if ($this->existingRowIds === null) {
            $this->existingRowIds = [];
            /** @var list<array{customerId: string, id: string}> $rows */
            $rows = $this->connection->fetchAllAssociative('
                SELECT LOWER(HEX(`customer_id`)) AS `customerId`, LOWER(HEX(`id`)) AS `id`
                FROM `customer_loyalty`
            ');

            foreach ($rows as $row) {
                $this->existingRowIds[$row['customerId']] = $row['id'];
            }
        }

        return $this->existingRowIds[$customerId] ?? self::loyaltyId($customerId);
    }
}
