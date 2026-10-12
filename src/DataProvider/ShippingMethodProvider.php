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
use Shopwell\Core\Framework\Api\Controller\SyncController;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Uuid\Uuid;

/**
 * Assigns the demo rule "cart amount >= 0" to the shop's shipping methods.
 *
 * The shipping methods themselves belong to the shop, so they are updated on
 * activation and restored on deactivation - they are never deleted.
 */
#[Package('fundamentals@after-sales')]
class ShippingMethodProvider extends DemoDataProvider
{
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
        return 'shipping_method';
    }

    /**
     * @return array<string, int>
     */
    public function getStages(): array
    {
        return [
            'create' => 0,
            // Runs before every other provider: the demo rules can only be deleted
            // once the shipping methods no longer reference them.
            'delete' => 2,
        ];
    }

    public function getDeleteAction(): string
    {
        return SyncController::ACTION_UPSERT;
    }

    public function getDeletePayload(): array
    {
        $defaultRuleId = $this->getDefaultRuleId();
        if ($defaultRuleId === null) {
            return [];
        }

        $payload = [];
        foreach ($this->getShippingMethodIdsUsingDemoRule() as $shippingMethodId) {
            $payload[] = [
                'id' => $shippingMethodId,
                'availabilityRuleId' => $defaultRuleId,
            ];
        }

        return $payload;
    }

    public function getPayload(): array
    {
        $payload = [];
        foreach ($this->getShippingMethodIds() as $shippingMethodId) {
            $payload[] = [
                'id' => $shippingMethodId,
                'availabilityRuleId' => RuleProvider::CART_AMOUNT_RULE_ID,
            ];
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function getShippingMethodIds(): array
    {
        return $this->connection->fetchFirstColumn('
            SELECT LOWER(HEX(`id`))
            FROM `shipping_method`;
        ');
    }

    /**
     * @return list<string>
     */
    private function getShippingMethodIdsUsingDemoRule(): array
    {
        return $this->connection->fetchFirstColumn('
            SELECT LOWER(HEX(`id`))
            FROM `shipping_method`
            WHERE `availability_rule_id` = :ruleId;
        ', ['ruleId' => Uuid::fromHexToBytes(RuleProvider::CART_AMOUNT_RULE_ID)]);
    }

    /**
     * The platform's "Always valid (Default)" rule, identified by its condition
     * type so the lookup does not depend on the shop's language.
     */
    private function getDefaultRuleId(): ?string
    {
        $ruleId = $this->connection->fetchOne('
            SELECT LOWER(HEX(`rule`.`id`))
            FROM `rule`
            INNER JOIN `rule_condition` ON `rule_condition`.`rule_id` = `rule`.`id`
            WHERE `rule_condition`.`type` = :type
            LIMIT 1;
        ', ['type' => 'alwaysValid']);

        return $ruleId === false ? null : (string) $ruleId;
    }
}
