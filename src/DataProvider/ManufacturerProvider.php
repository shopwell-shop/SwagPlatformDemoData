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

#[Package('fundamentals@after-sales')]
class ManufacturerProvider extends DemoDataProvider
{
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
        // The DAL entity name is "product_manufacturer", not "manufacturer".
        return 'product_manufacturer';
    }

    public function getPayload(): array
    {
        /** @var array<string, mixed> $resource */
        $resource = ResourceLoader::load('manufacturers.json');

        /** @var list<array{id: string, name: array<string, string>}> $manufacturers */
        $manufacturers = $resource['manufacturers'];

        $payload = [];
        foreach ($manufacturers as $manufacturer) {
            $payload[] = [
                'id' => $manufacturer['id'],
                'name' => $this->translationHelper->adjustTranslations($manufacturer['name']),
            ];
        }

        return $payload;
    }
}
