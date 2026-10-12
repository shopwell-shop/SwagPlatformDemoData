<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\DataProvider;

use Shopwell\Core\Framework\Api\Controller\SyncController;
use Shopwell\Core\Framework\Context;
use Shopwell\Core\Framework\Log\Package;

#[Package('fundamentals@after-sales')]
abstract class DemoDataProvider
{
    abstract public function getAction(): string;

    abstract public function getEntity(): string;

    /**
     * @return list<array<string, mixed>>
     */
    abstract public function getPayload(): array;

    /**
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => 0,
            'delete' => 0,
        ];
    }

    /**
     * The sync action used during the delete sequence.
     *
     * Providers that do not own their entities but only modify existing platform
     * data return "upsert" here, so the delete sequence reverses that change
     * instead of removing rows that belong to the shop.
     */
    public function getDeleteAction(): string
    {
        return SyncController::ACTION_DELETE;
    }

    /**
     * The payload used during the delete sequence, defaults to the create payload.
     *
     * @return list<array<string, mixed>>
     */
    public function getDeletePayload(): array
    {
        return $this->getPayload();
    }

    public function finalize(Context $context): void {}

    /**
     * Called after a provider ran its delete sequence, e.g. to remove files
     * that the platform keeps on the filesystem when the rows are gone.
     */
    public function finalizeDelete(Context $context): void {}
}
