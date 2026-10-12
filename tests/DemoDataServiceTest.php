<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\Tests;

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

#[Package('fundamentals@after-sales')]
class DemoDataServiceTest extends TestCase
{
    private const PRODUCT_COUNT = 30;
    private const CUSTOMER_COUNT = 30;
    private const MEDIA_COUNT = 91;
    private const CATEGORY_COUNT = 22;
    private const PROPERTY_GROUP_COUNT = 7;
    private const PROPERTY_OPTION_COUNT = 27;
    private const RULE_COUNT = 4;
    private const VARIANT_COUNT = 10;

    private const IMAGES_PER_PRODUCT = 3;

    protected function setUp(): void
    {
        $connection = $this->getContainer()->get(Connection::class);

        $connection->executeStatement('DELETE FROM product');
        $connection->executeStatement('DELETE FROM category WHERE parent_id IS NOT NULL');
        $connection->executeStatement('DELETE FROM customer');
        $connection->executeStatement('DELETE FROM property_group_option');
        $connection->executeStatement('DELETE FROM property_group');
    }

    public function testGenerate(): void
    {
        $demoDataService = $this->getContainer()->get(DemoDataService::class);

        $context = new Context(new SystemSource());
        $demoDataService->generate($context);

        $this->assertEntityCountGreaterThanOrEqual(self::CATEGORY_COUNT, 'category.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::CUSTOMER_COUNT, 'customer.repository');
        $this->assertEntityCountGreaterThanOrEqual(1, 'cms_page.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::MEDIA_COUNT, 'media.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PROPERTY_GROUP_COUNT, 'property_group.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PROPERTY_OPTION_COUNT, 'property_group_option.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::RULE_COUNT, 'rule.repository');
        $this->assertEntityCountGreaterThanOrEqual(1, 'sales_channel.repository');
        $this->assertEntityCountGreaterThanOrEqual(1, 'shipping_method.repository');
        $this->assertEntityCountGreaterThanOrEqual(self::PRODUCT_COUNT + self::VARIANT_COUNT, 'product.repository');
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

    private function assertEntityCountGreaterThanOrEqual(int $expectedCount, string $repositoryName): void
    {
        /** @var EntityRepository<CategoryCollection> $repository */
        $repository = $this->getContainer()->get($repositoryName);
        $ids = $repository->searchIds(new Criteria(), new Context(new SystemSource()))->getIds();
        static::assertGreaterThanOrEqual($expectedCount, \count($ids), 'There should be ' . $expectedCount . ' or more entities in ' . $repositoryName);
    }
}
