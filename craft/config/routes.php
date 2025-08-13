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

// config/routes.php
return [
  // pages
  'drinks'  => ['template' => 'drinks/index'],
  'teams'   => 'teams/index',
  'account' => 'account/index',
  'tabs'    => ['template' => 'tabs/index'],

  // actions to your site controllers
  'tab/add'   => ['route' => 'tabs/add'],
  'tab/close' => ['route' => 'tabs/close'],

  // admin pages
  'drinks/admin'   => ['template' => 'drinks/admin'],
  'teams/admin'    => ['template' => 'teams/admin'],
  'accounts/admin' => ['template' => 'accounts/admin'],
  'tabs/admin'     => ['template' => 'tabs/admin'],

  'login' => ['template' => 'auth/login'],

  // 🔽 payment endpoints (NO module prefix anymore)
  'tab/pay'     => 'pay/create',   // POST
  'tab/webhook' => 'pay/webhook',  // POST (Mollie -> your site)
  'tab/return'  => 'pay/return',   // GET

];

