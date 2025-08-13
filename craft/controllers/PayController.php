<?php
namespace app\controllers;

use Craft;
use craft\web\Controller;
use craft\elements\Entry;
use yii\web\Response;
use Mollie\Api\MollieApiClient;

class PayController extends Controller
{
    protected array|int|bool $allowAnonymous = ['webhook', 'return'];
    public $enableCsrfValidation = false;

    private function mollie(): MollieApiClient {
        $m = new MollieApiClient();
        $m->setApiKey(Craft::parseEnv('$MOLLIE_API_KEY'));
        return $m;
    }

    /** POST /tab/pay */
    public function actionCreate(): Response
    {
        $this->requirePostRequest();

        $tabId = Craft::$app->getRequest()->getBodyParam('tabId');
        /** @var Entry|null $tab */
        $tab = Entry::find()
            ->section('tabs')
            ->id($tabId)
            ->one();

        if (!$tab) {
            return $this->asJson(['ok' => false, 'error' => "Tab #{$tabId} not found"]);
        }

        // Accept "open", "Open", etc. (and be forgiving if handle casing differs)
        $rawStatus = (string)($tab->tabStatus ?? $tab->tabstatus ?? '');
        $status    = strtolower(trim($rawStatus));
        if ($status && $status !== 'open') {
            return $this->asJson(['ok' => false, 'error' => "Tab status is '{$rawStatus}', expected 'open'"]);
        }

        // Compute total (same logic as Twig, with fallbacks)
        $total = 0.0;
        foreach ($tab->items->all() as $b) {
            $qty = (float)($b->qty ?? 0);
            $priceMoney = $b->price ?? ($b->drink->one()->price ?? null);
            if ($priceMoney && method_exists($priceMoney, 'getAmount')) {
                $price = $priceMoney->getAmount() / 100;
            } else {
                $price = (float)($b->price ?? 0);
            }
            $total += $qty * $price;
        }
        $total = round($total, 2);

        if ($total < 0.01) {
            return $this->asJson(['ok' => false, 'error' => 'Total is €0.00. Fill Qty/Price (or Drink price).']);
        }

        $req = Craft::$app->getRequest();
        $baseUrl = rtrim($req->getHostInfo(), '/');

        $mollie = $this->mollie();
        $payment = $mollie->payments->create([
            'amount'      => ['currency' => 'EUR', 'value' => number_format($total, 2, '.', '')],
            'description' => "Bar tab #{$tab->id}",
            'redirectUrl' => "{$baseUrl}/tab/return?tabId={$tab->id}",
            'webhookUrl'  => "{$baseUrl}/tab/webhook",
            'metadata'    => ['tabId' => (string)$tab->id],
        ]);

        $tab->setFieldValue('molliePaymentId', $payment->id);
        $tab->setFieldValue('paidTotal', $total);
        Craft::$app->getElements()->saveElement($tab, false, false);

        return $this->asJson([
            'ok'          => true,
            'checkoutUrl' => $payment->_links->checkout->href ?? null,
            'paymentId'   => $payment->id,
        ]);
    }

    public function actionWebhook(): Response
    {
        $paymentId = Craft::$app->getRequest()->getBodyParam('id');
        if (!$paymentId) return $this->asRaw('missing id');

        $mollie  = $this->mollie();
        $payment = $mollie->payments->get($paymentId);

        $tabId = $payment->metadata->tabId ?? null;
        /** @var Entry|null $tab */
        $tab = $tabId
            ? Entry::find()->section('tabs')->id($tabId)->one()
            : Entry::find()->section('tabs')->molliePaymentId($paymentId)->one();

        if ($tab && $payment->isPaid()) {
            $expected = (float)($tab->paidTotal ?? 0);
            $paid = isset($payment->amount->value) ? (float)$payment->amount->value : 0.0;
            if ($expected <= 0 || abs($expected - $paid) < 0.01) {
                $tab->setFieldValue('tabStatus', 'paid');
                Craft::$app->getElements()->saveElement($tab, false, false);
            }
        }
        return $this->asRaw('ok');
    }

    public function actionReturn(): Response
    {
        $tabId = Craft::$app->getRequest()->getQueryParam('tabId');
        return $this->redirect("/tabs?tabId={$tabId}&return=1");
    }
}
