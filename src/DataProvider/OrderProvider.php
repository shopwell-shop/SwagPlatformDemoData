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
use Shopwell\Core\Checkout\Order\OrderStates;
use Shopwell\Core\Defaults;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Uuid\Uuid;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Shopwell\Core\Framework\Util\Hasher;

/**
 * Creates demo orders for the demo customers and products, including line
 * items, a delivery and a transaction. The order totals are written as a
 * calculated price; the database derives the amount columns from it.
 */
#[Package('fundamentals@after-sales')]
class OrderProvider extends DemoDataProvider
{
    public const SHIPPING_COSTS = 8.0;

    private const ORDER_NUMBER_PREFIX = 'SW-DEMO-ORD-';

    private const ORDER_COUNT = 30;

    private Connection $connection;

    private ClockInterface $clock;

    public function __construct(Connection $connection, ?ClockInterface $clock = null)
    {
        $this->connection = $connection;
        $this->clock = $clock ?? new NativeClock();
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'order';
    }

    /**
     * Orders are written last: they reference customers, products, shipping
     * and payment methods.
     *
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => -1,
            // Orders have to be deleted before every entity they reference.
            'delete' => 4,
        ];
    }

    /**
     * @return list<string> ids of all demo orders, in the order they are defined
     */
    public static function getDemoOrderIds(): array
    {
        return \array_map(
            static fn(int $index): string => self::orderId($index),
            \range(0, self::ORDER_COUNT - 1),
        );
    }

    public static function orderId(int $index): string
    {
        return Hasher::hash('shopwell-demo-data:order:' . $index, 'md5');
    }

    public function getPayload(): array
    {
        $customerIds = CustomerProvider::getDemoCustomerIds();
        // Orders reference the sellable variants, never a configurable parent.
        $products = ProductProvider::getDemoPurchasableProducts();
        $salesChannelId = $this->getStorefrontSalesChannelId();
        $paymentMethodId = $this->getPaymentMethodId();
        $shippingMethodId = $this->getShippingMethodId();
        $countryId = $this->getCountryIdByIsoCode('CN');
        $taxRate = TaxProvider::TAX_RATE;
        $stateIds = $this->getStateIds();
        $existingOrderIds = $this->getExistingOrderIds();

        $payload = [];
        for ($index = 0; $index < self::ORDER_COUNT; ++$index) {
            $orderId = self::orderId($index);
            // A re-import has to keep the states of orders that already exist.
            $isUpdate = isset($existingOrderIds[$orderId]);
            $customer = CustomerProvider::getDemoCustomer($customerIds[$index % \count($customerIds)]);

            // one to three items per order, deterministic and distinct
            $itemCount = 1 + ($index % 3);
            $positions = [];
            for ($position = 0; $position < $itemCount; ++$position) {
                $product = $products[($index + $position * 11) % \count($products)];
                $positions[] = [
                    'product' => $product,
                    'quantity' => 1 + (($index + $position) % 2),
                ];
            }

            $shippingFree = !\in_array(false, \array_map(
                static fn(array $position): bool => (bool) $position['product']['shippingFree'],
                $positions,
            ), true);

            $items = [];
            $positionPrice = 0.0;
            $positionNet = 0.0;
            foreach ($positions as $position => $entry) {
                $item = $this->buildLineItem($orderId, $position, $entry['product'], $entry['quantity'], $taxRate);
                $positionPrice += (float) $item['price']['totalPrice'];
                $positionNet += \round((float) $item['price']['totalPrice'] / (1 + $taxRate / 100), 2);
                $items[] = $item;
            }

            $shippingCosts = $this->calculatedPrice($shippingFree ? 0.0 : self::SHIPPING_COSTS, 1, $taxRate);
            $shippingTotal = (float) $shippingCosts['totalPrice'];
            $totalPrice = \round($positionPrice + $shippingTotal, 2);
            $totalNet = \round($positionNet + ($shippingTotal / (1 + $taxRate / 100)), 2);

            $orderState = $stateIds[OrderStates::STATE_MACHINE][['open', 'open', 'in_progress', 'completed'][$index % 4]];
            $deliveryState = $stateIds['order_delivery.state'][['open', 'open', 'shipped', 'cancelled'][$index % 4]];
            $transactionState = $stateIds['order_transaction.state'][['open', 'open', 'paid', 'cancelled'][$index % 4]];

            /** @var array<string, mixed> $address */
            $address = $customer['address'];
            $orderAddressId = Hasher::hash($orderId . ':address:1', 'md5');

            $order = [
                'id' => $orderId,
                'orderNumber' => self::ORDER_NUMBER_PREFIX . \str_pad((string) ($index + 1), 4, '0', \STR_PAD_LEFT),
                'salesChannelId' => $salesChannelId,
                'languageId' => Defaults::LANGUAGE_SYSTEM,
                'currencyId' => Defaults::CURRENCY,
                'currencyFactor' => 1.0,
                'orderDateTime' => $this->clock->now()->modify('-' . ($index * 2) . ' days'),
                'stateId' => $orderState,
                'billingAddressId' => $orderAddressId,
                'itemRounding' => self::rounding(),
                'totalRounding' => self::rounding(),
                'price' => [
                    'netPrice' => $totalNet,
                    'totalPrice' => $totalPrice,
                    'positionPrice' => $positionPrice,
                    'rawTotal' => $totalPrice,
                    'taxStatus' => 'gross',
                    'calculatedTaxes' => [[
                        'tax' => \round($totalPrice - $totalNet, 2),
                        'taxRate' => $taxRate,
                        'price' => $totalPrice,
                    ]],
                    'taxRules' => [['taxRate' => $taxRate, 'percentage' => 100.0]],
                ],
                'shippingCosts' => $shippingCosts,
                'orderCustomer' => [
                    'id' => Hasher::hash($orderId . ':order-customer', 'md5'),
                    'email' => $customer['email'],
                    'name' => $customer['name'],
                    'customerId' => $customerIds[$index % \count($customerIds)],
                ],
                'addresses' => [[
                    'id' => $orderAddressId,
                    'countryId' => $countryId,
                    'name' => $customer['name'],
                    'street' => $address['street'],
                    'additionalAddressLine1' => $address['additionalAddressLine1'],
                    'zipcode' => $address['zipcode'],
                    'city' => $address['city'],
                    'phoneNumber' => $address['phoneNumber'],
                ]],
                'lineItems' => $items,
                'deliveries' => [[
                    'id' => Hasher::hash($orderId . ':delivery:1', 'md5'),
                    'shippingOrderAddressId' => $orderAddressId,
                    'shippingMethodId' => $shippingMethodId,
                    'stateId' => $deliveryState,
                    'shippingCosts' => $shippingCosts,
                    'shippingDateEarliest' => $this->clock->now()->modify('+' . (2 + $index % 3) . ' days'),
                    'shippingDateLatest' => $this->clock->now()->modify('+' . (5 + $index % 4) . ' days'),
                    'trackingCodes' => [],
                ]],
                'transactions' => [[
                    'id' => Hasher::hash($orderId . ':transaction:1', 'md5'),
                    'paymentMethodId' => $paymentMethodId,
                    'amount' => $this->calculatedPrice($totalPrice, 1, $taxRate),
                    'stateId' => $transactionState,
                ]],
            ];

            if ($isUpdate) {
                // The write API rejects a state change for an existing entity in
                // the crud scope, so a re-import keeps the current order states.
                unset($order['stateId'], $order['deliveries'][0]['stateId'], $order['transactions'][0]['stateId']);
            }

            $payload[] = $order;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    private function buildLineItem(string $orderId, int $position, array $product, int $quantity, float $taxRate): array
    {
        $unitPrice = (float) $product['grossPrice'];

        return [
            'id' => Hasher::hash($orderId . ':line-item:' . $position, 'md5'),
            'identifier' => $product['productNumber'],
            'referencedId' => $product['id'],
            'productId' => $product['id'],
            'type' => 'product',
            'quantity' => $quantity,
            'label' => $product['name']['zh-CN'] ?? $product['name']['en-GB'],
            'position' => $position + 1,
            'good' => true,
            'removable' => true,
            'stackable' => true,
            'payload' => ['productNumber' => $product['productNumber']],
            'price' => $this->calculatedPrice($unitPrice, $quantity, $taxRate),
            'children' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function calculatedPrice(float $unitPrice, int $quantity, float $taxRate): array
    {
        $totalPrice = \round($unitPrice * $quantity, 2);
        $netPrice = \round($totalPrice / (1 + $taxRate / 100), 2);

        return [
            'unitPrice' => $unitPrice,
            'quantity' => $quantity,
            'totalPrice' => $totalPrice,
            'calculatedTaxes' => [[
                'tax' => \round($totalPrice - $netPrice, 2),
                'taxRate' => $taxRate,
                'price' => $totalPrice,
            ]],
            'taxRules' => [['taxRate' => $taxRate, 'percentage' => 100.0]],
        ];
    }

    /**
     * @return array<string, true> indexed by the hex id of every already imported demo order
     */
    private function getExistingOrderIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('
            SELECT LOWER(HEX(`id`))
            FROM `order`
            WHERE `order_number` LIKE :prefix
        ', ['prefix' => self::ORDER_NUMBER_PREFIX . '%']);

        return \array_fill_keys($ids, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rounding(): array
    {
        return [
            'decimals' => 2,
            'interval' => 0.01,
            'roundForNet' => true,
        ];
    }

    /**
     * @return array<string, array<string, string>> state ids by state machine technical name and state technical name
     */
    private function getStateIds(): array
    {
        /** @var list<array{machine: string, state: string, id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('
            SELECT LOWER(HEX(`state_machine_state`.`id`)) AS `id`,
                   `state_machine_state`.`technical_name` AS `state`,
                   `state_machine`.`technical_name` AS `machine`
            FROM `state_machine_state`
            INNER JOIN `state_machine` ON `state_machine`.`id` = `state_machine_state`.`state_machine_id`
            WHERE `state_machine`.`technical_name` IN (:machines)
        ', ['machines' => [OrderStates::STATE_MACHINE, 'order_delivery.state', 'order_transaction.state']], ['machines' => ArrayParameterType::STRING]);

        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['machine']][$row['state']] = $row['id'];
        }

        $required = [
            OrderStates::STATE_MACHINE => ['open', 'in_progress', 'completed', 'cancelled'],
            'order_delivery.state' => ['open', 'shipped', 'cancelled'],
            'order_transaction.state' => ['open', 'paid', 'cancelled'],
        ];
        foreach ($required as $machine => $states) {
            foreach ($states as $state) {
                if (!isset($ids[$machine][$state])) {
                    throw new \RuntimeException(\sprintf('State "%s" of state machine "%s" not found.', $state, $machine));
                }
            }
        }

        return $ids;
    }

    private function getPaymentMethodId(): string
    {
        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(`id`))
            FROM `payment_method`
            WHERE `active` = 1
            LIMIT 1
        ');

        if (!$result) {
            throw new \RuntimeException('No active payment method found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
    }

    private function getShippingMethodId(): string
    {
        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(`id`))
            FROM `shipping_method`
            WHERE `active` = 1
            LIMIT 1
        ');

        if (!$result) {
            throw new \RuntimeException('No active shipping method found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
    }

    private function getCountryIdByIsoCode(string $iso): string
    {
        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(`id`))
            FROM `country`
            WHERE `iso` = :iso
            LIMIT 1
        ', ['iso' => $iso]);

        if (!$result) {
            throw new \RuntimeException('No country for iso code "' . $iso . '" found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
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
