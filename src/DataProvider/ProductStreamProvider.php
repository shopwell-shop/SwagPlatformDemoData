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
use Shopwell\Core\Framework\Log\Package;
use Swag\PlatformDemoData\Helper\ResourceLoader;
use Swag\PlatformDemoData\Helper\TranslationHelper;

/**
 * Creates one dynamic product group per demo category plus a few condition
 * based groups. The compiled filters are written by the platform's
 * ProductStreamIndexer, which runs after every stream write.
 */
#[Package('fundamentals@after-sales')]
class ProductStreamProvider extends DemoDataProvider
{
    private Connection $connection;

    private TranslationHelper $translationHelper;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->translationHelper = new TranslationHelper($connection);
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'product_stream';
    }

    /**
     * @return list<string> ids of all demo product streams, in the order they are defined
     */
    public static function getDemoStreamIds(): array
    {
        return \array_map(
            static fn(array $category): string => self::categoryId($category['id']),
            self::getCategories(),
        );
    }

    public static function categoryId(string $categoryId): string
    {
        return \md5('shopwell-demo-data:product-stream:category:' . $categoryId);
    }

    public static function specialId(int $index): string
    {
        return \md5('shopwell-demo-data:product-stream:special:' . $index);
    }

    public function getPayload(): array
    {
        $payload = [];

        // One stream per category, parents include their children.
        foreach (self::getCategories() as $category) {
            $filters = [
                [
                    'id' => \md5('shopwell-demo-data:product-stream-filter:category:' . $category['id']),
                    'type' => 'equalsAny',
                    'field' => 'categoriesRo.id',
                    'value' => \implode('|', $this->expandCategory($category)),
                    'position' => 0,
                ],
            ];

            $payload[] = [
                'id' => self::categoryId($category['id']),
                'name' => $this->translationHelper->adjustTranslations($category['name']),
                'filters' => $filters,
            ];
        }

        foreach (self::specialStreams() as $index => $stream) {
            $payload[] = [
                'id' => self::specialId($index),
                'name' => $this->translationHelper->adjustTranslations($stream['name']),
                'filters' => $stream['filters'],
            ];
        }

        return $payload;
    }

    /**
     * Flattens the category tree: every category (parent or leaf) gets its own
     * stream, and the stream of a parent category also matches the products of
     * its children.
     *
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $flat
     *
     * @return list<array<string, mixed>>
     */
    private static function flattenCategories(array $nodes, array $flat = []): array
    {
        foreach ($nodes as $node) {
            $flat[] = $node;
            $flat = self::flattenCategories($node['children'] ?? [], $flat);
        }

        return $flat;
    }

    /**
     * The category itself and all of its children: the demo products are
     * assigned to the leaf categories, but the stream for a parent category
     * has to contain them as well.
     *
     * @param array<string, mixed> $category
     *
     * @return list<string>
     */
    private function expandCategory(array $category): array
    {
        $ids = [$category['id']];
        foreach ($category['children'] ?? [] as $child) {
            $ids[] = $child['id'];
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function getCategories(): array
    {
        /** @var array<string, mixed> $resource */
        $resource = ResourceLoader::load('categories.json');

        /** @var list<array<string, mixed>> $categories */
        $categories = $resource['categories'];

        return self::flattenCategories($categories);
    }

    /**
     * @return list<array{name: array{zh-CN: string, en-GB: string}, filters: list<array<string, mixed>>}>
     */
    private static function specialStreams(): array
    {
        return [
            [
                'name' => ['zh-CN' => '包邮专区', 'en-GB' => 'Free Shipping'],
                'filters' => [
                    self::specialFilter(0, 'equals', 'shippingFree', '1'),
                ],
            ],
            [
                'name' => ['zh-CN' => '热销爆款', 'en-GB' => 'Hot Sellers'],
                'filters' => [
                    self::specialFilter(1, 'equals', 'markAsTopseller', '1'),
                ],
            ],
            [
                'name' => ['zh-CN' => '百元好物', 'en-GB' => 'Under 100'],
                'filters' => [
                    self::specialFilter(2, 'range', 'stock', null, ['gte' => 1, 'lte' => 100]),
                ],
            ],
            [
                'name' => ['zh-CN' => '充足现货', 'en-GB' => 'Well Stocked'],
                'filters' => [
                    self::specialFilter(3, 'range', 'stock', null, ['gte' => 200]),
                ],
            ],
            [
                'name' => ['zh-CN' => '数码新品', 'en-GB' => 'Digital Picks'],
                'filters' => [
                    self::specialFilter(4, 'equals', 'markAsTopseller', '1'),
                    self::specialFilter(5, 'range', 'stock', null, ['gte' => 100]),
                ],
            ],
            [
                'name' => ['zh-CN' => '国货品牌', 'en-GB' => 'Domestic Brands'],
                'filters' => [
                    self::specialFilter(6, 'equalsAny', 'manufacturerId', \implode('|', \array_map(
                        static fn(array $manufacturer): string => $manufacturer['id'],
                        self::getManufacturers(),
                    ))),
                ],
            ],
            [
                'name' => ['zh-CN' => '轻便小件', 'en-GB' => 'Lightweight Picks'],
                'filters' => [
                    self::specialFilter(7, 'range', 'weight', null, ['gte' => 0, 'lte' => 1]),
                ],
            ],
            [
                'name' => ['zh-CN' => '大件好物', 'en-GB' => 'Bulky Goods'],
                'filters' => [
                    self::specialFilter(8, 'range', 'weight', null, ['gt' => 1]),
                ],
            ],
        ];
    }

    /**
     * @param array<string, float>|null $parameters
     */
    private static function specialFilter(
        int $index,
        string $type,
        string $field,
        ?string $value = null,
        ?array $parameters = null,
    ): array {
        $filter = [
            'id' => \md5('shopwell-demo-data:product-stream-filter:special:' . $index),
            'type' => $type,
            'field' => $field,
            'position' => 0,
        ];

        if ($value !== null) {
            $filter['value'] = $value;
        }

        if ($parameters !== null) {
            $filter['parameters'] = $parameters;
        }

        return $filter;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function getManufacturers(): array
    {
        /** @var array<string, mixed> $resource */
        $resource = ResourceLoader::load('manufacturers.json');

        /** @var list<array<string, mixed>> $manufacturers */
        $manufacturers = $resource['manufacturers'];

        return $manufacturers;
    }
}
