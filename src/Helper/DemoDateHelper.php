<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\Helper;

use Psr\Clock\ClockInterface;
use Shopwell\Core\Framework\Log\Package;
use Shopwell\Core\Framework\Util\Hasher;

/**
 * Hands out the timestamps of the demo data.
 *
 * Everything the plugin writes is dated inside the last month, because a demo
 * shop is looked at right after it has been installed and stale timestamps make
 * the statistics and the order list look dead. The current day and the day
 * before always carry records: every slot cycle the first entry is dated today
 * and the second one yesterday, so a shop that was just set up never opens with
 * an empty "today" or "yesterday" - as long as a sequence has at least two
 * entries. The remaining entries are spread over the rest of the month, with
 * the current day keeping the biggest share.
 *
 * The timestamp of an entry is derived from its position and a hash of the
 * seed, never from a random generator, so it is deterministic: importing the
 * demo data twice produces the same dates.
 */
#[Package('fundamentals@after-sales')]
class DemoDateHelper
{
    /**
     * The length of the slot cycle. Every cycle repeats the pattern "today,
     * yesterday, spread".
     */
    private const SLOT_CYCLE = 5;

    /**
     * Position inside the cycle that is dated today.
     */
    private const TODAY_SLOT = 0;

    /**
     * Position inside the cycle that is dated yesterday.
     */
    private const YESTERDAY_SLOT = 1;

    /**
     * The oldest entry is dated this many days ago. The days between today and
     * yesterday are reserved for their own slots.
     */
    private const SPREAD_DAYS = 29;

    public static function at(ClockInterface $clock, string $seed, int $index): \DateTimeImmutable
    {
        $now = $clock->now();
        $slot = $index % self::SLOT_CYCLE;

        if ($slot === self::TODAY_SLOT) {
            return self::insideToday($now, $index);
        }

        if ($slot === self::YESTERDAY_SLOT) {
            return self::atTimeOfDay($now->modify('-1 day'), $index);
        }

        // A day between two days ago and the end of the month. The hash keeps
        // the spread stable and lets different sequences spread differently.
        $bucket = (int) \hexdec(\substr(Hasher::hash('shopwell-demo-data:date:' . $seed . ':' . $index, 'md5'), 0, 8)) % 100;
        $daysAgo = 2 + (int) \floor($bucket / 100 * (self::SPREAD_DAYS - 1));

        return self::atTimeOfDay($now->modify(\sprintf('-%d days', $daysAgo)), $index);
    }

    /**
     * A timestamp inside the last month, shifted by the given amount of minutes
     * so that follow up events of a record (a payment after an order, a refund
     * after a payment) keep their order. The result never lies in the future,
     * so a follow up event of a record of today still falls on today.
     */
    public static function minutesAfter(ClockInterface $clock, \DateTimeImmutable $date, int $minutes): \DateTimeImmutable
    {
        $result = $date->modify(\sprintf('+%d minutes', $minutes));
        $now = $clock->now();

        if ($result > $now) {
            return $now->modify(\sprintf('-%d minutes', \max(1, $minutes % 30)));
        }

        return $result;
    }

    /**
     * A moment of the current day that has already passed, so that nothing the
     * plugin writes is dated in the future.
     */
    private static function insideToday(\DateTimeImmutable $now, int $index): \DateTimeImmutable
    {
        $midnight = $now->setTime(0, 0);
        $elapsedMinutes = (int) \floor(($now->getTimestamp() - $midnight->getTimestamp()) / 60);

        if ($elapsedMinutes <= 0) {
            return $now;
        }

        return $midnight->modify(\sprintf('+%d minutes', ($index * 37) % ($elapsedMinutes + 1)));
    }

    /**
     * A fixed moment of the working day of the given date, derived from the
     * position of the entry.
     */
    private static function atTimeOfDay(\DateTimeImmutable $date, int $index): \DateTimeImmutable
    {
        return $date->setTime(9 + ($index * 3) % 10, ($index * 7) % 60, ($index * 13) % 60);
    }
}
