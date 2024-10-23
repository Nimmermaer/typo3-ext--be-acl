<?php

use JBartels\BeAcl\Hook\DataHandlerHook;
use TYPO3\CMS\Beuser\Controller\PermissionController;
use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

if (!defined('TYPO3')) {
    die('Access denied.');
}

call_user_func(function () {
    $extensionConfiguration = GeneralUtility::makeInstance(
        ExtensionConfiguration::class
    )->get('be_acl');

    $isRedisEnabled = extension_loaded('redis') && $extensionConfiguration['enableRedis'];

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_userauthgroup.php']['calcPerms'][] =
        \JBartels\BeAcl\Utility\UserAuthGroup::class . '->calcPerms';
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_userauthgroup.php']['getPagePermsClause'][] =
        \JBartels\BeAcl\Utility\UserAuthGroup::class . '->getPagePermsClause';

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][PermissionController::class] = [
        'className' => \JBartels\BeAcl\Controller\PermissionController::class,
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
        DataHandlerHook::class;
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] =
        DataHandlerHook::class;

    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_be_acl_timestamp']['frontend'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_be_acl_timestamp']['frontend'] =
            VariableFrontend::class;
    }
    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_be_acl_timestamp']['backend'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_be_acl_timestamp']['backend'] = $isRedisEnabled
            ? RedisBackend::class
            : SimpleFileBackend::class;
    }

    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_be_acl_permissions']['frontend'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_be_acl_permissions']['frontend'] =
            VariableFrontend::class;
    }
    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_be_acl_permissions']['backend'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_be_acl_permissions']['backend'] = $isRedisEnabled
            ? RedisBackend::class
            : SimpleFileBackend::class;
    }
});
