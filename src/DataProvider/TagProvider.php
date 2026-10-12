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
use Swag\PlatformDemoData\Helper\DbHelper;

#[Package('fundamentals@after-sales')]
class TagProvider extends DemoDataProvider
{
    /**
     * The shipped demo tags, bilingual.
     *
     * @var list<array{zh-CN: string, en-GB: string}>
     */
    private const TAGS = [
        ['zh-CN' => '新品上市', 'en-GB' => 'New Arrivals'],
        ['zh-CN' => '热卖爆款', 'en-GB' => 'Best Sellers'],
        ['zh-CN' => '限时折扣', 'en-GB' => 'Flash Sale'],
        ['zh-CN' => '包邮专区', 'en-GB' => 'Free Shipping'],
        ['zh-CN' => '正品行货', 'en-GB' => 'Authentic Goods'],
        ['zh-CN' => '国货之光', 'en-GB' => 'Domestic Picks'],
        ['zh-CN' => '全球直采', 'en-GB' => 'Globally Sourced'],
        ['zh-CN' => '高端精选', 'en-GB' => 'Premium Selection'],
        ['zh-CN' => '性价比之选', 'en-GB' => 'Best Value'],
        ['zh-CN' => '礼盒装', 'en-GB' => 'Gift Box'],
        ['zh-CN' => '限量发售', 'en-GB' => 'Limited Edition'],
        ['zh-CN' => '清仓特惠', 'en-GB' => 'Clearance'],
        ['zh-CN' => '预售专区', 'en-GB' => 'Pre-order'],
        ['zh-CN' => '会员专享', 'en-GB' => 'Members Only'],
        ['zh-CN' => '环保材质', 'en-GB' => 'Eco Materials'],
        ['zh-CN' => '有机认证', 'en-GB' => 'Certified Organic'],
        ['zh-CN' => '工厂直供', 'en-GB' => 'Factory Direct'],
        ['zh-CN' => '明星同款', 'en-GB' => 'Celebrity Style'],
        ['zh-CN' => '节日礼品', 'en-GB' => 'Holiday Gifts'],
        ['zh-CN' => '人气推荐', 'en-GB' => 'Popular Picks'],
    ];

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'tag';
    }

    /**
     * Runs before every other provider: the products and customers reference
     * the tags.
     *
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => 1,
            'delete' => -1,
        ];
    }

    /**
     * @return list<string> ids of all demo tags, in the order they are defined
     */
    public static function getDemoTagIds(): array
    {
        return \array_map(
            static fn(int $index): string => self::tagId($index),
            \array_keys(self::TAGS),
        );
    }

    public static function tagId(int $index): string
    {
        return \md5('shopwell-demo-data:tag:' . $index);
    }

    public function getPayload(): array
    {
        // Tag names are not translatable, so the name is picked for the shop's
        // system language and falls back to the English name.
        $systemLanguageCode = (new DbHelper($this->connection))->getSystemLanguageCode();

        $payload = [];
        foreach (self::TAGS as $index => $tag) {
            $payload[] = [
                'id' => self::tagId($index),
                'name' => $tag[$systemLanguageCode] ?? $tag['en-GB'],
            ];
        }

        return $payload;
    }
}
