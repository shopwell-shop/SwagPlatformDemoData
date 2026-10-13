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
use Shopwell\Core\Defaults;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Uuid\Uuid;
use Shopwell\Core\Framework\Util\Hasher;
use Swag\PlatformDemoData\Helper\DemoDateHelper;

/**
 * Creates the wallet of every demo customer that has wallet history. The
 * balance is the result of the ledger in
 * {@see CustomerWalletTransactionProvider}.
 *
 * `(customer_id, currency_id)` is unique, so a wallet that already exists for
 * one of the demo customers is reused instead of provoking a duplicate key
 * error.
 */
#[Package('fundamentals@after-sales')]
class CustomerWalletProvider extends DemoDataProvider
{
    private Connection $connection;

    private ClockInterface $clock;

    /**
     * @var array<string, string>|null existing wallet id by customer id
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
        return 'customer_wallet';
    }

    /**
     * The wallet references the customers, so it runs after them and before the
     * ledger. On delete it is removed before the customers again.
     *
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => -1,
            'delete' => 5,
        ];
    }

    public static function walletId(string $customerId): string
    {
        return Hasher::hash('shopwell-demo-data:customer-wallet:' . $customerId, 'md5');
    }

    /**
     * The id of the demo wallet of the given customer: the row that already
     * exists in the shop, or the deterministic demo id when there is none.
     * Shared with the ledger, so that both agree on the wallet.
     */
    public function resolveWalletId(string $customerId): string
    {
        if ($this->existingRowIds === null) {
            $this->existingRowIds = [];
            /** @var list<array{customerId: string, id: string}> $rows */
            $rows = $this->connection->fetchAllAssociative('
                SELECT LOWER(HEX(`customer_id`)) AS `customerId`, LOWER(HEX(`id`)) AS `id`
                FROM `customer_wallet`
                WHERE `currency_id` = :currencyId
            ', ['currencyId' => Uuid::fromHexToBytes(Defaults::CURRENCY)]);

            foreach ($rows as $row) {
                $this->existingRowIds[$row['customerId']] = $row['id'];
            }
        }

        return $this->existingRowIds[$customerId] ?? self::walletId($customerId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPayload(): array
    {
        $customerIds = CustomerProvider::getDemoCustomerIds();
        $balances = CustomerWalletTransactionProvider::balancesByCustomer();

        $payload = [];

        foreach (CustomerWalletTransactionProvider::customersWithLedger() as $index) {
            if (!isset($customerIds[$index])) {
                continue;
            }

            $customerId = $customerIds[$index];

            $payload[] = [
                'id' => $this->resolveWalletId($customerId),
                'customerId' => $customerId,
                'currencyId' => Defaults::CURRENCY,
                'balance' => $balances[$index] ?? 0.0,
                'lockedBalance' => 0.0,
                'createdAt' => DemoDateHelper::at($this->clock, 'customer-wallet', $index),
            ];
        }

        return $payload;
    }
}
