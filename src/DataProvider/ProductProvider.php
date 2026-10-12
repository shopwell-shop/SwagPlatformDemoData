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
use Shopwell\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopwell\Core\Defaults;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Uuid\Uuid;
use Swag\PlatformDemoData\Helper\ProductReviewHelper;
use Swag\PlatformDemoData\Helper\ResourceLoader;
use Swag\PlatformDemoData\Helper\TranslationHelper;
use Symfony\Component\Clock\NativeClock;

#[Package('fundamentals@after-sales')]
class ProductProvider extends DemoDataProvider
{
    /**
     * The factor applied to the gross price to get the demo member price.
     */
    public const MEMBER_DISCOUNT = 0.9;

    private Connection $connection;

    private TranslationHelper $translationHelper;

    private ProductReviewHelper $productReviewHelper;

    private ClockInterface $clock;

    public function __construct(Connection $connection, ?ClockInterface $clock = null)
    {
        $this->connection = $connection;
        $this->clock = $clock ?? new NativeClock();
        $this->translationHelper = new TranslationHelper($connection);
        $this->productReviewHelper = new ProductReviewHelper();
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'product';
    }

    /**
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => 0,
            'delete' => 1,
        ];
    }

    /**
     * @return list<array<string, mixed>> all shipped demo products, in the order they are defined
     */
    public static function getDemoProducts(): array
    {
        /** @var array<string, mixed> $resource */
        $resource = ResourceLoader::load('products.json');

        /** @var list<array<string, mixed>> $products */
        $products = $resource['products'];

        return $products;
    }

    public function getPayload(): array
    {
        $tax = $this->getTax();
        $storefrontSalesChannel = $this->getStorefrontSalesChannel();
        $languageIds = $this->translationHelper->getAvailableLanguageIds(['zh-CN', 'en-GB']);
        $customerIds = CustomerProvider::getDemoCustomerIds();

        $payload = [];
        foreach (self::getDemoProducts() as $index => $product) {
            $payload[] = $this->buildProduct($index, $product, $tax, $storefrontSalesChannel, $languageIds, $customerIds);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $product
     * @param array{0: string, 1: float} $tax
     * @param array<string, string> $languageIds
     *
     * @return array<string, mixed>
     */
    private function buildProduct(
        int $index,
        array $product,
        array $tax,
        string $storefrontSalesChannel,
        array $languageIds,
        array $customerIds,
    ): array {
        [$taxId, $taxRate] = $tax;
        $gross = (float) $product['grossPrice'];
        $cost = (float) $product['purchasePrice'];
        $stock = (int) $product['stock'];
        $productId = (string) $product['id'];

        /** @var list<string> $mediaIds */
        $mediaIds = $product['media'];
        $media = [];
        foreach ($mediaIds as $position => $mediaId) {
            $media[] = [
                'id' => \md5($productId . ':media:' . $position),
                'position' => $position + 1,
                'mediaId' => $mediaId,
            ];
        }

        $entry = [
            'id' => $productId,
            'productNumber' => $product['productNumber'],
            'ean' => $product['ean'],
            'active' => $product['active'],
            'taxId' => $taxId,
            'stock' => $stock,
            'purchaseUnit' => 1.0,
            'referenceUnit' => 1.0,
            'shippingFree' => $product['shippingFree'],
            'markAsTopseller' => $product['markAsTopseller'],
            'purchasePrices' => [[
                'currencyId' => Defaults::CURRENCY,
                'net' => $this->net($cost, $taxRate),
                'gross' => $cost,
                'linked' => true,
            ]],
            'weight' => $product['weight'],
            'width' => $product['width'],
            'height' => $product['height'],
            'length' => $product['length'],
            'releaseDate' => $this->clock->now(),
            'name' => $this->translationHelper->adjustTranslations($product['name']),
            'description' => $this->translationHelper->adjustTranslations($product['description']),
            'manufacturerId' => $product['manufacturerId'],
            'media' => $media,
            'coverId' => $media[0]['id'],
            'categories' => array_map(
                static fn(string $categoryId) => ['id' => $categoryId],
                $product['categories'],
            ),
            'price' => [[
                'currencyId' => Defaults::CURRENCY,
                'net' => $this->net($gross, $taxRate),
                'gross' => $gross,
                'linked' => true,
            ]],
            // Advanced price: a member price for the demo customer group rule.
            'prices' => [[
                'id' => \md5($productId . ':advanced-price:1'),
                'ruleId' => RuleProvider::CUSTOMER_GROUP_RULE_ID,
                'quantityStart' => 1,
                'price' => [[
                    'currencyId' => Defaults::CURRENCY,
                    'net' => $this->net($gross * self::MEMBER_DISCOUNT, $taxRate),
                    'gross' => \round($gross * self::MEMBER_DISCOUNT, 2),
                    'linked' => true,
                ]],
            ]],
            'crossSellings' => [
                [
                    'id' => \md5($productId . ':cross-selling:1'),
                    'name' => $this->translationHelper->adjustTranslations([
                        'zh-CN' => '看了又看',
                        'en-GB' => 'You may also like',
                    ]),
                    'position' => 1,
                    'active' => true,
                    'type' => 'productStream',
                    'sortBy' => 'cheapestPrice',
                    'sortDirection' => 'ASC',
                    'limit' => 6,
                    'productStreamId' => ProductStreamProvider::categoryId($product['categories'][0]),
                ],
                [
                    'id' => \md5($productId . ':cross-selling:2'),
                    'name' => $this->translationHelper->adjustTranslations([
                        'zh-CN' => '猜你喜欢',
                        'en-GB' => 'Recommended for you',
                    ]),
                    'position' => 2,
                    'active' => true,
                    'type' => 'productStream',
                    'sortBy' => 'name',
                    'sortDirection' => 'ASC',
                    'limit' => 6,
                    'productStreamId' => ProductStreamProvider::specialId(1),
                ],
            ],
            'tags' => \array_map(
                static fn(int $tagIndex): array => ['id' => TagProvider::tagId($tagIndex)],
                self::tagIndexesFor($index),
            ),
            'visibilities' => [
                [
                    'id' => \md5($productId . ':visibility'),
                    'salesChannelId' => $storefrontSalesChannel,
                    'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                ],
            ],
            'properties' => array_map(
                static fn(string $optionId) => ['id' => $optionId],
                $product['properties'],
            ),
            'productReviews' => $this->productReviewHelper->createReviews(
                $storefrontSalesChannel,
                $languageIds,
                $index,
                $customerIds,
            ),
        ];

        /** @var list<array{group: string, options: list<string>}>|null $variants */
        $variants = $product['variants'] ?? null;
        if (\is_array($variants)) {
            $entry = \array_merge($entry, $this->buildVariants($product, $variants));
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $product
     * @param list<array{group: string, options: list<string>}> $variants
     *
     * @return array<string, mixed>
     */
    private function buildVariants(array $product, array $variants): array
    {
        $optionIds = [];
        foreach ($variants as $variant) {
            $optionIds = \array_merge($optionIds, $variant['options']);
        }

        $configuratorSettings = array_map(
            static fn(string $optionId) => [
                // deterministic id: the unique index on the configurator
                // setting table rejects a second row for the same option
                'id' => \md5($product['id'] . ':configurator:' . $optionId),
                'optionId' => $optionId,
            ],
            $optionIds,
        );

        $children = [];
        $number = 0;
        foreach ($this->combinations($variants) as $combination) {
            ++$number;
            $children[] = [
                'id' => \md5($product['id'] . ':variant:' . $number),
                'productNumber' => $product['productNumber'] . '.' . $number,
                'stock' => (int) $product['stock'],
                'options' => array_map(static fn(string $optionId) => ['id' => $optionId], $combination),
            ];
        }

        return [
            'configuratorSettings' => $configuratorSettings,
            'children' => $children,
        ];
    }

    /**
     * Builds the cartesian product of all variant option groups.
     *
     * @param list<array{group: string, options: list<string>}> $variants
     *
     * @return list<list<string>>
     */
    private function combinations(array $variants): array
    {
        $result = [[]];
        foreach ($variants as $variant) {
            $next = [];
            foreach ($result as $existing) {
                foreach ($variant['options'] as $optionId) {
                    $next[] = \array_merge($existing, [$optionId]);
                }
            }
            $result = $next;
        }

        return $result;
    }

    /**
     * Three tags per product, rotated deterministically over the demo tags.
     *
     * @return list<int>
     */
    private static function tagIndexesFor(int $productIndex): array
    {
        $tagCount = \count(TagProvider::getDemoTagIds());

        return [
            $productIndex % $tagCount,
            ($productIndex + 3) % $tagCount,
            ($productIndex + 7) % $tagCount,
        ];
    }

    /**
     * Prefers the Chinese VAT rate created by the demo data and falls back to
     * the highest existing rate.
     *
     * @return array{0: string, 1: float} tax id and rate
     */
    private function getTax(): array
    {
        $row = $this->connection->fetchAssociative('
            SELECT LOWER(HEX(`id`)) AS `tax_id`, `tax_rate` AS `rate`
            FROM `tax`
            WHERE `id` = :demoTaxId
            LIMIT 1
        ', ['demoTaxId' => Uuid::fromHexToBytes(TaxProvider::taxId())]);

        if ($row === false) {
            $row = $this->connection->fetchAssociative('
                SELECT LOWER(HEX(`id`)) AS `tax_id`, `tax_rate` AS `rate`
                FROM `tax`
                ORDER BY `tax_rate` DESC
                LIMIT 1
            ');
        }

        if ($row === false) {
            throw new \RuntimeException('No tax found, please make sure that basic data is available by running the migrations.');
        }

        return [(string) $row['tax_id'], (float) $row['rate']];
    }

    private function net(float $gross, float $taxRate): float
    {
        return \round($gross / (1 + $taxRate / 100), 2);
    }

    private function getStorefrontSalesChannel(): string
    {
        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(`id`))
            FROM `sales_channel`
            WHERE `type_id` = :storefront_type
        ', ['storefront_type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]);

        if (!$result) {
            throw new \RuntimeException('No storefront sales channel found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
    }
}
