<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\DataProvider;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Shopwell\Core\Defaults;
use Shopwell\Core\Framework\Context;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Uuid\Uuid;
use Shopwell\Core\Framework\Util\Hasher;
use Shopwell\Core\System\Payment\Order\PaymentOrderStates;
use Swag\PlatformDemoData\Helper\DbHelper;
use Swag\PlatformDemoData\Helper\DemoDateHelper;

/**
 * Creates one payment order per demo order, so that the payment overview of the
 * shop is populated.
 *
 * The demo orders are paid, some of them were refunded afterwards: the first
 * ones are plain payments, the ones in the middle were refunded completely and
 * the last ones only partly - once in a single refund and once in two, so that
 * a payment with several refunds exists as well. The refunds themselves are
 * written by {@see PaymentRefundProvider}, which shares the plan built here and
 * therefore always agrees with the refunded amount on the payment.
 */
#[Package('fundamentals@after-sales')]
class PaymentOrderProvider extends DemoDataProvider
{
    private const PAYMENT_NUMBER_PREFIX = 'SW-DEMO-PAY-';

    private const ORDER_NUMBER_PREFIX = 'SW-DEMO-ORD-';

    /**
     * Payments that were never refunded.
     */
    private const PAID_COUNT = 5;

    /**
     * Payments that were refunded completely.
     */
    private const REFUNDED_COUNT = 10;

    /**
     * Payments that were refunded partly with a single refund.
     */
    private const PARTIAL_COUNT = 10;

    /**
     * Percentage of the amount that the single partial refund returns.
     */
    private const PARTIAL_PERCENTAGE = 30;

    /**
     * Percentages of the two refunds of the last payments.
     */
    private const SPLIT_REFUND_PERCENTAGES = [20, 15];

    /**
     * The tables that reference a payment order. A payment can only be removed
     * once the rows in them are gone.
     */
    private const DEPENDENT_PAYMENT_TABLES = [
        'payment_refund',
        'payment_notification',
        'payment_transaction',
        'payment_webhook_delivery',
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
        return 'payment_order';
    }

    /**
     * The payment order is built from the demo orders, so it runs after them.
     * On delete it is removed before the orders again, but after the refunds
     * that hang off it.
     *
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => -2,
            'delete' => 7,
        ];
    }

    public static function paymentOrderId(int $index): string
    {
        return Hasher::hash('shopwell-demo-data:payment-order:' . $index, 'md5');
    }

    public static function paymentNumber(int $index): string
    {
        return self::PAYMENT_NUMBER_PREFIX . \str_pad((string) ($index + 1), 4, '0', \STR_PAD_LEFT);
    }

    /**
     * The settlement of every demo order: how much was paid for it, which state
     * the payment ended up in and which refunds belong to it. Shared with
     * {@see PaymentRefundProvider}, so both providers write the same story.
     *
     * @return list<array{
     *     index: int,
     *     orderNumber: string,
     *     amount: int,
     *     state: string,
     *     createdAt: \DateTimeImmutable,
     *     paidAt: \DateTimeImmutable,
     *     refunds: list<int>
     * }>
     */
    public function getSettlementPlan(): array
    {
        /** @var list<array{orderNumber: string, amountTotal: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('
            SELECT `order_number` AS `orderNumber`, `amount_total` AS `amountTotal`
            FROM `order`
            WHERE `order_number` LIKE :prefix
            ORDER BY `order_number`
        ', ['prefix' => self::ORDER_NUMBER_PREFIX . '%']);

        if ($rows === []) {
            throw new \RuntimeException('No demo orders found, please make sure that the demo orders are imported first.');
        }

        $plan = [];

        foreach ($rows as $index => $row) {
            // Payment amounts are integers in the smallest unit of the currency.
            $amount = (int) \round((float) $row['amountTotal'] * 100);
            $createdAt = DemoDateHelper::at($this->clock, 'order', $index);

            [$state, $refunds] = $this->settlementOf($index, $amount);

            $plan[] = [
                'index' => $index,
                'orderNumber' => $row['orderNumber'],
                'amount' => $amount,
                'state' => $state,
                'createdAt' => $createdAt,
                'paidAt' => DemoDateHelper::minutesAfter($this->clock, $createdAt, 3 + $index % 5),
                'refunds' => $refunds,
            ];
        }

        return $plan;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPayload(): array
    {
        $gateway = $this->resolveGateway();
        $systemLanguageCode = (new DbHelper($this->connection))->getSystemLanguageCode();
        $stateIds = $this->getStateIds([PaymentOrderStates::STATE_PAID, PaymentOrderStates::STATE_REFUNDED_PARTIALLY, PaymentOrderStates::STATE_REFUNDED]);

        $subject = [
            'zh-CN' => '演示订单 %s 支付',
            'en-GB' => 'Payment for demo order %s',
        ][$systemLanguageCode] ?? 'Payment for demo order %s';

        $body = [
            'zh-CN' => '本记录由演示数据插件生成，用于演示支付订单列表与退款流程。',
            'en-GB' => 'This record was created by the demo data plugin to demonstrate the payment order list and the refund flow.',
        ][$systemLanguageCode] ?? 'This record was created by the demo data plugin.';

        $payload = [];

        foreach ($this->getSettlementPlan() as $settlement) {
            $payload[] = [
                'id' => self::paymentOrderId($settlement['index']),
                'paymentNo' => self::paymentNumber($settlement['index']),
                'outTradeNo' => $settlement['orderNumber'],
                'paymentAppId' => $gateway['appId'],
                'providerTechnicalName' => $gateway['provider'],
                'channelTechnicalName' => $gateway['channel'],
                'amount' => $settlement['amount'],
                'paidAmount' => $settlement['amount'],
                'refundedAmount' => \array_sum($settlement['refunds']),
                'currency' => $this->getCurrencyIsoCode(),
                'subject' => \sprintf($subject, $settlement['orderNumber']),
                'body' => $body,
                'stateId' => $stateIds[$settlement['state']],
                'outPaymentNo' => 'DEMO' . \str_pad((string) ($settlement['index'] + 1), 12, '0', \STR_PAD_LEFT),
                'idempotencyKey' => 'shopwell-demo-payment-' . $settlement['index'],
                'clientIp' => '127.0.0.1',
                'device' => 'demo',
                'expireAt' => DemoDateHelper::minutesAfter($this->clock, $settlement['createdAt'], 30),
                'paidAt' => $settlement['paidAt'],
                'createdAt' => $settlement['createdAt'],
            ];
        }

        return $payload;
    }

    /**
     * The ISO code of the default currency, shared with {@see PaymentRefundProvider}
     * so that a refund is always written in the same currency as the payment.
     */
    public function getCurrencyIsoCode(): string
    {
        $isoCode = $this->connection->fetchOne('
            SELECT `iso_code`
            FROM `currency`
            WHERE `id` = :currencyId
        ', ['currencyId' => Uuid::fromHexToBytes(Defaults::CURRENCY)]);

        if (!$isoCode) {
            throw new \RuntimeException('The default currency could not be found.');
        }

        return (string) $isoCode;
    }

    /**
     * Removing the payments must not depend on the demo orders: an operator may
     * have deleted those first, and a payment that could no longer be removed
     * would survive a deactivation. The ids are therefore read from the payment
     * table itself.
     *
     * @return list<array<string, mixed>>
     */
    public function getDeletePayload(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('
            SELECT LOWER(HEX(`id`))
            FROM `payment_order`
            WHERE `payment_no` LIKE :prefix
        ', ['prefix' => self::PAYMENT_NUMBER_PREFIX . '%']);

        return \array_map(
            static fn(string $id): array => ['id' => $id],
            $ids,
        );
    }

    /**
     * Removes what the DAL left behind.
     *
     * The platform protects a payment order that carries history: the DAL
     * refuses to remove it, the delete sequence swallows that error and the
     * payment would silently survive a deactivation. The rows are therefore
     * removed from the tables directly, children first, so that a deactivation
     * never leaves a demo payment behind.
     */
    public function finalizeDelete(Context $context): void
    {
        $parameters = ['prefix' => self::PAYMENT_NUMBER_PREFIX . '%'];
        $orderIds = 'SELECT `id` FROM `payment_order` WHERE `payment_no` LIKE :prefix';

        foreach (self::DEPENDENT_PAYMENT_TABLES as $table) {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM `%s` WHERE `payment_order_id` IN (%s)', $table, $orderIds),
                $parameters,
            );
        }

        $this->connection->executeStatement(
            'DELETE FROM `payment_order` WHERE `payment_no` LIKE :prefix',
            $parameters,
        );
    }

    /**
     * How a payment of the given order ends up: the state it reaches and the
     * refunds that were booked against it.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function settlementOf(int $index, int $amount): array
    {
        if ($index < self::PAID_COUNT) {
            return [PaymentOrderStates::STATE_PAID, []];
        }

        if ($index < self::PAID_COUNT + self::REFUNDED_COUNT) {
            return [PaymentOrderStates::STATE_REFUNDED, [$amount]];
        }

        if ($index < self::PAID_COUNT + self::REFUNDED_COUNT + self::PARTIAL_COUNT) {
            return [PaymentOrderStates::STATE_REFUNDED_PARTIALLY, [\intdiv($amount * self::PARTIAL_PERCENTAGE, 100)]];
        }

        $refunds = [];
        foreach (self::SPLIT_REFUND_PERCENTAGES as $percentage) {
            $refunds[] = \intdiv($amount * $percentage, 100);
        }

        return [PaymentOrderStates::STATE_REFUNDED_PARTIALLY, $refunds];
    }

    /**
     * The app the demo payments are made with, together with a provider and a
     * channel that the app is enabled for and that the platform supports.
     *
     * @return array{appId: string, provider: string, channel: string}
     */
    private function resolveGateway(): array
    {
        /** @var array{appId: string, provider: string, channel: string}|false $row */
        $row = $this->connection->fetchAssociative('
            SELECT LOWER(HEX(`app`.`id`)) AS `appId`,
                   `app_provider`.`provider_technical_name` AS `provider`,
                   `app_channel`.`channel_technical_name` AS `channel`
            FROM `payment_app` `app`
            INNER JOIN `payment_app_provider` `app_provider`
                ON `app_provider`.`payment_app_id` = `app`.`id` AND `app_provider`.`enabled` = 1
            INNER JOIN `payment_app_channel` `app_channel`
                ON `app_channel`.`payment_app_id` = `app`.`id` AND `app_channel`.`enabled` = 1
            INNER JOIN `payment_channel_config` `channel_config`
                ON `channel_config`.`channel_technical_name` = `app_channel`.`channel_technical_name`
               AND `channel_config`.`provider_technical_name` = `app_provider`.`provider_technical_name`
               AND `channel_config`.`enabled` = 1
            WHERE `app`.`active` = 1
            ORDER BY `app`.`created_at` ASC, `channel_config`.`channel_technical_name` ASC
            LIMIT 1
        ');

        if ($row === false) {
            throw new \RuntimeException('No active payment app with an enabled provider and channel found, please make sure that basic data is available by running the migrations.');
        }

        return $row;
    }

    /**
     * @param list<string> $states
     *
     * @return array<string, string> state id by state technical name
     */
    private function getStateIds(array $states): array
    {
        /** @var list<array{state: string, id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('
            SELECT LOWER(HEX(`state_machine_state`.`id`)) AS `id`,
                   `state_machine_state`.`technical_name` AS `state`
            FROM `state_machine_state`
            INNER JOIN `state_machine` ON `state_machine`.`id` = `state_machine_state`.`state_machine_id`
            WHERE `state_machine`.`technical_name` = :machine
              AND `state_machine_state`.`technical_name` IN (:states)
        ', [
            'machine' => PaymentOrderStates::STATE_MACHINE,
            'states' => $states,
        ], [
            'states' => ArrayParameterType::STRING,
        ]);

        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['state']] = $row['id'];
        }

        foreach ($states as $state) {
            if (!isset($ids[$state])) {
                throw new \RuntimeException(\sprintf('State "%s" of state machine "%s" not found.', $state, PaymentOrderStates::STATE_MACHINE));
            }
        }

        return $ids;
    }
}
