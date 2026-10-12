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
use Shopwell\Core\Content\Category\CategoryCollection;
use Shopwell\Core\Framework\Api\Context\SystemSource;
use Shopwell\Core\Framework\Context;
use Shopwell\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopwell\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopwell\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopwell\Core\Framework\Log\Package;
use Swag\PlatformDemoData\Helper\ResourceLoader;
use Swag\PlatformDemoData\Helper\TranslationHelper;

#[Package('fundamentals@after-sales')]
class CategoryProvider extends DemoDataProvider
{
    /**
     * @var EntityRepository<CategoryCollection>
     */
    private EntityRepository $categoryRepository;

    private Connection $connection;

    private TranslationHelper $translationHelper;

    /**
     * @param EntityRepository<CategoryCollection> $categoryRepository
     */
    public function __construct(EntityRepository $categoryRepository, Connection $connection)
    {
        $this->categoryRepository = $categoryRepository;
        $this->connection = $connection;
        $this->translationHelper = new TranslationHelper($connection);
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'category';
    }

    public function getPayload(): array
    {
        $cmsPageId = $this->getDefaultCmsListingPageId();
        /** @var array<string, mixed> $resource */
        $resource = ResourceLoader::load('categories.json');
        /** @var array<int, array<string, mixed>> $categories */
        $categories = $resource['categories'];

        return [
            [
                // Only the children are written: the shop root category keeps its
                // own name and layout. DemoDataService deletes the children again
                // on deactivation.
                'id' => $this->getRootCategoryId(),
                'children' => $this->mapNodes($categories, $cmsPageId),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function mapNodes(array $nodes, string $cmsPageId): array
    {
        $mapped = [];
        foreach ($nodes as $node) {
            /** @var array<int, array<string, mixed>> $children */
            $children = $node['children'] ?? [];
            $mapped[] = [
                'id' => $node['id'],
                'cmsPageId' => $cmsPageId,
                'active' => true,
                'visible' => true,
                'displayNestedProducts' => true,
                'type' => 'page',
                'name' => $this->translationHelper->adjustTranslations($node['name']),
                'children' => $this->mapNodes($children, $cmsPageId),
            ];
        }

        return $mapped;
    }

    private function getRootCategoryId(): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', null));

        $rootCategoryId = $this->categoryRepository->searchIds($criteria, new Context(new SystemSource()))->firstId();
        if ($rootCategoryId === null) {
            throw new \RuntimeException('Root category not found');
        }

        return $rootCategoryId;
    }

    private function getDefaultCmsListingPageId(): string
    {
        // Looked up by type instead of by name so the query keeps working on shops
        // whose default language is not English (e.g. a zh-CN installation).
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM cms_page WHERE type = :type AND locked = 1 LIMIT 1',
            ['type' => 'product_list'],
        );

        if ($id === false) {
            throw new \RuntimeException('Default CMS listing page not found');
        }

        return (string) $id;
    }
}
