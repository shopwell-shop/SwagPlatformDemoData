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
use Shopwell\Core\Framework\Util\Hasher;
use Swag\PlatformDemoData\Helper\DbHelper;

/**
 * Writes the wallet ledger of the demo customers.
 *
 * Two bookings per customer, covering all four booking types (top up, consume,
 * refund, adjust). The balance a customer ends up with is published through
 * {@see self::balancesByCustomer()} and reused by {@see CustomerWalletProvider},
 * so the wallet balance always matches its ledger.
 */
#[Package('fundamentals@after-sales')]
class CustomerWalletTransactionProvider extends DemoDataProvider
{
    /**
     * The demo ledger, ordered by time. `amount` is a signed delta in the shop
     * currency, exactly as the platform stores it: top ups and refunds are
     * positive, wallet payments negative, adjustments either way. `daysAgo`
     * keeps every booking inside the last month; the current day and the day
     * before both carry bookings, with the bigger share on the current day.
     * The bookings of one customer are dated in the order they are listed, so
     * `balance_after` stays chronological.
     *
     * @var list<array{
     *     customer: int,
     *     type: string,
     *     amount: float,
     *     order: int|null,
     *     externalReference: string|null,
     *     daysAgo: int,
     *     comment: array{zh-CN: string, en-GB: string}|null
     * }>
     */
    private const LEDGER = [
        ['customer' => 0, 'type' => 'top_up', 'amount' => 200.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0001', 'daysAgo' => 24, 'comment' => null],
        ['customer' => 0, 'type' => 'consume', 'amount' => -68.0, 'order' => 0, 'externalReference' => null, 'daysAgo' => 0, 'comment' => null],
        ['customer' => 1, 'type' => 'top_up', 'amount' => 500.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0002', 'daysAgo' => 21, 'comment' => null],
        ['customer' => 1, 'type' => 'refund', 'amount' => 50.0, 'order' => 1, 'externalReference' => null, 'daysAgo' => 0, 'comment' => ['zh-CN' => '订单部分退款退回钱包', 'en-GB' => 'Partial order refund returned to the wallet']],
        ['customer' => 2, 'type' => 'top_up', 'amount' => 1000.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0003', 'daysAgo' => 18, 'comment' => null],
        ['customer' => 2, 'type' => 'consume', 'amount' => -320.0, 'order' => 2, 'externalReference' => null, 'daysAgo' => 0, 'comment' => null],
        ['customer' => 3, 'type' => 'top_up', 'amount' => 300.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0004', 'daysAgo' => 16, 'comment' => null],
        ['customer' => 3, 'type' => 'consume', 'amount' => -88.0, 'order' => 3, 'externalReference' => null, 'daysAgo' => 1, 'comment' => null],
        ['customer' => 4, 'type' => 'top_up', 'amount' => 2000.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0005', 'daysAgo' => 14, 'comment' => null],
        ['customer' => 4, 'type' => 'adjust', 'amount' => -150.0, 'order' => null, 'externalReference' => null, 'daysAgo' => 0, 'comment' => ['zh-CN' => '客服手工调账', 'en-GB' => 'Manual correction by support']],
        ['customer' => 5, 'type' => 'top_up', 'amount' => 150.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0006', 'daysAgo' => 12, 'comment' => null],
        ['customer' => 5, 'type' => 'consume', 'amount' => -45.0, 'order' => 5, 'externalReference' => null, 'daysAgo' => 1, 'comment' => null],
        ['customer' => 6, 'type' => 'top_up', 'amount' => 800.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0007', 'daysAgo' => 10, 'comment' => null],
        ['customer' => 6, 'type' => 'consume', 'amount' => -260.0, 'order' => 6, 'externalReference' => null, 'daysAgo' => 1, 'comment' => null],
        ['customer' => 7, 'type' => 'top_up', 'amount' => 600.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0008', 'daysAgo' => 8, 'comment' => null],
        ['customer' => 7, 'type' => 'consume', 'amount' => -590.0, 'order' => 7, 'externalReference' => null, 'daysAgo' => 1, 'comment' => null],
        ['customer' => 8, 'type' => 'top_up', 'amount' => 1200.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0009', 'daysAgo' => 6, 'comment' => null],
        ['customer' => 8, 'type' => 'refund', 'amount' => 199.0, 'order' => 8, 'externalReference' => null, 'daysAgo' => 0, 'comment' => ['zh-CN' => '退货退款退回钱包', 'en-GB' => 'Return refund returned to the wallet']],
        ['customer' => 9, 'type' => 'top_up', 'amount' => 5000.0, 'order' => null, 'externalReference' => 'DEMO-WALLET-TOPUP-0010', 'daysAgo' => 27, 'comment' => null],
        ['customer' => 9, 'type' => 'consume', 'amount' => -1250.0, 'order' => 9, 'externalReference' => null, 'daysAgo' => 0, 'comment' => null],
    ];

    private Connection $connection;

    private ClockInterface $clock;

    private CustomerWalletProvider $walletProvider;

    public function __construct(Connection $connection, ClockInterface $clock, CustomerWalletProvider $walletProvider)
    {
        $this->connection = $connection;
        $this->clock = $clock;
        $this->walletProvider = $walletProvider;
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'customer_wallet_transaction';
    }

    /**
     * The ledger references both the wallets and the demo orders, so it runs
     * after them. On delete it is removed before the wallets, the orders and
     * the payments again.
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
     * @return array<int, float> balance by demo customer index
     */
    public static function balancesByCustomer(): array
    {
        $balances = [];
        foreach (self::LEDGER as $entry) {
            $balances[$entry['customer']] = \round(($balances[$entry['customer']] ?? 0.0) + $entry['amount'], 2);
        }

        return $balances;
    }

    /**
     * @return list<int> the demo customer indexes that own a wallet
     */
    public static function customersWithLedger(): array
    {
        $customers = [];
        foreach (self::LEDGER as $entry) {
            $customers[$entry['customer']] = true;
        }

        return \array_keys($customers);
    }

    public static function transactionId(int $index): string
    {
        return Hasher::hash('shopwell-demo-data:wallet-transaction:' . $index, 'md5');
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
        $systemLanguageCode = (new DbHelper($this->connection))->getSystemLanguageCode();
        $now = $this->clock->now();

        $payload = [];

        foreach (self::LEDGER as $index => $entry) {
            $createdAt = $now->modify(\sprintf('-%d days', $entry['daysAgo']))->setTime(9 + ($index % 9), ($index * 11) % 60);

            $comment = null;
            if ($entry['comment'] !== null) {
                $comment = $entry['comment'][$systemLanguageCode] ?? $entry['comment']['en-GB'];
            }

            $payload[] = [
                'id' => self::transactionId($index),
                'customerWalletId' => $this->walletProvider->resolveWalletId($customerIds[$entry['customer'] % \count($customerIds)]),
                'type' => $entry['type'],
                'amount' => $entry['amount'],
                'balanceAfter' => $this->runningBalanceAt($index),
                'currencyId' => Defaults::CURRENCY,
                'orderId' => $entry['order'] === null ? null : $orderIds[$entry['order'] % \count($orderIds)],
                'externalReference' => $entry['externalReference'],
                'comment' => $comment,
                'createdAt' => $createdAt,
            ];
        }

        return $payload;
    }

    /**
     * The balance of the wallet directly after the ledger entry at the given
     * index. Only the bookings of the same wallet are added up, because
     * `balance_after` is the balance of a single wallet and not the sum of the
     * whole ledger.
     */
    private function runningBalanceAt(int $index): float
    {
        $customer = self::LEDGER[$index]['customer'];

        $balance = 0.0;
        foreach (self::LEDGER as $position => $entry) {
            if ($position > $index) {
                break;
            }

            if ($entry['customer'] !== $customer) {
                continue;
            }

            $balance = \round($balance + $entry['amount'], 2);
        }

        return $balance;
    }
}
