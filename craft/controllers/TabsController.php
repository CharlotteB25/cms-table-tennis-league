<?php
namespace app\controllers;

use Craft;
use craft\web\Controller;
use craft\elements\Entry;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

class TabsController extends Controller
{
    protected array|int|bool $allowAnonymous = false; // login required

    public function actionAdd()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            throw new ForbiddenHttpException('Login required');
        }

        $r = Craft::$app->getRequest();
        $drinkId = (int)$r->getRequiredBodyParam('drinkId');
        $qty     = max(1, (int)$r->getBodyParam('qty', 1));

        // 1) Find the drink
        $drink = Entry::find()->section('drinks')->id($drinkId)->one();
        if (!$drink) {
            throw new BadRequestHttpException('Drink not found');
        }

        // 2) Drink price (Money\Money expected)
        $priceMoney = $drink->price ?? null;
        if (!$priceMoney || !is_object($priceMoney) || !method_exists($priceMoney, 'getAmount')) {
            throw new BadRequestHttpException('Price missing or invalid on drink');
        }

        // 3) Find/create open tab
        $openTab = Entry::find()
            ->section('tabs')
            ->relatedTo(['field' => 'tabOwner', 'targetElement' => $user])
            ->tabStatus('open')
            ->one();

        if (!$openTab) {
            // Use your real IDs
            $sectionId   = 7;
            $entryTypeId = 5;

            $openTab = new Entry();
            $openTab->sectionId = $sectionId;
            $openTab->typeId    = $entryTypeId;
            $openTab->siteId    = Craft::$app->getSites()->getCurrentSite()->id;
            $openTab->enabled   = true;
            $openTab->title     = 'Tab for ' . $user->friendlyName;
            $openTab->setFieldValue('tabOwner', [$user->id]);
            $openTab->setFieldValue('tabStatus', 'open');
            $openTab->setFieldValue('items', []);

            if (!Craft::$app->getElements()->saveElement($openTab)) {
                return $this->asJson(['success' => false, 'errors' => $openTab->getErrors()]);
            }
        }

        // 4) Merge with existing line item if same drink
        $existingBlocks = $openTab->getFieldValue('items')->all();
        foreach ($existingBlocks as $block) {
            $existingDrink = $block->getFieldValue('drink')->one();
            if ($existingDrink && (int)$existingDrink->id === (int)$drink->id) {
                $block->setFieldValue('qty', (int)$block->getFieldValue('qty') + $qty);
                // Always overwrite stored price with current drink price (Money)
                $block->setFieldValue('price', $priceMoney);
                if (!Craft::$app->getElements()->saveElement($block)) {
                    return $this->asJson(['success' => false, 'errors' => $block->getErrors()]);
                }
                return $this->asJson(['success' => true, 'merged' => true]);
            }
        }

        // 5) Create a new block (use block-type handle EXACTLY as defined)
        $newBlock = [
            'type'    => 'lineitem',       // your Matrix block type handle
            'enabled' => true,
            'title'   => $drink->title,
            'fields'  => [
                'drink' => [$drink->id],
                'qty'   => $qty,
                'price' => $priceMoney,     // store Money\Money value
            ],
        ];

        // Preserve existing + add new
        $blocksData = [];
        foreach ($existingBlocks as $b) {
            $blocksData[] = ['id' => (int)$b->id];
        }
        $blocksData[] = $newBlock;

        $openTab->setFieldValue('items', $blocksData);

        if (!Craft::$app->getElements()->saveElement($openTab)) {
            return $this->asJson(['success' => false, 'errors' => $openTab->getErrors()]);
        }

        return $this->asJson(['success' => true, 'merged' => false]);
    }

    public function actionClose()
    {
        $this->requirePostRequest();

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            throw new ForbiddenHttpException('Login required');
        }

        $tabId = (int)Craft::$app->getRequest()->getRequiredBodyParam('tabId');
        $tab   = Entry::find()->section('tabs')->id($tabId)->one();
        if (!$tab) {
            throw new BadRequestHttpException('Tab not found');
        }

        $ownerId = (int)($tab->getFieldValue('tabOwner')->one()->id ?? 0);
        $isOwner = $ownerId === (int)$user->id;
        if (!$isOwner && !$user->admin) {
            throw new ForbiddenHttpException('Not allowed');
        }

        $tab->setFieldValue('tabStatus', 'paid');
        $tab->title = preg_replace('/^Tab for /', 'Paid tab for ', $tab->title);

        if (!Craft::$app->getElements()->saveElement($tab)) {
            return $this->asJson(['success' => false, 'errors' => $tab->getErrors()]);
        }

        return $this->asJson(['success' => true]);
    }
}
