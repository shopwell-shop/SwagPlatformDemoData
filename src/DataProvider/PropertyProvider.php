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
use Shopwell\Core\Content\Property\PropertyGroupDefinition;
use Shopwell\Core\Framework\Log\Package;
use Swag\PlatformDemoData\Helper\ResourceLoader;
use Swag\PlatformDemoData\Helper\TranslationHelper;

#[Package('fundamentals@after-sales')]
class PropertyProvider extends DemoDataProvider
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
        return 'property_group';
    }

    public function getPayload(): array
    {
        /** @var array<string, mixed> $resource */
        $resource = ResourceLoader::load('properties.json');
        /** @var list<array<string, mixed>> $groups */
        $groups = $resource['propertyGroups'];

        $payload = [];
        foreach ($groups as $group) {
            /** @var list<array<string, mixed>> $options */
            $options = $group['options'];

            $payload[] = [
                'id' => $group['id'],
                'sortingType' => $group['sortingType'] ?? PropertyGroupDefinition::SORTING_TYPE_ALPHANUMERIC,
                'displayType' => $group['displayType'] ?? PropertyGroupDefinition::DISPLAY_TYPE_TEXT,
                'name' => $this->translationHelper->adjustTranslations($group['name']),
                'options' => array_map(
                    fn(array $option) => \array_filter([
                        'id' => $option['id'],
                        'name' => $this->translationHelper->adjustTranslations($option['name']),
                        'colorHexCode' => $option['colorHexCode'] ?? null,
                    ], static fn($value) => $value !== null),
                    $options,
                ),
            ];
        }

        return $payload;
    }
}
