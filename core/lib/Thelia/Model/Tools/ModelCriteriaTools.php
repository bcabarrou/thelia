<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Thelia\Model\Tools;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Exception\PropelException;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * Tools for i18n joins on criteria.
 */
class ModelCriteriaTools
{
    /**
     * Constant for backend context.
     * @var string
     */
    protected static $BACKEND_CONTEXT = 'backend';
    /**
     * Constant for frontend context.
     * @var string
     */
    protected static $FRONTEND_CONTEXT = 'frontend';

    /**
     * Add i18n joins on a criteria.
     *
     * @param bool $backendContext Whether th context is backend.
     * @param int|string $requestedLangId A language numerical id or a locale code.
     * @param ModelCriteria $criteria Criteria to work on.
     * @param string $currentLocaleCode The current (i.e. default) locale code.
     * @param string[] $columnNames Names of the columns for which to retrieve localizations.
     * @param string $foreignI18nTablePrefix Name of the foreign i18n table, without the '_i18n' prefix.
     *     Defaults to the table of the criteria.
     * @param string $localJoinKey Name of the column to join from on the local table.
     * @param bool $forceReturn Return a result even if no translation exists for this locale.
     * @param string $localTableName Name of the table to join from. Defaults to the table of the criteria.
     *
     * @return string The requested locale code.
     */
    public static function getI18n(
        $backendContext,
        $requestedLangId,
        ModelCriteria &$criteria,
        $currentLocaleCode,
        $columnNames,
        $foreignI18nTablePrefix,
        $localJoinKey,
        $forceReturn = false,
        $localTableName = null
    ) {
        if ($requestedLangId !== null) {
            // If a lang has been requested, find the related Lang object, and get the locale
            $langSearch = LangQuery::create()->findByIdOrLocale($requestedLangId);

            if ($langSearch === null) {
                throw new \InvalidArgumentException(
                    "Incorrect lang argument given : lang {$requestedLangId} not found"
                );
            }

            $requestedLocaleCode = $langSearch->getLocale();
        } else {
            // Use the currently defined locale
            $requestedLocaleCode = $currentLocaleCode;
        }

        // Call the proper method depending on the context: front or back
        if ($backendContext) {
            self::getBackEndI18n(
                $criteria,
                $requestedLocaleCode,
                $columnNames,
                $foreignI18nTablePrefix,
                $localJoinKey,
                $localTableName
            );
        } else {
            self::getFrontEndI18n(
                $criteria,
                $requestedLocaleCode,
                $columnNames,
                $foreignI18nTablePrefix,
                $localJoinKey,
                $forceReturn,
                $localTableName
            );
        }

        return $requestedLocaleCode;
    }

    /**
     * Add i18n joins on a criteria, in a frontend context.
     *
     * @param ModelCriteria $criteria Criteria to work on.
     * @param string $requestedLocaleCode Code of the requested locale.
     * @param string[] $columnNames Names of the columns for which to retrieve localizations.
     * @param string $foreignI18nTablePrefix Name of the foreign i18n table, without the '_i18n' prefix.
     *     Defaults to the table of the criteria.
     * @param string $localJoinKey Name of the column to join from on the local table.
     * @param bool $forceReturn Return a result even if no translation exists for this locale.
     * @param string $localTableName Name of the table to join from. Defaults to the table of the criteria.
     */
    public static function getFrontEndI18n(
        ModelCriteria &$criteria,
        $requestedLocaleCode,
        $columnNames,
        $foreignI18nTablePrefix,
        $localJoinKey,
        $forceReturn = false,
        $localTableName = null
    ) {
        static::doGetI18n(
            static::$FRONTEND_CONTEXT,
            $criteria,
            $requestedLocaleCode,
            $columnNames,
            $foreignI18nTablePrefix,
            $localJoinKey,
            $forceReturn,
            $localTableName
        );
    }

    /**
     * Add i18n joins on a criteria, in a backend context.
     *
     * @param ModelCriteria $criteria Criteria to work on.
     * @param string $requestedLocaleCode Code of the requested locale.
     * @param string[] $columnNames Names of the columns for which to retrieve localizations.
     * @param string $foreignI18nTablePrefix Name of the foreign i18n table, without the '_i18n' prefix.
     *     Defaults to the table of the criteria.
     * @param string $localJoinKey Name of the column to join from on the local table.
     * @param string $localTableName Name of the table to join from. Defaults to the table of the criteria.
     */
    public static function getBackEndI18n(
        ModelCriteria &$criteria,
        $requestedLocaleCode,
        $columnNames = array('TITLE', 'CHAPO', 'DESCRIPTION', 'POSTSCRIPTUM'),
        $foreignI18nTablePrefix = null,
        $localJoinKey = 'ID',
        $localTableName = null
    ) {
        static::doGetI18n(
            static::$BACKEND_CONTEXT,
            $criteria,
            $requestedLocaleCode,
            $columnNames,
            $foreignI18nTablePrefix,
            $localJoinKey,
            false,
            $localTableName
        );
    }

    /**
     * Add i18n joins on a criteria.
     *
     * @param string $context Context. One of (static::BACKEND_CONTEXT|static::FRONTEND_CONTEXT).
     * @param ModelCriteria $criteria Criteria to work on.
     * @param string $requestedLocaleCode Code of the requested locale.
     * @param string[] $columnNames Names of the columns for which to retrieve localizations.
     * @param string $foreignI18nTablePrefix Name of the foreign i18n table, without the '_i18n' prefix.
     *     Defaults to the table of the criteria.
     * @param string $localJoinKey Name of the column to join from on the local table.
     * @param bool $forceReturn Return a result even if no translation exists for this locale.
     * @param string $localTableName Name of the table to join from. Defaults to the table of the criteria.
     * @throws PropelException
     */
    protected static function doGetI18n(
        $context,
        ModelCriteria &$criteria,
        $requestedLocaleCode,
        $columnNames,
        $foreignI18nTablePrefix = null,
        $localJoinKey = null,
        $forceReturn = false,
        $localTableName = null
    ) {
        if (empty($columnNames)) {
            return;
        }

        if ($localTableName === null) {
            $localTableName = $criteria->getTableMap()->getName();
        }

        if ($foreignI18nTablePrefix === null) {
            $foreignI18nTablePrefix = $criteria->getTableMap()->getName();
            $joinAliasPrefix = "";
        } else {
            $joinAliasPrefix = "{$foreignI18nTablePrefix}_";
        }
        $foreignI18nTableName = "{$foreignI18nTablePrefix}_i18n";

        $langWithoutTranslationBehavior = ConfigQuery::getDefaultLangWhenNoTranslationAvailable();

        $requestedLocaleJoinAlias = "{$joinAliasPrefix}requested_locale_i18n";
        $defaultLocaleJoinAlias = "{$joinAliasPrefix}default_locale_i18n";

        $requestedLocaleJoin = new Join();
        $requestedLocaleJoin->addExplicitCondition(
            $localTableName,
            $localJoinKey,
            null,
            $foreignI18nTableName,
            "ID",
            $requestedLocaleJoinAlias
        );

        if ($context === static::$BACKEND_CONTEXT) {
            $requestedLocaleJoin->setJoinType(Criteria::LEFT_JOIN);
        } elseif (
            $context === static::$FRONTEND_CONTEXT
            && $langWithoutTranslationBehavior == Lang::STRICTLY_USE_REQUESTED_LANGUAGE
            && $forceReturn === false
        ) {
            $requestedLocaleJoin->setJoinType(Criteria::INNER_JOIN);
        } else {
            $requestedLocaleJoin->setJoinType(Criteria::LEFT_JOIN);
        }

        $criteria
            ->addJoinObject($requestedLocaleJoin, $requestedLocaleJoinAlias)
            ->addJoinCondition(
                $requestedLocaleJoinAlias,
                "`{$requestedLocaleJoinAlias}`.LOCALE = ?",
                $requestedLocaleCode,
                null,
                \PDO::PARAM_STR
            );

        $criteria->withColumn(
            "NOT ISNULL(`{$requestedLocaleJoinAlias}`.`ID`)",
            "{$joinAliasPrefix}IS_TRANSLATED"
        );

        if ($context === static::$FRONTEND_CONTEXT) {
            $defaultLocaleJoin = new Join();
            $defaultLocaleJoin->addExplicitCondition(
                $localTableName,
                $localJoinKey,
                null,
                $foreignI18nTableName,
                "ID",
                $defaultLocaleJoinAlias
            );
            $defaultLocaleJoin->setJoinType(Criteria::LEFT_JOIN);

            if ($langWithoutTranslationBehavior == Lang::STRICTLY_USE_REQUESTED_LANGUAGE) {
                $defaultLocale = $requestedLocaleCode;
            } else {
                $defaultLocale = Lang::getDefaultLanguage()->getLocale();
            }

            $criteria
                ->addJoinObject($defaultLocaleJoin, $defaultLocaleJoinAlias)
                ->addJoinCondition(
                    $defaultLocaleJoinAlias,
                    "`{$defaultLocaleJoinAlias}`.LOCALE = ?",
                    $defaultLocale,
                    null,
                    \PDO::PARAM_STR
                );
        }

        if (
            $context === static::$BACKEND_CONTEXT
            || $langWithoutTranslationBehavior == Lang::STRICTLY_USE_REQUESTED_LANGUAGE
        ) {
            foreach ($columnNames as $columnName) {
                $criteria
                    ->withColumn(
                        "`{$requestedLocaleJoinAlias}`.`{$columnName}`",
                        "{$joinAliasPrefix}i18n_{$columnName}"
                    );
            }
        } else {
            if ($forceReturn === false) {
                $criteria
                    ->where("NOT ISNULL(`{$requestedLocaleJoinAlias}`.ID)")
                    ->_or()
                    ->where("NOT ISNULL(`{$defaultLocaleJoinAlias}`.ID)");
            }

            foreach ($columnNames as $columnName) {
                $criteria
                    ->withColumn(
                        "CASE WHEN NOT ISNULL(`{$requestedLocaleJoinAlias}`.ID)"
                        . " THEN `{$requestedLocaleJoinAlias}`.`{$columnName}`"
                        . " ELSE `{$defaultLocaleJoinAlias}`.`{$columnName}`"
                        . " END",
                        "{$joinAliasPrefix}i18n_{$columnName}"
                    );
            }
        }
    }
}
