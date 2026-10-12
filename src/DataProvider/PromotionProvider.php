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
use Shopwell\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopwell\Core\Checkout\Promotion\PromotionCollection;
use Shopwell\Core\Defaults;
use Shopwell\Core\Framework\Context;
use Shopwell\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopwell\Core\Framework\DataAbstractionLayer\Write\Validation\RestrictDeleteViolationException;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Uuid\Uuid;
use Swag\PlatformDemoData\Helper\TranslationHelper;
use Shopwell\Core\Framework\Util\Hasher;

/**
 * Creates one promotion per discount type: a percentage cart discount, a
 * percentage discount that needs a code, a fixed amount cart discount, a fixed
 * amount discount that needs a code and a free shipping promotion.
 */
#[Package('fundamentals@after-sales')]
class PromotionProvider extends DemoDataProvider
{
    /**
     * Amount of promotions that older versions of the demo data set shipped.
     * Their deterministic ids are cleaned up as well, so that the promotion
     * list of the shop cannot grow with outdated entries.
     */
    private const LEGACY_PROMOTION_COUNT = 30;

    private Connection $connection;

    private TranslationHelper $translationHelper;

    /**
     * @var EntityRepository<PromotionCollection>
     */
    private EntityRepository $promotionRepository;

    /**
     * @param EntityRepository<PromotionCollection> $promotionRepository
     */
    public function __construct(Connection $connection, EntityRepository $promotionRepository)
    {
        $this->connection = $connection;
        $this->promotionRepository = $promotionRepository;
        $this->translationHelper = new TranslationHelper($connection);
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'promotion';
    }

    /**
     * @return list<string> ids of all demo promotions, in the order they are defined
     */
    public static function getDemoPromotionIds(): array
    {
        return \array_map(
            static fn(int $index): string => self::promotionId($index),
            \array_keys(self::promotions()),
        );
    }

    /**
     * @return list<string> deterministic ids of every promotion this plugin owns, including
     *                      the ones that older versions of the data set shipped
     */
    public static function getAllDemoPromotionIds(): array
    {
        $count = \max(self::LEGACY_PROMOTION_COUNT, \count(self::getDemoPromotionIds()));

        $ids = [];
        for ($index = 0; $index < $count; ++$index) {
            $ids[] = self::promotionId($index);
        }

        return $ids;
    }

    public static function promotionId(int $index): string
    {
        return Hasher::hash('shopwell-demo-data:promotion:' . $index, 'md5');
    }

    /**
     * Clears the promotions before the current set is written. This is required,
     * because an outdated promotion might still hold a code that the payload
     * below reuses, and the platform rejects duplicated codes.
     */
    public function prepare(Context $context): void
    {
        try {
            $this->promotionRepository->delete(
                \array_map(static fn(string $id): array => ['id' => $id], self::getAllDemoPromotionIds()),
                $context,
            );
        } catch (RestrictDeleteViolationException) {
            // A demo promotion might be referenced by an order, keep it in that case.
        }
    }

    /**
     * Deactivating the plugin removes every promotion this plugin owns, including
     * the ones of older demo data sets, otherwise they would stay in the shop forever.
     *
     * @return list<array<string, mixed>>
     */
    public function getDeletePayload(): array
    {
        return \array_map(
            static fn(string $id): array => ['id' => $id],
            self::getAllDemoPromotionIds(),
        );
    }

    public function getPayload(): array
    {
        $salesChannelIds = $this->getStorefrontSalesChannelIds();

        $payload = [];
        foreach (self::promotions() as $index => $promotion) {
            $usesCode = isset($promotion['code']);

            $payload[] = [
                'id' => self::promotionId($index),
                'name' => $this->translationHelper->adjustTranslations($promotion['name']),
                'active' => true,
                'priority' => 10 + $index,
                'exclusive' => false,
                'useCodes' => $usesCode,
                'code' => $promotion['code'] ?? null,
                'useIndividualCodes' => false,
                'useSetGroups' => false,
                'preventCombination' => false,
                // A manually entered code is a one time welcome gift, the
                // automatically applied discounts stay valid for every order.
                'maxRedemptionsPerCustomer' => $usesCode ? 1 : null,
                'salesChannels' => \array_map(
                    static fn(string $salesChannelId): array => [
                        'id' => Hasher::hash(self::promotionId($index) . ':sales-channel:' . $salesChannelId, 'md5'),
                        'salesChannelId' => $salesChannelId,
                        'priority' => 1,
                    ],
                    $salesChannelIds,
                ),
                'discounts' => [
                    [
                        'id' => Hasher::hash(self::promotionId($index) . ':discount:1', 'md5'),
                        'scope' => $promotion['scope'],
                        'type' => $promotion['type'],
                        'value' => $promotion['value'],
                        'considerAdvancedRules' => false,
                    ],
                ],
            ];
        }

        return $payload;
    }

    /**
     * @return list<array{name: array{zh-CN: string, en-GB: string}, type: string, scope: string, value: float, code?: string}>
     */
    private static function promotions(): array
    {
        return [
            [
                'name' => ['zh-CN' => '双11 狂欢盛典 · 全场 9 折', 'en-GB' => 'Double 11 Festival · 10% Off'],
                'type' => PromotionDiscountEntity::TYPE_PERCENTAGE,
                'scope' => PromotionDiscountEntity::SCOPE_CART,
                'value' => 10.0,
            ],
            [
                'name' => ['zh-CN' => '新人首单立减 15%', 'en-GB' => 'Newcomer Deal · 15% Off'],
                'type' => PromotionDiscountEntity::TYPE_PERCENTAGE,
                'scope' => PromotionDiscountEntity::SCOPE_CART,
                'value' => 15.0,
                'code' => 'SWDEMO-NEW',
            ],
            [
                'name' => ['zh-CN' => '下单立减 20 元', 'en-GB' => 'Instant Discount · 20 Off'],
                'type' => PromotionDiscountEntity::TYPE_ABSOLUTE,
                'scope' => PromotionDiscountEntity::SCOPE_CART,
                'value' => 20.0,
            ],
            [
                'name' => ['zh-CN' => '会员专享立减 50 元', 'en-GB' => 'VIP Exclusive · 50 Off'],
                'type' => PromotionDiscountEntity::TYPE_ABSOLUTE,
                'scope' => PromotionDiscountEntity::SCOPE_CART,
                'value' => 50.0,
                'code' => 'SWDEMO-VIP',
            ],
            [
                'name' => ['zh-CN' => '全场包邮', 'en-GB' => 'Free Shipping'],
                'type' => PromotionDiscountEntity::TYPE_PERCENTAGE,
                'scope' => PromotionDiscountEntity::SCOPE_DELIVERY,
                'value' => 100.0,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function getStorefrontSalesChannelIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('
            SELECT LOWER(HEX(`id`))
            FROM `sales_channel`
            WHERE `type_id` = :storefrontType
        ', ['storefrontType' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]);

        if ($ids === []) {
            throw new \RuntimeException('No storefront sales channel found, please make sure that basic data is available by running the migrations.');
        }

        return $ids;
    }
}
