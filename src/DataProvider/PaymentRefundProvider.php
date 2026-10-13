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
use Shopwell\Core\System\Payment\Order\Refund\PaymentRefundStates;
use Swag\PlatformDemoData\Helper\DbHelper;
use Swag\PlatformDemoData\Helper\DemoDateHelper;

/**
 * Writes the refunds that belong to the demo payments.
 *
 * The refunds are taken from the settlement plan of {@see PaymentOrderProvider},
 * so the amount of a refund and the `refunded_amount` of the payment it belongs
 * to can never drift apart. Every refund ends in the `finished` state, which is
 * what a successfully returned payment looks like.
 */
#[Package('fundamentals@after-sales')]
class PaymentRefundProvider extends DemoDataProvider
{
    private const REFUND_NUMBER_PREFIX = 'SW-DEMO-REF-';

    private Connection $connection;

    private ClockInterface $clock;

    private PaymentOrderProvider $paymentOrderProvider;

    public function __construct(Connection $connection, ClockInterface $clock, PaymentOrderProvider $paymentOrderProvider)
    {
        $this->connection = $connection;
        $this->clock = $clock;
        $this->paymentOrderProvider = $paymentOrderProvider;
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'payment_refund';
    }

    /**
     * A refund belongs to its payment, so it runs after it. On delete it is
     * removed before the payment and before the demo orders the payment was
     * written for.
     *
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => -3,
            'delete' => 8,
        ];
    }

    public static function refundId(int $index): string
    {
        return Hasher::hash('shopwell-demo-data:payment-refund:' . $index, 'md5');
    }

    /**
     * The number of refunds the demo data ships, so that tests and the delete
     * sequence can rely on it.
     */
    public function countRefunds(): int
    {
        $count = 0;
        foreach ($this->paymentOrderProvider->getSettlementPlan() as $settlement) {
            $count += \count($settlement['refunds']);
        }

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPayload(): array
    {
        $currency = $this->paymentOrderProvider->getCurrencyIsoCode();
        $stateId = $this->getFinishedStateId();
        $systemLanguageCode = (new DbHelper($this->connection))->getSystemLanguageCode();

        $reason = [
            'zh-CN' => '演示退款：买家申请退货并已收到商品',
            'en-GB' => 'Demo refund: the buyer returned the goods and they arrived',
        ][$systemLanguageCode] ?? 'Demo refund: the buyer returned the goods and they arrived';

        $payload = [];
        $refundIndex = 0;

        foreach ($this->paymentOrderProvider->getSettlementPlan() as $settlement) {
            foreach ($settlement['refunds'] as $position => $amount) {
                $refundedAt = DemoDateHelper::minutesAfter(
                    $this->clock,
                    $settlement['paidAt'],
                    90 + $refundIndex * 15,
                );

                $payload[] = [
                    'id' => self::refundId($refundIndex),
                    'refundNo' => self::REFUND_NUMBER_PREFIX . \str_pad((string) ($refundIndex + 1), 4, '0', \STR_PAD_LEFT),
                    'paymentOrderId' => PaymentOrderProvider::paymentOrderId($settlement['index']),
                    'outRefundNo' => $settlement['orderNumber'] . '-R' . ($position + 1),
                    'outRefundId' => 'DEMOREF' . \str_pad((string) ($refundIndex + 1), 12, '0', \STR_PAD_LEFT),
                    'amount' => $amount,
                    'currency' => $currency,
                    'reason' => $reason,
                    'stateId' => $stateId,
                    'refundedAt' => $refundedAt,
                    'createdAt' => $refundedAt,
                ];

                ++$refundIndex;
            }
        }

        return $payload;
    }

    /**
     * The refunds are removed from the refund table itself - like the payments
     * of {@see PaymentOrderProvider} - so that a deactivation keeps working
     * after the demo orders have already been deleted.
     *
     * @return list<array<string, mixed>>
     */
    public function getDeletePayload(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('
            SELECT LOWER(HEX(`id`))
            FROM `payment_refund`
            WHERE `refund_no` LIKE :prefix
        ', ['prefix' => self::REFUND_NUMBER_PREFIX . '%']);

        return \array_map(
            static fn(string $id): array => ['id' => $id],
            $ids,
        );
    }

    private function getFinishedStateId(): string
    {
        $stateId = $this->connection->fetchOne('
            SELECT LOWER(HEX(`state_machine_state`.`id`))
            FROM `state_machine_state`
            INNER JOIN `state_machine` ON `state_machine`.`id` = `state_machine_state`.`state_machine_id`
            WHERE `state_machine`.`technical_name` = :machine
              AND `state_machine_state`.`technical_name` = :state
        ', [
            'machine' => PaymentRefundStates::STATE_MACHINE,
            'state' => PaymentRefundStates::STATE_FINISHED,
        ]);

        if (!$stateId) {
            throw new \RuntimeException(\sprintf('State "%s" of state machine "%s" not found.', PaymentRefundStates::STATE_FINISHED, PaymentRefundStates::STATE_MACHINE));
        }

        return (string) $stateId;
    }
}
