<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\Tests;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopwell\Core\Content\Category\CategoryCollection;
use Shopwell\Core\Defaults;
use Shopwell\Core\Framework\Api\Context\SystemSource;
use Shopwell\Core\Framework\Context;
use Shopwell\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopwell\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopwell\Core\Framework\Uuid\Uuid;
use Swag\PlatformDemoData\DataProvider\PromotionProvider;
use Swag\PlatformDemoData\DataProvider\RuleProvider;
use Swag\PlatformDemoData\DemoDataService;
use Swag\PlatformDemoData\Helper\ResourceLoader;

#[Package('fundamentals@after-sales')]
class DemoDataServiceTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const PRODUCT_COUNT = 30;
    private const CUSTOMER_COUNT = 30;
    private const MEDIA_COUNT = 91;
    private const CATEGORY_COUNT = 22;
    private const MANUFACTURER_COUNT = 11;
    private const PROPERTY_GROUP_COUNT = 7;
    private const PROPERTY_OPTION_COUNT = 27;
    private const RULE_COUNT = 4;
    private const VARIANT_COUNT = 69;
    private const ORDER_COUNT = 30;
    private const TAG_COUNT = 20;
    private const PRODUCT_STREAM_COUNT = 30;
    private const PROMOTION_COUNT = 5;
    private const LOYALTY_TIER_COUNT = 4;
    private const POINT_TRANSACTION_COUNT = 20;
    private const WALLET_COUNT = 10;
    private const WALLET_TRANSACTION_COUNT = 20;
    private const PAYMENT_ORDER_COUNT = 30;
    private const PAYMENT_REFUND_COUNT = 30;

    /**
     * Nothing the demo data writes may be dated further back than this.
     */
    private const OLDEST_ALLOWED_DAYS = 32;

    private const IMAGES_PER_PRODUCT = 3;

    protected function setUp(): void
    {
        $connection = $this->getContainer()->get(Connection::class);

        // The ledgers reference the orders, so they have to go first. The
        // payments and their refunds are removed through the tables, because
        // the DAL refuses to delete a payment order that carries history.
        $connection->executeStatement('DELETE FROM `payment_refund`');
        $connection->executeStatement('DELETE FROM `payment_order`');
        $connection->executeStatement('DELETE FROM `customer_point_transaction`');
        $connection->executeStatement('DELETE FROM `customer_wallet_transaction`');
        $connection->executeStatement('DELETE FROM `customer_loyalty`');
        $connection->executeStatement('DELETE FROM `customer_wallet`');
        $connection->executeStatement('DELETE FROM `loyalty_tier`');

        $connection->executeStatement('DELETE FROM `order`');
        $connection->executeStatement('DELETE FROM product');
        $connection->executeStatement('DELETE FROM category WHERE parent_id IS NOT NULL');
        $connection->executeStatement('DELETE FROM customer');
        $connection->executeStatement('DELETE FROM property_group_option');
        $connection->executeStatement('DELETE FROM property_group');
        $connection->executeStatement('DELETE FROM promotion');
        $connection->executeStatement('DELETE FROM product_stream');
        $connection->executeStatement('DELETE FROM tag');
    }

    public function testGenerate(): void
    {
        $demoDataService = $this->getContainer()->get(DemoDataService::class);

        $context = new Context(new SystemSource());
        $demoDataService->generate($context);

        $this->assertEntityCountGreaterThanOrEqual(self::CATEGORY_COUNT, 'category.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::MANUFACTURER_COUNT, 'product_manufacturer.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::CUSTOMER_COUNT, 'customer.repository');
        $this->assertEntityCountGreaterThanOrEqual(1, 'cms_page.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::MEDIA_COUNT, 'media.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PROPERTY_GROUP_COUNT, 'property_group.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PROPERTY_OPTION_COUNT, 'property_group_option.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::RULE_COUNT, 'rule.repository');
        $this->assertEntityCountGreaterThanOrEqual(1, 'sales_channel.repository');
        $this->assertEntityCountGreaterThanOrEqual(1, 'shipping_method.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PRODUCT_COUNT + self::VARIANT_COUNT, 'product.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::ORDER_COUNT, 'order.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::TAG_COUNT, 'tag.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PRODUCT_STREAM_COUNT, 'product_stream.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PROMOTION_COUNT, 'promotion.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::LOYALTY_TIER_COUNT, 'loyalty_tier.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::CUSTOMER_COUNT, 'customer_loyalty.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::POINT_TRANSACTION_COUNT, 'customer_point_transaction.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::WALLET_COUNT, 'customer_wallet.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::WALLET_TRANSACTION_COUNT, 'customer_wallet_transaction.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PAYMENT_ORDER_COUNT, 'payment_order.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PAYMENT_REFUND_COUNT, 'payment_refund.repository');
    }

    public function testEveryProductHasBetweenOneAndThreeVariants(): void
    {
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        $connection = $this->getContainer()->get(Connection::class);

        $variantCounts = $connection->fetchAllAssociative('
            SELECT p.product_number AS `number`, COUNT(c.id) AS `variants`
            FROM `product` p
            INNER JOIN `product` c ON c.parent_id = p.id
            WHERE p.product_number LIKE "SW-DEMO-%"
            GROUP BY p.id
        ');

        static::assertCount(self::PRODUCT_COUNT, $variantCounts);

        foreach ($variantCounts as $row) {
            static::assertGreaterThanOrEqual(
                1,
                (int) $row['variants'],
                'Product ' . $row['number'] . ' should have at least one variant',
            );
            static::assertLessThanOrEqual(
                3,
                (int) $row['variants'],
                'Product ' . $row['number'] . ' should have at most three variants',
            );
        }

        static::assertSame(
            self::VARIANT_COUNT,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM `product` WHERE `product_number` LIKE \'SW-DEMO-%.%\''),
        );
    }

    public function testOrdersReferenceSellableVariantsInsteadOfParents(): void
    {
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        $connection = $this->getContainer()->get(Connection::class);

        // no line item of a demo order points at a configurable parent
        static::assertSame(0, (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `order_line_item` li
            INNER JOIN `order` o ON o.id = li.order_id
            INNER JOIN `product` p ON p.id = li.product_id
            WHERE o.order_number LIKE "SW-DEMO-ORD-%"
              AND EXISTS (SELECT 1 FROM `product` child WHERE child.parent_id = p.id)
        '));
    }

    public function testGenerateAssignsTagsToProductsAndCustomers(): void
    {
        $connection = $this->getContainer()->get(Connection::class);
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        // three tags per product, two per customer
        $productTagCounts = $connection->fetchAllAssociative('
            SELECT COUNT(pt.tag_id) AS `c`
            FROM `product` p
            JOIN `product_tag` pt ON pt.product_id = p.id
            WHERE p.product_number LIKE "SW-DEMO-%" AND p.parent_id IS NULL
            GROUP BY p.id
        ');
        static::assertCount(self::PRODUCT_COUNT, $productTagCounts);
        foreach ($productTagCounts as $row) {
            static::assertSame(3, (int) $row['c']);
        }

        $customerTagCounts = $connection->fetchAllAssociative('
            SELECT COUNT(ct.tag_id) AS `c`
            FROM `customer` c
            JOIN `customer_tag` ct ON ct.customer_id = c.id
            WHERE c.customer_number LIKE "SW-DEMO-C%"
            GROUP BY c.id
        ');
        static::assertCount(self::CUSTOMER_COUNT, $customerTagCounts);
        foreach ($customerTagCounts as $row) {
            static::assertSame(2, (int) $row['c']);
        }
    }

    public function testGenerateCreatesAdvancedPricesAndCrossSellings(): void
    {
        $connection = $this->getContainer()->get(Connection::class);
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        // one advanced (member) price per demo product
        $advancedPrices = (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `product_price` pp
            JOIN `product` p ON p.id = pp.product_id
            WHERE p.product_number LIKE "SW-DEMO-%"
        ');
        static::assertSame(self::PRODUCT_COUNT, $advancedPrices);

        // two cross sellings per demo product, both pointing at demo streams
        $crossSellings = (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `product_cross_selling` cs
            JOIN `product` p ON p.id = cs.product_id
            JOIN `product_stream` ps ON ps.id = cs.product_stream_id
            WHERE p.product_number LIKE "SW-DEMO-%"
        ');
        static::assertSame(self::PRODUCT_COUNT * 2, $crossSellings);
    }

    public function testDemoPromotionsCoverEveryDiscountType(): void
    {
        $connection = $this->getContainer()->get(Connection::class);
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        $discounts = $connection->fetchAllAssociative('
            SELECT p.use_codes AS `useCodes`, d.scope AS `scope`, d.type AS `type`
            FROM `promotion` p
            INNER JOIN `promotion_discount` d ON d.promotion_id = p.id
        ');

        // one promotion per type, each of them with exactly one discount
        $combinations = \array_map(
            static fn(array $row): string => $row['scope'] . '/' . $row['type'] . '/' . $row['useCodes'],
            $discounts,
        );
        \sort($combinations);

        static::assertSame([
            'cart/absolute/0',
            'cart/absolute/1',
            'cart/percentage/0',
            'cart/percentage/1',
            'delivery/percentage/0',
        ], $combinations);

        // both code based promotions carry a code, the automatic ones do not
        static::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM `promotion` WHERE use_codes = 1 AND code IS NOT NULL'));

        // every promotion is available in the storefront sales channel
        static::assertSame(self::PROMOTION_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `promotion_sales_channel`'));
    }

    public function testGenerateUpgradesPromotionsOfOlderDemoDataSets(): void
    {
        $connection = $this->getContainer()->get(Connection::class);

        // older versions shipped 30 promotions and used the code "SWDEMO-NEW"
        // for the promotion with index 2, while the current set uses it for index 1
        $createdAt = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        for ($index = 0; $index < 30; ++$index) {
            $id = Uuid::fromHexToBytes(PromotionProvider::promotionId($index));
            $connection->insert('promotion', [
                'id' => $id,
                'active' => 1,
                'priority' => 10 + $index,
                'code' => $index === 2 ? 'SWDEMO-NEW' : null,
                'use_codes' => $index === 2 ? 1 : 0,
                'created_at' => $createdAt,
            ]);
            $connection->insert('promotion_translation', [
                'promotion_id' => $id,
                'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                'name' => 'Legacy promotion ' . $index,
                'created_at' => $createdAt,
            ]);
        }

        // generating must not fail on the duplicated code and drops the outdated entries
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        static::assertSame(self::PROMOTION_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `promotion`'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `promotion_translation` WHERE `name` LIKE \'Legacy promotion %\''));
        static::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM `promotion` WHERE use_codes = 1 AND code IS NOT NULL'));
    }

    public function testGenerateCreatesOrdersWithLineItems(): void
    {
        $connection = $this->getContainer()->get(Connection::class);
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        static::assertSame(self::ORDER_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `order` WHERE order_number LIKE "SW-DEMO-ORD-%"'));

        // every order carries line items, a delivery and a transaction
        static::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM `order` o WHERE NOT EXISTS (SELECT 1 FROM order_line_item li WHERE li.order_id = o.id)',
        ));
        static::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM `order` o WHERE NOT EXISTS (SELECT 1 FROM order_delivery d WHERE d.order_id = o.id)',
        ));
        static::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM `order` o WHERE NOT EXISTS (SELECT 1 FROM order_transaction t WHERE t.order_id = o.id)',
        ));

        // the order totals are derived from the price json
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `order` WHERE amount_total <= 0'));
    }

    public function testGenerateAssignsCartAmountRuleToAllShippingMethods(): void
    {
        $connection = $this->getContainer()->get(Connection::class);

        // A rule sorting before all others must not become the availability rule of the shipping methods.
        $connection->insert('rule', [
            'id' => Uuid::fromHexToBytes('00000000000000000000000000000001'),
            'name' => 'Never matching',
            'priority' => 1,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $demoDataService = $this->getContainer()->get(DemoDataService::class);
        $demoDataService->generate(new Context(new SystemSource()));

        $availabilityRuleIds = $connection->fetchFirstColumn('SELECT DISTINCT LOWER(HEX(availability_rule_id)) FROM shipping_method');

        static::assertSame([RuleProvider::CART_AMOUNT_RULE_ID], $availabilityRuleIds);
    }

    public function testEveryProductHasAtLeastThreeImages(): void
    {
        $demoDataService = $this->getContainer()->get(DemoDataService::class);
        $demoDataService->generate(new Context(new SystemSource()));

        $connection = $this->getContainer()->get(Connection::class);

        $counts = $connection->fetchAllAssociative('
            SELECT LOWER(HEX(product.id)) AS `id`, COUNT(product_media.id) AS `mediaCount`
            FROM `product`
            INNER JOIN `product_media` ON `product_media`.`product_id` = `product`.`id`
            WHERE `product`.`parent_id` IS NULL
            GROUP BY `product`.`id`
        ');

        static::assertCount(self::PRODUCT_COUNT, $counts);

        foreach ($counts as $row) {
            static::assertGreaterThanOrEqual(
                self::IMAGES_PER_PRODUCT,
                (int) $row['mediaCount'],
                'Product ' . $row['id'] . ' should have at least ' . self::IMAGES_PER_PRODUCT . ' images',
            );
        }
    }

    public function testDemoCustomerCanLogIn(): void
    {
        $demoDataService = $this->getContainer()->get(DemoDataService::class);
        $demoDataService->generate(new Context(new SystemSource()));

        $connection = $this->getContainer()->get(Connection::class);
        $customerCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer`');
        static::assertSame(self::CUSTOMER_COUNT, $customerCount);

        // The demo password "shopwell123" must verify against the stored hash.
        $hash = (string) $connection->fetchOne('SELECT `password` FROM `customer` LIMIT 1');
        static::assertTrue(\password_verify('shopwell123', $hash));
    }

    public function testGenerateCreatesLoyaltyProgramWithMatchingBalances(): void
    {
        $connection = $this->getContainer()->get(Connection::class);
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        // the four tiers exist and every one of them is in use
        static::assertSame(self::LOYALTY_TIER_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `loyalty_tier`'));
        static::assertSame(self::LOYALTY_TIER_COUNT, (int) $connection->fetchOne('SELECT COUNT(DISTINCT `membership_tier_id`) FROM `customer_loyalty`'));

        // every demo customer owns a membership
        static::assertSame(self::CUSTOMER_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer_loyalty`'));

        // the ledger is complete and all four booking types are used
        static::assertSame(self::POINT_TRANSACTION_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer_point_transaction`'));
        static::assertSame(
            ['adjust', 'award', 'expire', 'redeem'],
            \array_keys($connection->fetchAllKeyValue('SELECT `type`, COUNT(*) FROM `customer_point_transaction` GROUP BY `type` ORDER BY `type`')),
        );

        // the balance of a membership is the sum of the bookings of its customer
        static::assertSame(0, (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `customer_loyalty` cl
            WHERE EXISTS (SELECT 1 FROM `customer_point_transaction` t WHERE t.customer_id = cl.customer_id)
              AND cl.points_balance <> (
                  SELECT COALESCE(SUM(t.points), 0) FROM `customer_point_transaction` t WHERE t.customer_id = cl.customer_id
              )
        '));

        // every balance_after is the balance that customer had at that moment,
        // so the rows are chronological as well
        static::assertSame(0, (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `customer_point_transaction` t
            WHERE t.balance_after <> (
                SELECT COALESCE(SUM(p.points), 0) FROM `customer_point_transaction` p
                WHERE p.customer_id = t.customer_id
                  AND (p.created_at < t.created_at OR (p.created_at = t.created_at AND p.id <= t.id))
            )
        '));
    }

    public function testGenerateCreatesWalletHistory(): void
    {
        $connection = $this->getContainer()->get(Connection::class);
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        static::assertSame(self::WALLET_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer_wallet`'));
        static::assertSame(self::WALLET_TRANSACTION_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer_wallet_transaction`'));

        // every wallet has a history and its balance is the sum of it
        static::assertSame(0, (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `customer_wallet` w
            WHERE NOT EXISTS (SELECT 1 FROM `customer_wallet_transaction` t WHERE t.customer_wallet_id = w.id)
               OR w.balance <> (
                   SELECT COALESCE(SUM(t.amount), 0) FROM `customer_wallet_transaction` t WHERE t.customer_wallet_id = w.id
               )
        '));

        // every balance_after is the balance that wallet had at that moment
        static::assertSame(0, (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `customer_wallet_transaction` t
            WHERE t.balance_after <> (
                SELECT COALESCE(SUM(p.amount), 0) FROM `customer_wallet_transaction` p
                WHERE p.customer_wallet_id = t.customer_wallet_id
                  AND (p.created_at < t.created_at OR (p.created_at = t.created_at AND p.id <= t.id))
            )
        '));
    }

    public function testGenerateCreatesPaymentsAndRefunds(): void
    {
        $connection = $this->getContainer()->get(Connection::class);
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        static::assertSame(self::PAYMENT_ORDER_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `payment_order`'));
        static::assertSame(self::PAYMENT_REFUND_COUNT, (int) $connection->fetchOne('SELECT COUNT(*) FROM `payment_refund`'));

        // every payment belongs to a demo order
        static::assertSame(0, (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `payment_order` po
            WHERE NOT EXISTS (SELECT 1 FROM `order` o WHERE o.order_number = po.out_trade_no)
        '));

        // the payments tell a story: plain, fully and partly refunded ones
        $states = $connection->fetchAllKeyValue('
            SELECT sms.technical_name, COUNT(*)
            FROM `payment_order` po
            INNER JOIN `state_machine_state` sms ON sms.id = po.state_id
            GROUP BY sms.technical_name
        ');
        static::assertSame(5, (int) ($states['paid'] ?? 0));
        static::assertSame(10, (int) ($states['refunded'] ?? 0));
        static::assertSame(15, (int) ($states['refunded_partially'] ?? 0));

        // the refunded amount of a payment is exactly the sum of its refunds
        static::assertSame(0, (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `payment_order` po
            WHERE po.refunded_amount <> (
                SELECT COALESCE(SUM(r.amount), 0) FROM `payment_refund` r WHERE r.payment_order_id = po.id
            )
        '));

        // a payment without refunds is never in a refunded state
        static::assertSame(0, (int) $connection->fetchOne('
            SELECT COUNT(*) FROM `payment_order` po
            INNER JOIN `state_machine_state` sms ON sms.id = po.state_id
            WHERE sms.technical_name = \'paid\' AND po.refunded_amount > 0
        '));
    }

    public function testDemoDataCoversTodayAndYesterday(): void
    {
        $connection = $this->getContainer()->get(Connection::class);
        $this->getContainer()->get(DemoDataService::class)->generate(new Context(new SystemSource()));

        $timestamps = [
            'order' => 'order_date_time',
            'customer_loyalty' => 'created_at',
            'customer_point_transaction' => 'created_at',
            'customer_wallet_transaction' => 'created_at',
            'payment_order' => 'created_at',
            'payment_refund' => 'created_at',
        ];

        foreach ($timestamps as $table => $column) {
            // the shop has to look alive on the day it was installed, so both
            // the current day and the day before carry records
            foreach (['today' => 0, 'yesterday' => 1] as $label => $daysAgo) {
                static::assertGreaterThan(
                    0,
                    (int) $connection->fetchOne(\sprintf(
                        'SELECT COUNT(*) FROM `%s` WHERE DATEDIFF(CURDATE(), DATE(`%s`)) = %d',
                        $table,
                        $column,
                        $daysAgo,
                    )),
                    \sprintf('%s must contain at least one record of %s', $table, $label),
                );
            }

            // and nothing is dated further back than the last month
            static::assertSame(
                0,
                (int) $connection->fetchOne(\sprintf(
                    'SELECT COUNT(*) FROM `%s` WHERE `%s` < DATE_SUB(NOW(), INTERVAL %d DAY)',
                    $table,
                    $column,
                    self::OLDEST_ALLOWED_DAYS,
                )),
                \sprintf('%s must not contain records older than %d days', $table, self::OLDEST_ALLOWED_DAYS),
            );
        }
    }

    public function testDeleteRemovesAllDemoData(): void
    {
        $demoDataService = $this->getContainer()->get(DemoDataService::class);
        $context = new Context(new SystemSource());

        $demoDataService->generate($context);
        $demoDataService->delete($context);

        $connection = $this->getContainer()->get(Connection::class);

        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `product` WHERE `product_number` LIKE \'SW-DEMO-%\''));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer` WHERE `customer_number` LIKE \'SW-DEMO-C%\''));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `media` WHERE `file_name` LIKE \'SW-DEMO-%\''));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `category` WHERE `parent_id` IS NOT NULL'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `property_group`'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `order` WHERE `order_number` LIKE \'SW-DEMO-ORD-%\''));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `tag`'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `product_stream`'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `promotion`'));

        // the loyalty program, the wallets and the payments are removed too
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `loyalty_tier`'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer_loyalty`'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer_point_transaction`'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer_wallet`'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer_wallet_transaction`'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `payment_refund`'));

        // The DAL refuses to remove a payment order that carries history and
        // the delete sequence swallows that error, so the payment provider
        // removes the rows from the tables directly.
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `payment_order`'));

        // Manufacturers used to survive a deactivation because they were only
        // created implicitly as a nested association of the product.
        $manufacturerIds = \array_map(
            static fn(array $manufacturer): string => $manufacturer['id'],
            ResourceLoader::load('manufacturers.json')['manufacturers'],
        );

        $remainingManufacturers = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM `product_manufacturer` WHERE `id` IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($manufacturerIds)],
            ['ids' => ArrayParameterType::BINARY],
        );

        static::assertSame(0, $remainingManufacturers);

        // The demo rules can only be removed when the shipping methods released
        // them beforehand, otherwise the delete is rejected as a restrict violation.
        static::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM `rule` WHERE `id` = :ruleId',
            ['ruleId' => Uuid::fromHexToBytes(RuleProvider::CART_AMOUNT_RULE_ID)],
        ));

        static::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM `shipping_method` WHERE `availability_rule_id` = :ruleId',
            ['ruleId' => Uuid::fromHexToBytes(RuleProvider::CART_AMOUNT_RULE_ID)],
        ));
    }

    private function assertEntityCountGreaterThanOrEqual(int $expectedCount, string $repositoryName): void
    {
        /** @var EntityRepository<CategoryCollection> $repository */
        $repository = $this->getContainer()->get($repositoryName);
        $ids = $repository->searchIds(new Criteria(), new Context(new SystemSource()))->getIds();
        static::assertGreaterThanOrEqual($expectedCount, \count($ids), 'There should be ' . $expectedCount . ' or more entities in ' . $repositoryName);
    }
}
