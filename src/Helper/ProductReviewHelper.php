<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\Helper;

use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Util\Hasher;

#[Package('fundamentals@after-sales')]
class ProductReviewHelper
{
    /**
     * Review copy per locale, keyed by locale code. Each entry is a list of
     * [title, content, points] tuples; the review index picks one.
     *
     * @var array<string, list<array{0: string, 1: string, 2: float}>>
     */
    private const COPY = [
        'zh-CN' => [
            ['非常好', '商品质量很好，包装严实，物流也快，和描述完全一致，很满意的一次购物。', 5.0],
            ['值得推荐', '用了几天才来评价，做工细节都不错，性价比很高，会推荐给朋友。', 5.0],
            ['整体不错', '整体挺满意的，就是包装还可以再改进一点，其他都没问题。', 4.0],
        ],
        'en-GB' => [
            ['Very good', 'Great quality, well packed and delivered quickly. Exactly as described — very happy with this purchase.', 5.0],
            ['Recommended', 'Used it for a few days before writing this. Solid build quality and good value for money. Would recommend.', 5.0],
            ['Pretty good', 'Overall satisfied. The packaging could be better, but the product itself is exactly what I expected.', 4.0],
        ],
    ];

    /**
     * Creates two demo reviews per product (one per available locale), written
     * by real demo customers.
     *
     * @param array<string, string> $languageIds locale code => language id
     * @param list<string> $customerIds
     *
     * @return list<array<string, mixed>>
     */
    public function createReviews(string $salesChannelId, array $languageIds, int $productIndex, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $reviews = [];
        foreach (self::COPY as $locale => $copy) {
            $languageId = $languageIds[$locale] ?? null;
            if ($languageId === null) {
                continue;
            }

            [$title, $content, $points] = $copy[$productIndex % \count($copy)];
            $customerId = $customerIds[($productIndex * 3 + ($locale === 'zh-CN' ? 0 : 1)) % \count($customerIds)];

            $reviews[] = [
                'id' => Hasher::hash('shopwell-demo-data:review:' . $productIndex . ':' . $locale, 'md5'),
                'salesChannelId' => $salesChannelId,
                'customerId' => $customerId,
                'languageId' => $languageId,
                'title' => $title,
                'content' => $content,
                'points' => $points,
                'status' => true,
            ];
        }

        return $reviews;
    }
}
