<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\DataProvider;

use Shopwell\Core\Framework\Log\Package;

#[Package('fundamentals@after-sales')]
class TaxProvider extends DemoDataProvider
{
    /**
     * The standard Chinese VAT rate used by every demo product.
     */
    public const TAX_RATE = 13.0;

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'tax';
    }

    public function getPayload(): array
    {
        return [
            [
                'id' => self::taxId(),
                'taxRate' => self::TAX_RATE,
                'name' => '增值税 ' . self::TAX_RATE . '%',
            ],
        ];
    }

    public static function taxId(): string
    {
        return \md5('shopwell-demo-data:tax:standard');
    }
}
