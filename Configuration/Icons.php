<?php


use FriendsOfTYPO3\FontawesomeProvider\Imaging\IconProvider\FontawesomeIconProvider;
use TYPO3\CMS\Core\Imaging\IconProvider\BitmapIconProvider;

return [
    'tx_beacl-object-info' => [
        'provider' => FontawesomeIconProvider::class,
        'name' => 'info',
    ],
    'tx_beacl-acl' => [
        'provider' => BitmapIconProvider::class,
        'source' => 'EXT:be_acl/Resources/Public/Icons/icon_tx_beacl_acl.gif',
    ],
];
