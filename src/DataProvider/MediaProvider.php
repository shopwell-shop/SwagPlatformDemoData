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
use League\Flysystem\FilesystemOperator;
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

    private FilesystemOperator $publicFilesystem;

    /**
     * Paths of the demo media files inside the public directory, collected
     * while the delete payload is built and removed afterwards.
     *
     * @var list<string>
     */
    private array $pendingPublicPaths = [];

    public function __construct(Connection $connection, FileSaver $fileSaver, FilesystemOperator $publicFilesystem)
    {
        $this->fileSaver = $fileSaver;
        $this->connection = $connection;
        $this->publicFilesystem = $publicFilesystem;
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

    /**
     * Persists the shipped image files and assigns them to the media entities.
     *
     * The file name comes from media.json and has to be unique across the whole
     * media table: FileSaver::ensureFileNameIsUnique() rejects a second media with
     * the same file name and extension, so a per-directory "1.jpg" naming scheme
     * would abort the import with `A file with the name "1.jpg" already exists.`
     */
    public function finalize(Context $context): void
    {
        foreach (self::getMedia() as $media) {
            $source = \sprintf('%s/../Resources/media/%s/%s', __DIR__, $media['id'], $media['file']);

            if (!\is_file($source)) {
                throw new \RuntimeException(\sprintf('Demo media file "%s" is missing.', $source));
            }

            $this->fileSaver->persistFileToMedia(
                new MediaFile(
                    $source,
                    \mime_content_type($source) ?: 'application/octet-stream',
                    \pathinfo($source, \PATHINFO_EXTENSION),
                    \filesize($source) ?: 0,
                ),
                \pathinfo($media['file'], \PATHINFO_FILENAME),
                $media['id'],
                $context,
            );
        }

        parent::finalize($context);
    }

    /**
     * Collects the public paths of the demo media files before the rows are
     * deleted: deleting the media rows does not remove the files.
     */
    public function getDeletePayload(): array
    {
        $this->pendingPublicPaths = [];

        $rows = $this->connection->fetchAllAssociative('
            SELECT `path`
            FROM `media`
            WHERE `file_name` LIKE :prefix
              AND `path` IS NOT NULL
        ', ['prefix' => 'SW-DEMO-%']);

        foreach ($rows as $row) {
            $this->pendingPublicPaths[] = (string) $row['path'];
        }

        return parent::getDeletePayload();
    }

    /**
     * Removes the shipped image files from the public directory.
     */
    public function finalizeDelete(Context $context): void
    {
        foreach ($this->pendingPublicPaths as $path) {
            // Goes through the public filesystem instead of touching the disk
            // directly: the platform forbids raw filesystem writes in plugins
            // and a remote adapter would not be reachable with unlink() at all.
            if ($this->publicFilesystem->fileExists($path)) {
                $this->publicFilesystem->delete($path);
            }
        }

        $this->pendingPublicPaths = [];
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
