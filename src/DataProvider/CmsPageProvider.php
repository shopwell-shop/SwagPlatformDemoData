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
use Shopwell\Core\Content\Cms\DataResolver\FieldConfig;
use Shopwell\Core\Framework\Log\Package;
use Swag\PlatformDemoData\Helper\TranslationHelper;

#[Package('fundamentals@after-sales')]
class CmsPageProvider extends DemoDataProvider
{
    private const CMS_PAGE_ID = '695477e02ef643e5a016b83ed4cdf63a';
    private const SECTION_ID = '935477e02ef643e5a016b83ed4cdf63a';
    private const SLOT_ID = '9e2f55fac84647098fe5b0f17ee4786f';

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
        return 'cms_page';
    }

    public function getPayload(): array
    {
        $mediaId = MediaProvider::getCmsMediaId();

        $slotConfig = static fn() => [
            'url' => [
                'value' => null,
                'source' => FieldConfig::SOURCE_STATIC,
            ],
            'media' => [
                'value' => $mediaId,
                'source' => FieldConfig::SOURCE_STATIC,
            ],
            'newTab' => [
                'value' => false,
                'source' => FieldConfig::SOURCE_STATIC,
            ],
            'minHeight' => [
                'value' => '340px',
                'source' => FieldConfig::SOURCE_STATIC,
            ],
            'displayMode' => [
                'value' => 'standard',
                'source' => FieldConfig::SOURCE_STATIC,
            ],
        ];

        return [
            [
                'id' => self::CMS_PAGE_ID,
                'type' => 'landingpage',
                'locked' => 0,
                'name' => $this->translationHelper->adjustTranslations([
                    'zh-CN' => 'Shopwell 首页横幅',
                    'en-GB' => 'Shopwell Homepage Banner',
                ]),
                'sections' => [
                    [
                        'id' => self::SECTION_ID,
                        'position' => 1,
                        'type' => 'default',
                        'blocks' => [
                            [
                                'position' => 0,
                                'type' => 'image-cover',
                                'locked' => 0,
                                'sizingMode' => 'boxed',
                                'backgroundMediaMode' => 'cover',
                                'slots' => [
                                    [
                                        'id' => self::SLOT_ID,
                                        'type' => 'image',
                                        'slot' => 'image',
                                        'locked' => 0,
                                        'translations' => $this->translationHelper->adjustTranslations([
                                            'zh-CN' => ['config' => $slotConfig()],
                                            'en-GB' => ['config' => $slotConfig()],
                                        ]),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
