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
use Shopwell\Core\Defaults;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Uuid\Uuid;
use Swag\PlatformDemoData\Helper\TranslationHelper;

/**
 * Creates a mix of automatically applied and code based promotions with
 * percentage discounts.
 */
#[Package('fundamentals@after-sales')]
class PromotionProvider extends DemoDataProvider
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

    public static function promotionId(int $index): string
    {
        return \md5('shopwell-demo-data:promotion:' . $index);
    }

    public function getPayload(): array
    {
        $salesChannelIds = $this->getStorefrontSalesChannelIds();

        $payload = [];
        foreach (self::promotions() as $index => $promotion) {
            $payload[] = [
                'id' => self::promotionId($index),
                'name' => $this->translationHelper->adjustTranslations($promotion['name']),
                'active' => true,
                'priority' => 10 + $index,
                'exclusive' => false,
                'useCodes' => isset($promotion['code']),
                'code' => $promotion['code'] ?? null,
                'useIndividualCodes' => false,
                'useSetGroups' => false,
                'preventCombination' => false,
                'maxRedemptionsPerCustomer' => 1,
                'salesChannels' => \array_map(
                    static fn(string $salesChannelId): array => [
                        'id' => \md5(self::promotionId($index) . ':sales-channel:' . $salesChannelId),
                        'salesChannelId' => $salesChannelId,
                        'priority' => 1,
                    ],
                    $salesChannelIds,
                ),
                'discounts' => [
                    [
                        'id' => \md5(self::promotionId($index) . ':discount:1'),
                        'scope' => 'cart',
                        'type' => 'percentage',
                        'value' => $promotion['discount'],
                        'considerAdvancedRules' => false,
                    ],
                ],
            ];
        }

        return $payload;
    }

    /**
     * @return list<array{name: array{zh-CN: string, en-GB: string}, code?: string, discount: float}>
     */
    private static function promotions(): array
    {
        return [
            ['name' => ['zh-CN' => '双11 狂欢盛典', 'en-GB' => 'Double 11 Festival'], 'discount' => 20.0],
            ['name' => ['zh-CN' => '618 年中大促', 'en-GB' => '618 Mid-Year Sale'], 'discount' => 15.0],
            ['name' => ['zh-CN' => '新人首单立减', 'en-GB' => 'Newcomer Deal'], 'code' => 'SWDEMO-NEW', 'discount' => 10.0],
            ['name' => ['zh-CN' => '会员日专享', 'en-GB' => 'Members Day'], 'discount' => 8.0],
            ['name' => ['zh-CN' => '年货节大促', 'en-GB' => 'Spring Festival Sale'], 'discount' => 12.0],
            ['name' => ['zh-CN' => '开学季特惠', 'en-GB' => 'Back to School'], 'discount' => 10.0],
            ['name' => ['zh-CN' => '女神节献礼', 'en-GB' => 'Women\'s Day'], 'discount' => 18.0],
            ['name' => ['zh-CN' => '五一出游季', 'en-GB' => 'May Day Travel'], 'discount' => 9.0],
            ['name' => ['zh-CN' => '六一儿童节', 'en-GB' => 'Children\'s Day'], 'code' => 'SWDEMO-KIDS', 'discount' => 15.0],
            ['name' => ['zh-CN' => '端午粽香礼', 'en-GB' => 'Dragon Boat Gifts'], 'discount' => 10.0],
            ['name' => ['zh-CN' => '中秋团圆购', 'en-GB' => 'Mid-Autumn Sale'], 'discount' => 14.0],
            ['name' => ['zh-CN' => '国庆黄金周', 'en-GB' => 'Golden Week'], 'discount' => 16.0],
            ['name' => ['zh-CN' => '双12 年终礼', 'en-GB' => 'Double 12 Finale'], 'discount' => 20.0],
            ['name' => ['zh-CN' => '圣诞暖心购', 'en-GB' => 'Christmas Deals'], 'code' => 'SWDEMO-XMAS', 'discount' => 12.0],
            ['name' => ['zh-CN' => '数码焕新季', 'en-GB' => 'Digital Upgrade'], 'discount' => 11.0],
            ['name' => ['zh-CN' => '美妆焕肤节', 'en-GB' => 'Beauty Week'], 'discount' => 13.0],
            ['name' => ['zh-CN' => '家居焕新日', 'en-GB' => 'Home Refresh'], 'discount' => 9.0],
            ['name' => ['zh-CN' => '美食嘉年华', 'en-GB' => 'Food Carnival'], 'discount' => 10.0],
            ['name' => ['zh-CN' => '服饰换新补贴', 'en-GB' => 'Wardrobe Refresh'], 'discount' => 15.0],
            ['name' => ['zh-CN' => '老客回馈日', 'en-GB' => 'Loyalty Reward'], 'code' => 'SWDEMO-VIP', 'discount' => 25.0],
            ['name' => ['zh-CN' => '周末闪购', 'en-GB' => 'Weekend Flash'], 'discount' => 7.0],
            ['name' => ['zh-CN' => '深夜食堂补贴', 'en-GB' => 'Late Night Treats'], 'discount' => 8.0],
            ['name' => ['zh-CN' => '绿色环保周', 'en-GB' => 'Eco Week'], 'discount' => 6.0],
            ['name' => ['zh-CN' => '运动健身季', 'en-GB' => 'Sports Season'], 'discount' => 10.0],
            ['name' => ['zh-CN' => '出行装备节', 'en-GB' => 'Travel Gear'], 'discount' => 9.0],
            ['name' => ['zh-CN' => '母婴关爱日', 'en-GB' => 'Family Day'], 'discount' => 12.0],
            ['name' => ['zh-CN' => '宠物友好日', 'en-GB' => 'Pet Friendly'], 'discount' => 10.0],
            ['name' => ['zh-CN' => '茶咖满减日', 'en-GB' => 'Tea & Coffee Day'], 'discount' => 11.0],
            ['name' => ['zh-CN' => '香水邂逅礼', 'en-GB' => 'Fragrance Encounter'], 'discount' => 14.0],
            ['name' => ['zh-CN' => '周年庆狂欢', 'en-GB' => 'Anniversary Bash'], 'code' => 'SWDEMO-BDAY', 'discount' => 30.0],
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
