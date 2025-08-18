<?php
namespace app\controllers;

use Craft;
use craft\web\Controller;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use yii\web\Response;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use Mollie\Api\MollieApiClient;

class TabsController extends Controller
{
    /**
     * Allow anonymous access for guest flows & webhooks.
     */
    protected array|int|bool $allowAnonymous = [
        'pay'         => self::ALLOW_ANONYMOUS_LIVE,
        'webhook'     => self::ALLOW_ANONYMOUS_LIVE,
        'start-guest' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    // ── Config ────────────────────────────────────────────────────────────────
    private const SECTION_TABS_HANDLE     = 'tabs';
    private const ENTRYTYPE_TABS_HANDLE   = null; // set to your entry type handle if you have one
    private const FIELD_TAB_STATUS_HANDLE = 'tabStatus';
    private const FIELD_ITEMS_HANDLE      = 'items';     // Matrix
    private const FIELD_TAB_OWNER_HANDLE  = 'tabOwner';  // User relation

    // Matrix block & fields
    private const BLOCK_LINEITEM_HANDLE = 'lineitem';
    private const FIELD_DRINK_HANDLE    = 'drink';
    private const FIELD_QTY_HANDLE      = 'qty';
    private const FIELD_PRICE_HANDLE    = 'price';

    // Fallback author when creating guest tabs
    private const DEFAULT_AUTHOR_ID = 1;

    // ── Helpers ──────────────────────────────────────────────────────────────
    private function svc(string $id) {
        $app = Craft::$app;
        return $app->has($id, true) ? $app->get($id) : null;
    }

    private function getPriceMode(): string
    {
        $fields = $this->svc('fields'); if (!$fields) return 'money';
        $f = $fields->getFieldByHandle(self::FIELD_PRICE_HANDLE);
        $isMoney  = class_exists('\craft\fields\Money') && ($f instanceof \craft\fields\Money);
        $isNumber = $f instanceof \craft\fields\Number;
        $isText   = $f instanceof \craft\fields\PlainText;
        return $isMoney ? 'money' : ($isNumber ? 'number' : ($isText ? 'text' : 'money'));
    }

    // ── Email helpers ────────────────────────────────────────────────────────
    private function getGuestEmailFromTab(Entry $tab): ?string
    {
        foreach (['guestEmail','email'] as $handle) {
            try {
                $val = $tab->getFieldValue($handle);
                if (is_string($val) && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    return $val;
                }
            } catch (\Throwable $e) {}
        }
        return null;
    }

    private function getAdminEmails(): array
    {
        $env = App::env('ADMIN_EMAILS');
        if ($env) {
            $list = array_filter(array_map('trim', explode(',', $env)));
            return array_values(array_unique($list));
        }
        $emails = [];
        foreach (User::find()->admin(true)->status('active')->all() as $u) {
            if ($u->email) $emails[] = $u->email;
        }
        return array_values(array_unique($emails));
    }

    /** Safe "from" identity for mailer (no deprecated systemSettings). */
    private function getMailerFrom(): array
    {
        $general  = Craft::$app->getConfig()->getGeneral();
        $siteName = Craft::$app->getSites()->getCurrentSite()->name ?? 'Website';

        $host = parse_url(Craft::$app->getRequest()->hostInfo ?? '', PHP_URL_HOST) ?: 'example.test';
        $host = preg_replace('/^www\./', '', $host);

        $fromEmail = $general->fromEmail ?: ('noreply@' . $host);
        $fromName  = $general->fromName  ?: $siteName;

        return [$fromEmail => $fromName];
    }

    /**
     * Single, definitive receipt mailer.
     * (Removed the duplicate definition that caused class load errors.)
     */
    private function sendReceipt(Entry $tab, ?User $recipientUser = null, ?string $guestEmail = null): bool
    {
        try {
            $to = [];
            if ($recipientUser && $recipientUser->email) $to[] = $recipientUser->email;
            if ($guestEmail) $to[] = $guestEmail;
            if (!$to) {
                Craft::info('No recipients for tab '.$tab->id.'; skipping email', __METHOD__);
                return false;
            }

            $site     = Craft::$app->getSites()->getCurrentSite();
            $siteName = $site->name ?? 'Website';

            // Build rows + total
            $total = 0.0;
            $items = [];
            foreach ($tab->getFieldValue(self::FIELD_ITEMS_HANDLE)->all() as $b) {
                $drink = $b->getFieldValue(self::FIELD_DRINK_HANDLE)->one();
                $qty   = (int)($b->getFieldValue(self::FIELD_QTY_HANDLE) ?? 0);
                $price = $b->getFieldValue(self::FIELD_PRICE_HANDLE);

                $unit  = (is_object($price) && method_exists($price, 'getAmount'))
                    ? $price->getAmount()/100
                    : (float)$price;

                $line   = $qty * max(0, $unit);
                $total += $line;

                $items[] = [
                    'title' => $b->title ?: ($drink ? $drink->title : '—'),
                    'qty'   => $qty,
                    'price' => $unit,
                    'line'  => $line,
                ];
            }

            // Render body (fallback if template missing)
            $view = Craft::$app->getView();
            $old  = $view->getTemplateMode();
            $view->setTemplateMode($view::TEMPLATE_MODE_SITE);

            $html = '';
            try {
                $html = $view->renderTemplate('_emails/receipt', [
                    'tab'      => $tab,
                    'items'    => $items,
                    'total'    => $total,
                    'siteName' => $siteName,
                    'siteUrl'  => $site->getBaseUrl(),
                ]);
            } catch (\Throwable $e) {
                Craft::warning('Receipt template failed: '.$e->getMessage(), __METHOD__);
            } finally {
                $view->setTemplateMode($old);
            }

            if (!$html) {
                // super simple fallback
                $rows = [];
                foreach ($items as $i) {
                    $rows[] = sprintf(
                        '<tr><td>%s</td><td align="right">%d</td><td align="right">€ %.2f</td><td align="right">€ %.2f</td></tr>',
                        htmlspecialchars($i['title']), (int)$i['qty'], $i['price'], $i['line']
                    );
                }
                $html = sprintf(
                    '<h2>Bon – Tab #%d</h2><table cellpadding="6" cellspacing="0" border="1">%s</table><p><strong>Totaal:</strong> € %.2f</p>',
                    $tab->id, implode('', $rows), $total
                );
            }

            $mailer = Craft::$app->getMailer();

            // SEND NOW: disable queue for this send
            $restoreQueue = property_exists($mailer, 'useQueue') ? $mailer->useQueue : null;
            if ($restoreQueue !== null) $mailer->useQueue = false;

            $ok = (bool)$mailer->compose()
                ->setFrom($this->getMailerFrom())
                ->setTo($to)
                ->setSubject('Je kassabon – '.$siteName)
                ->setHtmlBody($html)
                ->send();

            if ($restoreQueue !== null) $mailer->useQueue = $restoreQueue;

            Craft::info('Mailer send '.($ok?'OK':'FAILED').' to '.implode(', ', $to).' for tab '.$tab->id, __METHOD__);
            return $ok;
        } catch (\Throwable $e) {
            Craft::error('Failed to send receipt: '.$e->getMessage(), __METHOD__);
            return false;
        }
    }

    // ── Guest: create tab from a cart ────────────────────────────────────────
    public function actionStartGuest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $req      = Craft::$app->getRequest();
        $name     = trim((string)$req->getBodyParam('name'));
        $table    = trim((string)$req->getBodyParam('table'));
        $cartJson = (string)$req->getBodyParam('cart');
        $rows     = json_decode($cartJson, true) ?: [];

        if ($name === '' || !is_array($rows) || !count($rows)) {
            return $this->asJson(['success'=>false,'error'=>'Invalid input'])->setStatusCode(400);
        }

        // validate drinks
        $normalized = [];
        foreach ($rows as $r) {
            $id  = (int)($r['id'] ?? 0);
            $qty = max(1, (int)($r['qty'] ?? 0));
            if (!$id || !$qty) continue;

            $drink = Entry::find()->section('drinks')->id($id)->one();
            if (!$drink) return $this->asJson(['success'=>false,'error'=>"Drink not found: {$id}"])->setStatusCode(400);

            $priceMoney = $drink->price ?? null;
            if (!$priceMoney || !is_object($priceMoney) || !method_exists($priceMoney, 'getAmount')) {
                return $this->asJson(['success'=>false,'error'=>"Invalid price for drink {$id}"])->setStatusCode(400);
            }

            $key = (int)$drink->id;
            if (!isset($normalized[$key])) {
                $normalized[$key] = ['drink'=>$drink, 'qty'=>$qty, 'priceMoney'=>$priceMoney];
            } else {
                $normalized[$key]['qty'] += $qty;
            }
        }
        if (!count($normalized)) {
            return $this->asJson(['success'=>false,'error'=>'Empty cart'])->setStatusCode(400);
        }

        [$sectionId, $entryTypeId] = $this->getTabsSectionAndTypeIds();
        if (!$sectionId || !$entryTypeId) {
            return $this->asJson(['success'=>false,'error'=>'Tabs section/type not found'])->setStatusCode(500);
        }

        $priceMode = $this->getPriceMode();
        $elements  = Craft::$app->getElements();
        $db        = Craft::$app->getDb();
        $tx        = $db->beginTransaction();

        try {
            // author fallback
            $users  = $this->svc('users');
            $author = $users?->getUserById((int)self::DEFAULT_AUTHOR_ID)
                ?: User::find()->admin(true)->status('active')->one()
                ?: User::find()->status('active')->one()
                ?: User::find()->status(null)->one();
            if (!$author) throw new \RuntimeException('No author available');

            // tab
            $tab = new Entry();
            $tab->sectionId = (int)$sectionId;
            $tab->typeId    = (int)$entryTypeId;
            $tab->siteId    = (int)Craft::$app->getSites()->getCurrentSite()->id;
            $tab->enabled   = true;
            $tab->authorId  = (int)$author->id;
            $tab->title     = 'Gast-tab: '.$name.($table ? " ({$table})" : '');
            $tab->setFieldValue(self::FIELD_TAB_STATUS_HANDLE, 'open');
            $tab->setFieldValue(self::FIELD_ITEMS_HANDLE, []);

            if (!$elements->saveElement($tab)) {
                $tx->rollBack();
                return $this->asJson(['success'=>false,'errors'=>$tab->getErrors()])->setStatusCode(400);
            }

            // items
            $blocksData = [];
            foreach ($normalized as $row) {
                $drink = $row['drink'];
                $qty   = (int)$row['qty'];
                $money = $row['priceMoney'];
                $priceValue = ($priceMode === 'money') ? $money : ($money->getAmount()/100);
                $blocksData[] = [
                    'type'    => self::BLOCK_LINEITEM_HANDLE,
                    'enabled' => true,
                    'title'   => $drink->title,
                    'fields'  => [
                        self::FIELD_DRINK_HANDLE => [$drink->id],
                        self::FIELD_QTY_HANDLE   => $qty,
                        self::FIELD_PRICE_HANDLE => $priceValue,
                    ],
                ];
            }
            $tab->setFieldValue(self::FIELD_ITEMS_HANDLE, $blocksData);

            if (!$elements->saveElement($tab)) {
                $tx->rollBack();
                return $this->asJson(['success'=>false,'errors'=>$tab->getErrors()])->setStatusCode(400);
            }

            $tx->commit();

            return $this->asJson([
                'success'  => true,
                'tabId'    => (int)$tab->id,
                'redirect' => UrlHelper::siteUrl('tabs?tabId='.$tab->id),
            ]);
        } catch (\Throwable $e) {
            $tx->rollBack();
            Craft::error($e->getMessage(), __METHOD__);
            return $this->asJson(['success'=>false,'error'=>'Server error'])->setStatusCode(500);
        }
    }

    // ── Logged-in: add to my open tab ────────────────────────────────────────
    public function actionAdd(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) throw new ForbiddenHttpException('Login required');

        $r       = Craft::$app->getRequest();
        $drinkId = (int)$r->getRequiredBodyParam('drinkId');
        $qty     = max(1, (int)$r->getBodyParam('qty', 1));

        $drink = Entry::find()->section('drinks')->id($drinkId)->one();
        if (!$drink) throw new BadRequestHttpException('Drink not found');

        $priceMoney = $drink->price ?? null;
        if (!$priceMoney || !is_object($priceMoney) || !method_exists($priceMoney, 'getAmount')) {
            throw new BadRequestHttpException('Price missing or invalid on drink');
        }

        $priceMode  = $this->getPriceMode();
        $unitNumber = $priceMoney->getAmount() / 100;

        $elements = Craft::$app->getElements();
        $db       = Craft::$app->getDb();
        $tx       = $db->beginTransaction();

        try {
            $tab = $this->getOrCreateOpenTabForUser($user);

            // existing blocks
            $existingBlocks = $tab->getFieldValue(self::FIELD_ITEMS_HANDLE)->all();

            $blocksData = [];
            $merged     = false;

            foreach ($existingBlocks as $block) {
                $bDrink = $block->getFieldValue(self::FIELD_DRINK_HANDLE)->one();
                $isSame = $bDrink && (int)$bDrink->id === (int)$drink->id;

                $existingQty   = (int)($block->getFieldValue(self::FIELD_QTY_HANDLE) ?? 0);
                $existingPrice = $block->getFieldValue(self::FIELD_PRICE_HANDLE);

                if ($isSame) {
                    $merged = true;
                    $existingQty += $qty;
                }

                // Keep each block's own price type/value
                $priceFieldValue = $existingPrice;
                if (!$priceFieldValue) {
                    $priceFieldValue = ($priceMode === 'money') ? $priceMoney : (float)$unitNumber;
                }

                $blocksData[] = [
                    'id'      => (int)$block->id, // preserve block
                    'type'    => self::BLOCK_LINEITEM_HANDLE,
                    'enabled' => true,
                    'title'   => $block->title ?: ($bDrink?->title ?? $drink->title),
                    'fields'  => [
                        self::FIELD_DRINK_HANDLE => [$bDrink?->id ?? $drink->id],
                        self::FIELD_QTY_HANDLE   => $existingQty,
                        self::FIELD_PRICE_HANDLE => $priceFieldValue,
                    ],
                ];
            }

            if (!$merged) {
                // add new block
                $blocksData[] = [
                    'type'    => self::BLOCK_LINEITEM_HANDLE,
                    'enabled' => true,
                    'title'   => $drink->title,
                    'fields'  => [
                        self::FIELD_DRINK_HANDLE => [$drink->id],
                        self::FIELD_QTY_HANDLE   => $qty,
                        self::FIELD_PRICE_HANDLE => ($priceMode === 'money') ? $priceMoney : (float)$unitNumber,
                    ],
                ];
            }

            // Ensure owner + status correct; set Matrix payload and save parent
            $tab->setFieldValue(self::FIELD_TAB_OWNER_HANDLE, [$user->id]);
            $tab->setFieldValue(self::FIELD_TAB_STATUS_HANDLE, 'open');
            $tab->setFieldValue(self::FIELD_ITEMS_HANDLE, $blocksData);

            if (!$elements->saveElement($tab)) {
                $tx->rollBack();
                return $this->asJson(['success'=>false,'errors'=>$tab->getErrors()])->setStatusCode(400);
            }

            $tx->commit();
            return $this->asJson(['success'=>true,'merged'=>$merged,'tabId'=>(int)$tab->id]);
        } catch (\Throwable $e) {
            $tx->rollBack();
            Craft::error($e->getMessage(), __METHOD__);
            return $this->asJson(['success'=>false,'error'=>'Server error'])->setStatusCode(500);
        }
    }

    // ── Online pay (Mollie) ─────────────────────────────────────────────────
    public function actionPay(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $tabId = (int)Craft::$app->getRequest()->getRequiredBodyParam('tabId');
        $tab   = Entry::find()->section(self::SECTION_TABS_HANDLE)->id($tabId)->one();
        if (!$tab) return $this->asJson(['ok'=>false,'error'=>'Tab not found'])->setStatusCode(404);

        // total
        $total = 0.0;
        foreach ($tab->getFieldValue(self::FIELD_ITEMS_HANDLE)->all() as $b) {
            $qty   = (float)$b->getFieldValue(self::FIELD_QTY_HANDLE) ?: 0;
            $price = $b->getFieldValue(self::FIELD_PRICE_HANDLE);
            $unit  = (is_object($price) && method_exists($price, 'getAmount')) ? $price->getAmount()/100 : (float)$price;
            $total += max(0,$qty) * max(0,$unit);
        }
        $total = max(0.01, round($total, 2));

        $apiKey = App::env('MOLLIE_API_KEY');
        if (!$apiKey) return $this->asJson(['ok'=>false,'error'=>'Mollie API key missing'])->setStatusCode(500);

        $mollie = new MollieApiClient();
        $mollie->setApiKey($apiKey);

        $value       = number_format($total, 2, '.', '');
        $redirectUrl = UrlHelper::siteUrl('tabs?tabId='.$tab->id);
        // This controller is mounted as application controller => plain 'tabs/webhook'
        $webhookUrl  = UrlHelper::actionUrl('tabs/webhook');

        $payment = $mollie->payments->create([
            'amount'      => ['currency'=>'EUR','value'=>$value],
            'description' => 'Tab #'.$tab->id,
            'redirectUrl' => $redirectUrl,
            'webhookUrl'  => $webhookUrl,
            'metadata'    => ['tabId'=>(int)$tab->id],
        ]);

        return $this->asJson(['ok'=>true,'checkoutUrl'=>$payment->getCheckoutUrl()]);
    }

    public function actionWebhook(): Response
    {
        $this->requirePostRequest();

        $apiKey = App::env('MOLLIE_API_KEY');
        if (!$apiKey) return $this->asPlainText('no key', 500);

        $id = (string)Craft::$app->getRequest()->getRequiredBodyParam('id');

        $mollie = new MollieApiClient();
        $mollie->setApiKey($apiKey);
        $payment = $mollie->payments->get($id);

        $tabId = (int)($payment->metadata->tabId ?? 0);
        if (!$tabId) return $this->asPlainText('no tab', 200);

        $tab = Entry::find()->section(self::SECTION_TABS_HANDLE)->id($tabId)->one();
        if (!$tab) return $this->asPlainText('tab missing', 200);

        if ($payment->isPaid()) {
            $tab->setFieldValue(self::FIELD_TAB_STATUS_HANDLE, 'paid');
            Craft::$app->getElements()->saveElement($tab);

            $ownerUser = $tab->getFieldValue(self::FIELD_TAB_OWNER_HANDLE)->one();
            $guestEmail= $this->getGuestEmailFromTab($tab);
            $this->sendReceipt($tab, $ownerUser instanceof User ? $ownerUser : null, $guestEmail);
        }

        return $this->asPlainText('ok', 200);
    }

    // ── Logged-in: cash close ────────────────────────────────────────────────
   public function actionClose(): Response
{
    $this->requirePostRequest();

    $req       = Craft::$app->getRequest();
    $wantsJson = $req->getAcceptsJson(); // don't force it; just detect
    $user      = Craft::$app->getUser()->getIdentity();
    if (!$user) {
        if ($wantsJson) {
            return $this->asJson(['success' => false, 'error' => 'Login required'])->setStatusCode(403);
        }
        throw new ForbiddenHttpException('Login required');
    }

    $tabId = (int)$req->getRequiredBodyParam('tabId');
    $tab   = Entry::find()->section(self::SECTION_TABS_HANDLE)->id($tabId)->one();
    if (!$tab) {
        if ($wantsJson) return $this->asJson(['success'=>false,'error'=>'Tab not found'])->setStatusCode(404);
        throw new BadRequestHttpException('Tab not found');
    }

    // perms: owner or admin
    $currentOwner = $tab->getFieldValue(self::FIELD_TAB_OWNER_HANDLE)->one();
    $ownerId      = (int)($currentOwner->id ?? 0);
    if ($ownerId !== (int)$user->id && !$user->admin) {
        if ($wantsJson) return $this->asJson(['success'=>false,'error'=>'Not allowed'])->setStatusCode(403);
        throw new ForbiddenHttpException('Not allowed');
    }

    // Ensure owner exists: make the closer the owner if none is set
    if (!$currentOwner instanceof User) {
        $tab->setFieldValue(self::FIELD_TAB_OWNER_HANDLE, [$user->id]);
    }

    // Mark as paid
    $tab->setFieldValue(self::FIELD_TAB_STATUS_HANDLE, 'paid');
    if (!Craft::$app->getElements()->saveElement($tab)) {
        $err = $tab->getErrors();
        if ($wantsJson) return $this->asJson(['success'=>false,'errors'=>$err])->setStatusCode(400);
        Craft::$app->getSession()->setError('Kon tab niet sluiten.');
        return $this->redirect(UrlHelper::siteUrl('tabs'));
    }

    // Send receipt (immediate; no queue)
    $sent = false;
    try {
        $ownerUser = $tab->getFieldValue(self::FIELD_TAB_OWNER_HANDLE)->one() ?: $user; // fallback to closer
        $sent = $this->sendReceipt($tab, $ownerUser, null);
        Craft::info('Cash receipt sent='.($sent?'yes':'no').' for tab '.$tab->id, __METHOD__);
    } catch (\Throwable $e) {
        Craft::warning('Receipt email suppressed: '.$e->getMessage(), __METHOD__);
    }

    if ($wantsJson) {
        return $this->asJson(['success'=>true, 'emailSent'=>$sent]);
    }

    Craft::$app->getSession()->setNotice($sent ? 'Tab gesloten, bon gemaild.' : 'Tab gesloten.');
    return $this->redirect(UrlHelper::siteUrl('tabs'));
}


    // ── Section/type + user-tab helpers ──────────────────────────────────────
    private function getTabsSectionAndTypeIds(): array
    {
        // Fast path: inspect any entry
        $any = Entry::find()->section(self::SECTION_TABS_HANDLE)->status(null)->site('*')->one();
        if ($any) return [(int)$any->sectionId, (int)$any->typeId];

        $sections = $this->svc('sections');
        if ($sections) {
            $section = $sections->getSectionByHandle(self::SECTION_TABS_HANDLE);
            if (!$section) return [null, null];
            $entryType = null;
            foreach ($section->getEntryTypes() as $t) {
                if (self::ENTRYTYPE_TABS_HANDLE && $t->handle === self::ENTRYTYPE_TABS_HANDLE) { $entryType = $t; break; }
            }
            if (!$entryType) $entryType = $section->getEntryTypes()[0] ?? null;
            return [$section->id ?? null, $entryType?->id ?? null];
        }
        return [null, null];
    }

    /** Only return an OPEN tab for the user (hardened for admin as well). */
    private function getOpenTabForUser(User $user): ?Entry
    {
        $q = Entry::find()
            ->section(self::SECTION_TABS_HANDLE)
            // Don’t let status filtering hide open tabs if someone made a draft/revision:
            ->status(null)
            ->relatedTo(['field' => self::FIELD_TAB_OWNER_HANDLE, 'targetElement' => $user])
            ->site('*');

        try {
            // Preferred: dynamic field param (works when the custom field is queryable)
            $q->{self::FIELD_TAB_STATUS_HANDLE}('open');
        } catch (\Throwable $e) {
            // Portable fallback: filter on the content table column
            // Works even if the query builder changes aliases behind the scenes
            $column = '[[field_'.self::FIELD_TAB_STATUS_HANDLE.']]';
            $q->andWhere([$column => 'open']);
        }

        return $q->one();
    }

    /** Get the open tab or create one if none exists. */
    private function getOrCreateOpenTabForUser(User $user): Entry
    {
        $tab = $this->getOpenTabForUser($user);
        if ($tab) return $tab;

        [$sectionId, $entryTypeId] = $this->getTabsSectionAndTypeIds();
        if (!$sectionId || !$entryTypeId) {
            throw new BadRequestHttpException('Tabs section/entry type not configured');
        }

        $tab = new Entry();
        $tab->sectionId = (int)$sectionId;
        $tab->typeId    = (int)$entryTypeId;
        $tab->siteId    = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $tab->enabled   = true;
        $tab->authorId  = (int)$user->id;
        $tab->title     = 'Tab for ' . ($user->friendlyName ?: $user->username);

        $tab->setFieldValue(self::FIELD_TAB_OWNER_HANDLE, [$user->id]);
        $tab->setFieldValue(self::FIELD_TAB_STATUS_HANDLE, 'open');
        $tab->setFieldValue(self::FIELD_ITEMS_HANDLE, []);

        if (!Craft::$app->getElements()->saveElement($tab)) {
            throw new BadRequestHttpException('Unable to create tab: ' . json_encode($tab->getErrors()));
        }

        return $tab;
    }
}
