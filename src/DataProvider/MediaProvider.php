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
use Shopwell\Core\Content\Media\File\FileSaver;
use Shopwell\Core\Content\Media\File\MediaFile;
use Shopwell\Core\Framework\Context;
use Shopwell\Core\Framework\Log\Package;
use Swag\PlatformDemoData\Helper\ResourceLoader;

#[Package('fundamentals@after-sales')]
class MediaProvider extends DemoDataProvider
{
    private FileSaver $fileSaver;

    private Connection $connection;

    public function __construct(Connection $connection, FileSaver $fileSaver)
    {
        $this->fileSaver = $fileSaver;
        $this->connection = $connection;
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'media';
    }

    public function getPayload(): array
    {
        $productFolder = $this->getDefaultFolderIdForEntity('product');
        $cmsFolder = $this->getDefaultFolderIdForEntity('cms_page');

        $payload = [];
        foreach ($this->getMedia() as $media) {
            $payload[] = [
                'id' => $media['id'],
                'mediaFolderId' => $media['folder'] === 'cms' ? $cmsFolder : $productFolder,
            ];
        }

        return $payload;
    }

    /**
     * @return list<array{id: string, folder: string, file: string}>
     */
    public static function getMedia(): array
    {
        /** @var array<string, mixed> $resource */
        $resource = ResourceLoader::load('media.json');

        /** @var list<array{id: string, folder: string, file: string}> $media */
        $media = $resource['media'];

        return $media;
    }

    /**
     * Returns the id of the single demo media entry that lives in the CMS folder.
     */
    public static function getCmsMediaId(): string
    {
        foreach (self::getMedia() as $media) {
            if ($media['folder'] === 'cms') {
                return $media['id'];
            }
        }

        throw new \RuntimeException('No CMS demo media found in media.json');
    }

    public function finalize(Context $context): void
    {
        $files = \glob(__DIR__ . '/../Resources/media/*/*.jpg');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $this->fileSaver->persistFileToMedia(
                new MediaFile(
                    $file,
                    \mime_content_type($file) ?: 'application/octet-stream',
                    \pathinfo($file, \PATHINFO_EXTENSION),
                    \filesize($file) ?: 0,
                ),
                \pathinfo($file, \PATHINFO_FILENAME),
                \basename(\dirname($file)),
                $context,
            );
        }

        parent::finalize($context);
    }

    private function getDefaultFolderIdForEntity(string $entity): string
    {
        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(`media_folder`.`id`))
            FROM `media_default_folder`
            JOIN `media_folder` ON `media_default_folder`.`id` = `media_folder`.`default_folder_id`
            WHERE `media_default_folder`.`entity` = :entity;
        ', ['entity' => $entity]);

        if (!$result) {
            throw new \RuntimeException('No default folder for entity "' . $entity . '" found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
    }
}
