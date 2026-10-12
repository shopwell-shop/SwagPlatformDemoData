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

#[Package('fundamentals@after-sales')]
final class ResourceLoader
{
    /**
     * Loads a demo data JSON resource shipped with the plugin.
     *
     * @return array<string, mixed>
     */
    public static function load(string $file): array
    {
        $path = __DIR__ . '/../Resources/demo/' . $file;
        $content = \file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException(\sprintf('Demo data resource "%s" could not be read.', $file));
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf('Demo data resource "%s" is not valid JSON: %s', $file, $e->getMessage()), 0, $e);
        }

        return $decoded;
    }
}
