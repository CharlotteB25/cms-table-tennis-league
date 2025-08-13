<?php
namespace modules\tabs;

use Craft;
use yii\base\Module as BaseModule;

class Module extends BaseModule
{
    public function init(): void
    {
        parent::init();
        // Point controller routes to modules\tabs\controllers\*
        $this->controllerNamespace = __NAMESPACE__ . '\\controllers';
        // Helpful alias (optional)
        Craft::setAlias('@modules/tabs', __DIR__);
    }
}
