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
use Shopwell\Core\Framework\Util\Hasher;
use Swag\PlatformDemoData\Helper\TranslationHelper;

/**
 * Creates the four demo membership tiers the loyalty program is built on.
 *
 * Every tier carries the growth value a customer has to reach, the multiplier
 * that is applied to earned points, the cart discount and whether shipping is
 * free. The customer assignments live in {@see CustomerLoyaltyProvider}.
 */
#[Package('fundamentals@after-sales')]
class LoyaltyTierProvider extends DemoDataProvider
{
    /**
     * The demo tiers, ordered by level. The growth value thresholds are the
     * entry requirement for the tier and are used to derive the membership of
     * the demo customers.
     *
     * @var list<array{
     *     technicalName: string,
     *     level: int,
     *     minGrowthValue: int,
     *     pointsMultiplier: float,
     *     discountPercent: float,
     *     freeShipping: bool,
     *     name: array{zh-CN: string, en-GB: string},
     *     description: array{zh-CN: string, en-GB: string}
     * }>
     */
    private const TIERS = [
        [
            'technicalName' => 'bronze',
            'level' => 1,
            'minGrowthValue' => 0,
            'pointsMultiplier' => 1.0,
            'discountPercent' => 0.0,
            'freeShipping' => false,
            'name' => ['zh-CN' => '普通会员', 'en-GB' => 'Bronze Member'],
            'description' => [
                'zh-CN' => '注册即可加入，消费每满 1 元累计 1 点成长值，积分按 1 倍发放。',
                'en-GB' => 'Join for free. Every yuan spent earns one growth point and points are credited with a 1x multiplier.',
            ],
        ],
        [
            'technicalName' => 'silver',
            'level' => 2,
            'minGrowthValue' => 1000,
            'pointsMultiplier' => 1.2,
            'discountPercent' => 2.0,
            'freeShipping' => false,
            'name' => ['zh-CN' => '银卡会员', 'en-GB' => 'Silver Member'],
            'description' => [
                'zh-CN' => '成长值满 1000 晋升，积分 1.2 倍发放，全场商品额外 98 折。',
                'en-GB' => 'Reach 1000 growth points. Earn a 1.2x points multiplier and a 2% discount on every order.',
            ],
        ],
        [
            'technicalName' => 'gold',
            'level' => 3,
            'minGrowthValue' => 5000,
            'pointsMultiplier' => 1.5,
            'discountPercent' => 5.0,
            'freeShipping' => true,
            'name' => ['zh-CN' => '金卡会员', 'en-GB' => 'Gold Member'],
            'description' => [
                'zh-CN' => '成长值满 5000 晋升，积分 1.5 倍发放，全场 95 折并享受全国包邮。',
                'en-GB' => 'Reach 5000 growth points. Earn a 1.5x points multiplier, a 5% discount and free shipping.',
            ],
        ],
        [
            'technicalName' => 'platinum',
            'level' => 4,
            'minGrowthValue' => 20000,
            'pointsMultiplier' => 2.0,
            'discountPercent' => 8.0,
            'freeShipping' => true,
            'name' => ['zh-CN' => '铂金会员', 'en-GB' => 'Platinum Member'],
            'description' => [
                'zh-CN' => '成长值满 20000 晋升，积分 2 倍发放，全场 92 折、包邮并享有专属客服。',
                'en-GB' => 'Reach 20000 growth points. Earn a 2x points multiplier, an 8% discount, free shipping and a dedicated support line.',
            ],
        ],
    ];

    private TranslationHelper $translationHelper;

    public function __construct(Connection $connection)
    {
        $this->translationHelper = new TranslationHelper($connection);
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'loyalty_tier';
    }

    /**
     * The tiers are written before the customer assignments, because those
     * reference them. On delete they are removed last, after every membership
     * pointing at them is gone.
     *
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => 2,
            'delete' => -5,
        ];
    }

    /**
     * @return list<string> ids of all demo tiers, ordered by level
     */
    public static function getDemoTierIds(): array
    {
        return \array_map(
            static fn(int $index): string => self::tierId($index),
            \array_keys(self::TIERS),
        );
    }

    public static function tierId(int $index): string
    {
        return Hasher::hash('shopwell-demo-data:loyalty-tier:' . $index, 'md5');
    }

    /**
     * Returns the growth value a customer needs to reach the tier at the given
     * index. Consumers use it to derive a consistent membership.
     */
    public static function minGrowthValue(int $index): int
    {
        return self::TIERS[$index]['minGrowthValue'];
    }

    public static function tierCount(): int
    {
        return \count(self::TIERS);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPayload(): array
    {
        $payload = [];

        foreach (self::TIERS as $index => $tier) {
            $payload[] = [
                'id' => self::tierId($index),
                'technicalName' => $tier['technicalName'],
                'level' => $tier['level'],
                'minGrowthValue' => $tier['minGrowthValue'],
                'pointsMultiplier' => $tier['pointsMultiplier'],
                'discountPercent' => $tier['discountPercent'],
                'freeShipping' => $tier['freeShipping'],
                'active' => true,
                'translations' => $this->translationHelper->adjustTranslations([
                    'zh-CN' => [
                        'name' => $tier['name']['zh-CN'],
                        'description' => $tier['description']['zh-CN'],
                    ],
                    'en-GB' => [
                        'name' => $tier['name']['en-GB'],
                        'description' => $tier['description']['en-GB'],
                    ],
                ]),
            ];
        }

        return $payload;
    }
}
