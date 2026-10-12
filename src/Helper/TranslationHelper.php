<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * (c) Shopwell <info@shopwell.cn>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PlatformDemoData\Helper;

use Doctrine\DBAL\Connection;
use Shopwell\Core\Framework\Log\Package;

#[Package('fundamentals@after-sales')]
class TranslationHelper
{
    // Which language to use if no translation for the wanted language is available
    private const DEFAULT_TRANSLATION_LANGUAGE = 'en-GB';

    private DbHelper $dbHelper;

    public function __construct(Connection $connection)
    {
        $this->dbHelper = new DbHelper($connection);
    }

    /**
     * @param array<string, mixed> $translations
     *
     * @return array<string, mixed>
     */
    public function adjustTranslations(array $translations): array
    {
        $systemLanguageCode = $this->dbHelper->getSystemLanguageCode();

        if (!isset($translations[$systemLanguageCode])) {
            $translations[$systemLanguageCode] = $translations[self::DEFAULT_TRANSLATION_LANGUAGE];
        }

        return $this->clearUnavailableTranslations($translations);
    }

    /**
     * Maps the given locale codes to the language ids that actually exist in
     * the shop. Codes without a matching language are dropped.
     *
     * @param list<string> $codes
     *
     * @return array<string, string>
     */
    public function getAvailableLanguageIds(array $codes): array
    {
        $ids = [];
        foreach ($codes as $code) {
            $languageId = $this->dbHelper->getLanguageId($code);
            if ($languageId) {
                $ids[$code] = $languageId;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $translations
     *
     * @return array<string, mixed>
     */
    private function clearUnavailableTranslations(array $translations): array
    {
        $availableCodes = [];
        foreach ($translations as $code => $value) {
            $languageId = $this->dbHelper->getLanguageId($code);
            if ($languageId) {
                $availableCodes[$code] = $value;
            }
        }

        return $availableCodes;
    }
}
