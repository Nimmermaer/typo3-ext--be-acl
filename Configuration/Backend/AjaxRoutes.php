<?php

use JBartels\BeAcl\Controller\PermissionController;

/**
 * Definitions for routes provided by EXT:be_acl
 */
return [
    // Dispatch the permissions actions
    'user_access_permissions' => [
        'path' => '/users/access/permissions',
        'target' => PermissionController::class . '::handleAjaxRequest',
    ],
];
