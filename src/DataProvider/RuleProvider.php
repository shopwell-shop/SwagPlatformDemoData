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

#[Package('fundamentals@after-sales')]
class RuleProvider extends DemoDataProvider
{
    /**
     * Used as availability rule of every demo shipping method.
     */
    public const CART_AMOUNT_RULE_ID = 'e1378db7808a478f919e0d740d5d6c1a';

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getAction(): string
    {
        return 'upsert';
    }

    public function getEntity(): string
    {
        return 'rule';
    }

    public function getPayload(): array
    {
        $chinaId = $this->getCountryIdByIsoCode('CN');

        return [
            [
                'id' => '28caae75a5624f0d985abd0eb32aa160',
                'name' => '所有标准客户组的客户',
                'priority' => 1,
                'conditions' => [
                    [
                        'type' => 'orContainer',
                        'children' => [
                            [
                                'type' => 'andContainer',
                                'children' => [
                                    [
                                        'type' => 'customerCustomerGroup',
                                        'value' => [
                                            'operator' => '=',
                                            'customerGroupIds' => ['cfbd5018d38d41d8adca10d94fc8bdd6'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => '3858957016644de4ae48c0500bf3ccc8',
                'name' => '购物车金额大于等于 0（支付方式）',
                'priority' => 100,
                'conditions' => $this->cartAmountCondition(),
            ],
            [
                'id' => 'a62e1f6a1a0b4519af401b6270a37149',
                'name' => '中国大陆客户',
                'priority' => 100,
                'conditions' => [
                    [
                        'type' => 'orContainer',
                        'children' => [
                            [
                                'type' => 'andContainer',
                                'children' => [
                                    [
                                        'type' => 'customerBillingCountry',
                                        'value' => [
                                            'operator' => '=',
                                            'countryIds' => [$chinaId],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => self::CART_AMOUNT_RULE_ID,
                'name' => '购物车金额大于等于 0',
                'priority' => 100,
                'conditions' => $this->cartAmountCondition(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cartAmountCondition(): array
    {
        return [
            [
                'type' => 'orContainer',
                'children' => [
                    [
                        'type' => 'andContainer',
                        'children' => [
                            [
                                'type' => 'cartCartAmount',
                                'value' => [
                                    'operator' => '>=',
                                    'amount' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getCountryIdByIsoCode(string $iso): string
    {
        $result = $this->connection->fetchOne('
            SELECT LOWER(HEX(`id`))
            FROM `country`
            WHERE `iso` = :iso;
        ', ['iso' => $iso]);

        if (!$result) {
            throw new \RuntimeException('No country for iso code "' . $iso . '" found, please make sure that basic data is available by running the migrations.');
        }

        return (string) $result;
    }
}
