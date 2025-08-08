<?php
/**
 * Site URL Rules
 *
 * You can define custom site URL rules here, which Craft will check in addition
 * to routes defined in Settings → Routes.
 *
 * Read about Craft’s routing behavior (and this file’s structure), here:
 * @link https://craftcms.com/docs/5.x/system/routing.html
 */

return [
    // Member routes
    'drinks'   => ['template' => 'drinks/index'],
    'teams'           => 'teams/index',
    'account'           => 'account/index',

    // Admin routes
    'drinks/admin'   => ['template' => 'drinks/admin'],
    'teams/admin'    => ['template' => 'teams/admin'],
    'accounts/admin'   => ['template' => 'accounts/admin'],

    // Auth routes (optional if you're customizing login)
'login' => ['template' => 'auth/login'],
];