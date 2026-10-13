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
use Swag\PlatformDemoData\Helper\DbHelper;

/**
 * Writes the point ledger of the demo customers.
 *
 * Two bookings per customer, so that the shop shows a running balance and all
 * four booking types (award, redeem, adjust, expire) are represented. The
 * resulting balance of every customer is published through
 * {@see self::balancesByCustomer()} and reused by {@see CustomerLoyaltyProvider},
 * so the balance of the membership always matches the ledger.
 */
#[Package('fundamentals@after-sales')]
class CustomerPointTransactionProvider extends DemoDataProvider
{
    /**
     * The demo ledger, ordered by time. `points` is a signed delta, exactly as
     * the platform stores it: awards are positive, redemptions and expirations
     * negative. `daysAgo` keeps every booking inside the last month; the current
     * day and the day before both carry bookings, with the bigger share on the
     * current day. The bookings of one customer are dated in the order they are
     * listed, so `balance_after` stays chronological.
     *
     * @var list<array{
     *     customer: int,
     *     type: string,
     *     points: int,
     *     order: int|null,
     *     daysAgo: int,
     *     comment: array{zh-CN: string, en-GB: string}|null
     * }>
     */
    private const LEDGER = [
        ['customer' => 0, 'type' => 'award', 'points' => 180, 'order' => 0, 'daysAgo' => 26, 'comment' => null],
        ['customer' => 0, 'type' => 'redeem', 'points' => -60, 'order' => 12, 'daysAgo' => 0, 'comment' => null],
        ['customer' => 1, 'type' => 'award', 'points' => 420, 'order' => 1, 'daysAgo' => 22, 'comment' => null],
        ['customer' => 1, 'type' => 'award', 'points' => 95, 'order' => 21, 'daysAgo' => 0, 'comment' => ['zh-CN' => '下单奖励', 'en-GB' => 'Order reward']],
        ['customer' => 2, 'type' => 'award', 'points' => 260, 'order' => 2, 'daysAgo' => 19, 'comment' => null],
        ['customer' => 2, 'type' => 'expire', 'points' => -40, 'order' => null, 'daysAgo' => 0, 'comment' => null],
        ['customer' => 3, 'type' => 'award', 'points' => 1280, 'order' => 3, 'daysAgo' => 17, 'comment' => null],
        ['customer' => 3, 'type' => 'redeem', 'points' => -300, 'order' => 16, 'daysAgo' => 1, 'comment' => null],
        ['customer' => 4, 'type' => 'award', 'points' => 640, 'order' => 4, 'daysAgo' => 15, 'comment' => null],
        ['customer' => 4, 'type' => 'adjust', 'points' => 200, 'order' => null, 'daysAgo' => 0, 'comment' => ['zh-CN' => '活动补发积分', 'en-GB' => 'Points credited for a campaign']],
        ['customer' => 5, 'type' => 'award', 'points' => 330, 'order' => 5, 'daysAgo' => 13, 'comment' => null],
        ['customer' => 5, 'type' => 'redeem', 'points' => -120, 'order' => 25, 'daysAgo' => 1, 'comment' => null],
        ['customer' => 6, 'type' => 'award', 'points' => 1580, 'order' => 6, 'daysAgo' => 11, 'comment' => null],
        ['customer' => 6, 'type' => 'award', 'points' => 260, 'order' => 18, 'daysAgo' => 1, 'comment' => ['zh-CN' => '下单奖励', 'en-GB' => 'Order reward']],
        ['customer' => 7, 'type' => 'award', 'points' => 75, 'order' => 7, 'daysAgo' => 9, 'comment' => null],
        ['customer' => 7, 'type' => 'expire', 'points' => -15, 'order' => null, 'daysAgo' => 0, 'comment' => null],
        ['customer' => 8, 'type' => 'award', 'points' => 910, 'order' => 8, 'daysAgo' => 7, 'comment' => null],
        ['customer' => 8, 'type' => 'redeem', 'points' => -260, 'order' => 29, 'daysAgo' => 1, 'comment' => null],
        ['customer' => 9, 'type' => 'award', 'points' => 1500, 'order' => 9, 'daysAgo' => 28, 'comment' => null],
        ['customer' => 9, 'type' => 'adjust', 'points' => -500, 'order' => null, 'daysAgo' => 0, 'comment' => ['zh-CN' => '客服手工扣减', 'en-GB' => 'Manually deducted by support']],
    ];

    private Connection $connection;

    private ClockInterface $clock;

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
        return 'customer_point_transaction';
    }

    /**
     * The ledger references the demo orders and the customers, so it has to run
     * after them. On delete it runs before all of them again - and before the
     * payments, which also build on the demo orders.
     *
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => -2,
            'delete' => 9,
        ];
    }

    /**
     * The balance a customer ends up with after the whole ledger has been
     * booked. Customers without a booking are not part of the result.
     *
     * @return array<int, int> balance by demo customer index
     */
    public static function balancesByCustomer(): array
    {
        $balances = [];
        foreach (self::LEDGER as $entry) {
            $balances[$entry['customer']] = ($balances[$entry['customer']] ?? 0) + $entry['points'];
        }

        return $balances;
    }

    public static function transactionId(int $index): string
    {
        return Hasher::hash('shopwell-demo-data:point-transaction:' . $index, 'md5');
    }

    /**
     * @return list<string>
     */
    public static function getDemoTransactionIds(): array
    {
        return \array_map(
            static fn(int $index): string => self::transactionId($index),
            \array_keys(self::LEDGER),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPayload(): array
    {
        $customerIds = CustomerProvider::getDemoCustomerIds();
        $orderIds = OrderProvider::getDemoOrderIds();
        $salesChannelId = $this->getStorefrontSalesChannelId();
        $systemLanguageCode = (new DbHelper($this->connection))->getSystemLanguageCode();
        $now = $this->clock->now();

        $payload = [];

        foreach (self::LEDGER as $index => $entry) {
            $createdAt = $now->modify(\sprintf('-%d days', $entry['daysAgo']))->setTime(10 + ($index % 8), ($index * 7) % 60);

            $comment = null;
            if ($entry['comment'] !== null) {
                $comment = $entry['comment'][$systemLanguageCode] ?? $entry['comment']['en-GB'];
            }

            $payload[] = [
                'id' => self::transactionId($index),
                'customerId' => $customerIds[$entry['customer'] % \count($customerIds)],
                'type' => $entry['type'],
                'points' => $entry['points'],
                'balanceAfter' => $this->runningBalanceAt($index),
                'idempotencyKey' => 'shopwell-demo-points-' . $index,
                'orderId' => $entry['order'] === null ? null : $orderIds[$entry['order'] % \count($orderIds)],
                'salesChannelId' => $salesChannelId,
                'expiresAt' => $entry['type'] === 'award' ? $createdAt->modify('+365 days') : null,
                'expiredAt' => $entry['type'] === 'expire' ? $createdAt : null,
                'comment' => $comment,
                'createdAt' => $createdAt,
            ];
        }

        return $payload;
    }

    /**
     * The balance of the customer directly after the ledger entry at the given
     * index. Only the bookings of the same customer are added up, because
     * `balance_after` is the balance of a single membership and not the sum of
     * the whole ledger.
     */
    private function runningBalanceAt(int $index): int
    {
        $customer = self::LEDGER[$index]['customer'];

        $balance = 0;
        foreach (self::LEDGER as $position => $entry) {
            if ($position > $index) {
                break;
            }

            if ($entry['customer'] !== $customer) {
                continue;
            }

            $balance += $entry['points'];
        }

        return $balance;
    }

    private function getStorefrontSalesChannelId(): string
    {
        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(`id`))
            FROM `sales_channel`
            WHERE `type_id` = :storefrontType
        ', ['storefrontType' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]);

        if (!$result) {
            throw new \RuntimeException('No storefront sales channel found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
    }
}
