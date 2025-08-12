<?php
namespace modules\app;

use Craft;
use yii\base\Module as BaseModule;

class Module extends BaseModule
{
    public function init(): void
    {
        parent::init();

        // Make sure controllers auto-load from this namespace
        $this->controllerNamespace = 'modules\\app\\controllers';

        // Optional: log we booted
        Craft::info('App module booted', __METHOD__);
    }
}
