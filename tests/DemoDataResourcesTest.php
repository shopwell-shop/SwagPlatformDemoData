<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\Tests;

use PHPUnit\Framework\TestCase;
use Shopwell\Core\Framework\Log\Package;
use Swag\PlatformDemoData\DataProvider\MediaProvider;
use Swag\PlatformDemoData\Helper\ResourceLoader;

#[Package('fundamentals@after-sales')]
class DemoDataResourcesTest extends TestCase
{
    private const MIN_IMAGES_PER_PRODUCT = 3;

    /**
     * FileSaver::ensureFileNameIsUnique() rejects a second media that reuses an
     * existing file name, which used to abort the import with
     * `A file with the name "1.jpg" already exists.` once two products shipped
     * an image called "1.jpg".
     */
    public function testMediaFileNamesAreUnique(): void
    {
        $fileNames = [];
        foreach (MediaProvider::getMedia() as $media) {
            $fileNames[] = $media['file'];
        }

        $duplicates = \array_keys(\array_filter(
            \array_count_values($fileNames),
            static fn(int $count): bool => $count > 1,
        ));

        static::assertSame(
            [],
            $duplicates,
            'Media file names have to be unique, but these are used more than once: ' . \implode(', ', $duplicates),
        );
    }

    public function testEveryMediaEntryHasAFile(): void
    {
        $directory = __DIR__ . '/../src/Resources/media';

        foreach (MediaProvider::getMedia() as $media) {
            static::assertFileExists($directory . '/' . $media['id'] . '/' . $media['file']);
        }
    }

    public function testEveryProductReferencesShippedImages(): void
    {
        /** @var array{products: list<array{productNumber: string, media: list<string>}>} $resource */
        $resource = ResourceLoader::load('products.json');

        $mediaIds = [];
        foreach (MediaProvider::getMedia() as $media) {
            $mediaIds[] = $media['id'];
        }

        foreach ($resource['products'] as $product) {
            static::assertGreaterThanOrEqual(
                self::MIN_IMAGES_PER_PRODUCT,
                \count($product['media']),
                \sprintf('Product %s should reference at least %d images', $product['productNumber'], self::MIN_IMAGES_PER_PRODUCT),
            );

            foreach ($product['media'] as $mediaId) {
                static::assertContains(
                    $mediaId,
                    $mediaIds,
                    \sprintf('Product %s references unknown media %s', $product['productNumber'], $mediaId),
                );
            }
        }
    }
}
