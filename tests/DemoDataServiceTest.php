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
use Shopwell\Core\Framework\Uuid\Uuid;
use Swag\PlatformDemoData\DataProvider\RuleProvider;
use Swag\PlatformDemoData\DemoDataService;
use Swag\PlatformDemoData\Helper\ResourceLoader;

#[Package('fundamentals@after-sales')]
class DemoDataServiceTest extends TestCase
{
    private const PRODUCT_COUNT = 30;
    private const CUSTOMER_COUNT = 30;
    private const MEDIA_COUNT = 91;
    private const CATEGORY_COUNT = 22;
    private const MANUFACTURER_COUNT = 11;
    private const PROPERTY_GROUP_COUNT = 7;
    private const PROPERTY_OPTION_COUNT = 27;
    private const RULE_COUNT = 4;
    private const VARIANT_COUNT = 10;
    private const ORDER_COUNT = 30;
    private const TAG_COUNT = 20;
    private const PRODUCT_STREAM_COUNT = 30;
    private const PROMOTION_COUNT = 30;

    private const IMAGES_PER_PRODUCT = 3;

    protected function setUp(): void
    {
        $connection = $this->getContainer()->get(Connection::class);

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
        $this->assertEntityCountGreaterThanOrEqual(self::MANUFACTURER_COUNT, 'manufacturer.repository');
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

    public function testDemoCustomersCanLogIn(): void
    {
        $demoDataService = $this->getContainer()->get(DemoDataService::class);
        $demoDataService->generate(new Context(new SystemSource()));

        $connection = $this->getContainer()->get(Connection::class);
        $customerCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM `customer`');
        static::assertSame(self::CUSTOMER_COUNT, $customerCount);

        // The demo password "shopwell123" must verify against the stored hash.
        $hashes = $connection->fetchFirstColumn('SELECT `password` FROM `customer` LIMIT 1');
        static::assertNotFalse(\password_verify('shopwell123', (string) $hashes[0]));
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
