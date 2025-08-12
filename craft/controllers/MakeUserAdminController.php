<?php

namespace craft\console\controllers;

use craft\elements\User;
use Craft;
use yii\console\Controller;

class MakeUserAdminController extends Controller
{
    public function actionIndex(): int
    {
        $user = User::find()->one();

        if (!$user) {
            $this->stderr("⚠️  No user found.\n");
            return 1;
        }

        $user->admin = true;

        if (Craft::$app->elements->saveElement($user)) {
            $this->stdout("✅ User '{$user->username}' promoted to admin.\n");
            return 0;
        } else {
            $this->stderr("❌ Failed to save user.\n");
            return 1;
        }
    }
}
