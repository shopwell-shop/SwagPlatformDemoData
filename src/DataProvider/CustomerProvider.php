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
use Shopwell\Core\Framework\Uuid\Uuid;
use Swag\PlatformDemoData\Helper\ResourceLoader;

#[Package('fundamentals@after-sales')]
class CustomerProvider extends DemoDataProvider
{
    /**
     * Every demo customer can log in with the password "shopwell123".
     */
    public const DEMO_PASSWORD_HASH = '$2y$12$nB.LG.QpRyFY6SA8nqsU7.7Kqr4tBwSpkYYk5x3IM3yJp/Hu1fT/6';

    private Connection $connection;

    /**
     * @var EntityRepository<CategoryCollection>
     */
    private EntityRepository $categoryRepository;

    /**
     * @param EntityRepository<CategoryCollection> $categoryRepository
     */
    public function __construct(Connection $connection, EntityRepository $categoryRepository)
    {
        $this->connection = $connection;
        $this->categoryRepository = $categoryRepository;
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'customer';
    }

    /**
     * @return list<string> ids of all demo customers, in the order they are defined
     */
    public static function getDemoCustomerIds(): array
    {
        return array_map(
            static fn(array $customer) => $customer['id'],
            self::getCustomers(),
        );
    }

    /**
     * @return array<string, mixed> the demo customer with the given id
     */
    public static function getDemoCustomer(string $customerId): array
    {
        foreach (self::getCustomers() as $customer) {
            if ($customer['id'] === $customerId) {
                return $customer;
            }
        }

        throw new \RuntimeException(\sprintf('Demo customer "%s" not found.', $customerId));
    }

    public function getPayload(): array
    {
        $salutationIds = $this->getSalutationIds();
        $paymentMethodId = $this->getPaymentMethodId();
        $countryId = $this->getCountryIdByIsoCode('CN');
        $salesChannelId = $this->getStorefrontSalesChannelId();

        $payload = [];
        foreach (self::getCustomers() as $index => $customer) {
            /** @var array<string, mixed> $address */
            $address = $customer['address'];
            $salutationId = $salutationIds[$customer['salutationKey']] ?? $salutationIds['not_specified'];

            $payload[] = [
                'id' => $customer['id'],
                'defaultPaymentMethodId' => $paymentMethodId,
                'salutationId' => $salutationId,
                'salesChannelId' => $salesChannelId,
                'customerNumber' => $customer['customerNumber'],
                // The platform uses a single "name" field for customer profiles.
                'name' => $customer['name'],
                'password' => self::DEMO_PASSWORD_HASH,
                'email' => $customer['email'],
                'active' => $customer['active'],
                'guest' => $customer['guest'],
                'newsletter' => $customer['newsletter'],
                'birthday' => $customer['birthday'],
                'accountType' => 'private',
                'groupId' => 'cfbd5018d38d41d8adca10d94fc8bdd6',
                'tags' => array_map(
                    static fn(int $tagIndex) => ['id' => TagProvider::tagId($tagIndex)],
                    self::tagIndexesFor($index),
                ),
                'defaultShippingAddress' => [
                    'id' => $address['id'],
                    'countryId' => $countryId,
                    'salutationId' => $salutationId,
                    'name' => $address['name'],
                    'street' => $address['street'],
                    'additionalAddressLine1' => $address['additionalAddressLine1'],
                    'city' => $address['city'],
                    'zipcode' => $address['zipcode'],
                    'phoneNumber' => $address['phoneNumber'],
                ],
                'defaultBillingAddressId' => $address['id'],
            ];
        }

        return $payload;
    }

    /**
     * Two tags per customer, rotated deterministically over the demo tags.
     *
     * @return list<int>
     */
    private static function tagIndexesFor(int $customerIndex): array
    {
        $tagCount = \count(TagProvider::getDemoTagIds());

        return [
            $customerIndex % $tagCount,
            ($customerIndex + 5) % $tagCount,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function getCustomers(): array
    {
        /** @var array<string, mixed> $resource */
        $resource = ResourceLoader::load('customers.json');

        /** @var list<array<string, mixed>> $customers */
        $customers = $resource['customers'];

        return $customers;
    }

    /**
     * @return array<string, string>
     */
    private function getSalutationIds(): array
    {
        /** @var list<array<string, string>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT `salutation_key`, LOWER(HEX(`id`)) AS `salutation_id` FROM `salutation`',
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['salutation_key']] = $row['salutation_id'];
        }

        if (!isset($ids['not_specified'])) {
            throw new \RuntimeException('No salutation found, please make sure that basic data is available by running the migrations.');
        }

        return $ids;
    }

    private function getPaymentMethodId(): string
    {
        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(`id`))
            FROM `payment_method`
            WHERE `active` = 1;
        ');

        if (!$result) {
            throw new \RuntimeException('No active payment method found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
    }

    private function getCountryIdByIsoCode(string $iso): string
    {
        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(`id`))
            FROM `country`
            WHERE `iso` = :iso
            LIMIT 1;
        ', ['iso' => $iso]);

        if (!$result) {
            throw new \RuntimeException('No country for iso code "' . $iso . '" found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
    }

    private function getStorefrontSalesChannelId(): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', null));

        $rootCategoryId = $this->categoryRepository->searchIds($criteria, new Context(new SystemSource()))->firstId();
        if ($rootCategoryId === null) {
            throw new \RuntimeException('Root category not found');
        }

        $navigationSalesChannelId = $this->connection->fetchOne('
            SELECT `id`
            FROM `sales_channel`
            WHERE `navigation_category_id` = :rootCategoryId
            LIMIT 1;
        ', ['rootCategoryId' => Uuid::fromHexToBytes($rootCategoryId)]);

        if (!$navigationSalesChannelId) {
            throw new \RuntimeException('Sales channel not found');
        }

        return Uuid::fromBytesToHex((string) $navigationSalesChannelId);
    }
}
