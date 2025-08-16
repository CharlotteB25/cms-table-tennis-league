<?php
namespace modules\hno;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\services\Elements;
use yii\base\Event;

class Module extends \yii\base\Module
{
    private static bool $inProgress = false;

    public function init(): void
    {
        parent::init();
        Craft::info('HNO module booted', __METHOD__);

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function (ElementEvent $e) {
                // Re-entrancy (when we persist flags)
                if (self::$inProgress) {
                    Craft::info('Skip: re-entrant guard', __METHOD__);
                    return;
                }

                $el = $e->element;
                if (!$el instanceof Entry) {
                    return;
                }

                // --- Suppress common duplicate triggers ---
                // 1) Ignore propagation saves
                $isPropagation = isset($e->isPropagation) ? (bool)$e->isPropagation : false;
                if ($isPropagation) {
                    Craft::info("Skip: propagation save for #{$el->id}", __METHOD__);
                    return;
                }

                // 2) Ignore drafts/revisions/non-canonical variants
                if ((method_exists($el, 'getIsDraft') && $el->getIsDraft())
                    || (method_exists($el, 'getIsRevision') && $el->getIsRevision())
                    || (method_exists($el, 'getIsCanonical') && !$el->getIsCanonical())
                ) {
                    Craft::info("Skip: non-canonical/draft/revision for #{$el->id}", __METHOD__);
                    return;
                }

                // 3) Only run on the primary site
                try {
                    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
                    if ((int)$el->siteId !== (int)$primarySiteId) {
                        Craft::info("Skip: non-primary site (siteId={$el->siteId}) for #{$el->id}", __METHOD__);
                        return;
                    }
                } catch (\Throwable $x) {
                    // If anything odd, continue (better to send than silently drop)
                }

                $section = $el->getSection();
                $secHandle = $section->handle ?? null;
                Craft::info(
                    "AFTER_SAVE_ELEMENT entry #{$el->id} in '{$secHandle}' (isNew=" . (($e->isNew ?? false) ? 'yes' : 'no') . ")",
                    __METHOD__
                );

                if ($secHandle !== 'availability') {
                    return;
                }

                // --- Safely extract fields ---
                $player = null;
                try { $player = $el->getFieldValue('player')->one(); } catch (\Throwable $ex) {
                    Craft::warning("player read failed: ".$ex->getMessage(), __METHOD__);
                }
                $match = null;
                try { $match = $el->getFieldValue('match')->one(); } catch (\Throwable $ex) {
                    Craft::warning("match read failed: ".$ex->getMessage(), __METHOD__);
                }
                if (!$player || !$match) {
                    Craft::info('Email skip: missing player or match', __METHOD__);
                    return;
                }

                // Preference
                $notify = (bool)($player->emailNotifications ?? $player->getFieldValue('emailNotifications'));
                Craft::info("User {$player->id} notify=" . ($notify ? 'ON' : 'OFF'), __METHOD__);

                // Option fields
                $availabilityData  = $el->getFieldValue('availability');
                $availabilityValue = is_object($availabilityData) && property_exists($availabilityData, 'value')
                    ? (string)$availabilityData->value : (string)$availabilityData;
                $availabilityLabel = is_object($availabilityData) && property_exists($availabilityData, 'label')
                    ? (string)$availabilityData->label : ($availabilityValue ?: '—');

                $statusAdminData  = $el->getFieldValue('statusAdmin');
                $statusAdminValue = is_object($statusAdminData) && property_exists($statusAdminData, 'value')
                    ? (string)$statusAdminData->value : (string)$statusAdminData;

                $isSelected = in_array(strtolower((string)$statusAdminValue), ['geselecteerd','selected','yes','ja'], true);

                // Flags (force false on first save so initial mails can send even if defaults are on)
                $confirmationSent = (bool)$el->getFieldValue('confirmationSent');
                $selectionSent    = (bool)$el->getFieldValue('selectionSent');
                if ($e->isNew ?? false) {
                    $confirmationSent = false;
                    if ($isSelected) {
                        $selectionSent = false;
                    }
                }

                Craft::info(
                    "Values: availability='{$availabilityValue}' (label='{$availabilityLabel}'), statusAdmin='{$statusAdminValue}', confirmationSent=" . ($confirmationSent?'1':'0') . ", selectionSent=" . ($selectionSent?'1':'0'),
                    __METHOD__
                );

                $siteName = Craft::$app->getSystemName();
                $siteUrl  = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();
                $currentUser = Craft::$app->getUser()->getIdentity();

                $didChange = false;
                $cache = Craft::$app->getCache();

                // 4) De-dupe keys (prevents back-to-back double sends)
                $keyConfirm = "hno:mail:avail:confirm:{$player->id}:{$match->id}";
                $keySelect  = "hno:mail:avail:select:{$player->id}:{$match->id}";
                $dedupeTtl  = 15; // seconds

                // 1) Player confirmation (only if player saves their own availability)
                if (
                    $notify &&
                    !$confirmationSent &&
                    $currentUser &&
                    (int)$currentUser->id === (int)$player->id &&
                    !$cache->get($keyConfirm)
                ) {
                    $ok = $this->sendTemplateMail(
                        (string)$player->email,
                        (string)($player->friendlyName ?? $player->username),
                        'We ontvingen je beschikbaarheid',
                        'emails/availability-confirmation',
                        [
                            'user' => $player,
                            'match' => $match,
                            'availabilityLabel' => $availabilityLabel,
                            'siteName' => $siteName,
                            'siteUrl' => $siteUrl,
                        ],
                        "<p>Hoi ".htmlspecialchars((string)($player->friendlyName ?: $player->username), ENT_QUOTES, 'UTF-8').",</p>
                         <p>We ontvingen je beschikbaarheid (<strong>".htmlspecialchars($availabilityLabel, ENT_QUOTES, 'UTF-8')."</strong>) voor <em>".htmlspecialchars((string)$match->title, ENT_QUOTES, 'UTF-8')."</em>.</p>
                         <p>Groetjes,<br>".htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8')."</p>"
                    );
                    Craft::info('Sent availability confirmation: '.($ok?'OK':'FAIL'), __METHOD__);
                    if ($ok) {
                        $cache->set($keyConfirm, 1, $dedupeTtl);
                        $el->setFieldValue('confirmationSent', true);
                        $didChange = true;
                    }
                } elseif ($cache->get($keyConfirm)) {
                    Craft::info('Skip: confirmation de-dup (cache)', __METHOD__);
                }

                // 2) Admin selection notice
                if ($notify && !$selectionSent && $isSelected && !$cache->get($keySelect)) {
                    $ok = $this->sendTemplateMail(
                        (string)$player->email,
                        (string)($player->friendlyName ?? $player->username),
                        'Je bent geselecteerd',
                        'emails/selection-notification',
                        [
                            'user' => $player,
                            'match' => $match,
                            'siteName' => $siteName,
                            'siteUrl' => $siteUrl,
                        ],
                        "<p>Hoi ".htmlspecialchars((string)($player->friendlyName ?: $player->username), ENT_QUOTES, 'UTF-8').",</p>
                         <p>Goed nieuws: je bent geselecteerd voor <em>".htmlspecialchars((string)$match->title, ENT_QUOTES, 'UTF-8')."</em>.</p>
                         <p>Succes!<br>".htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8')."</p>"
                    );
                    Craft::info('Sent selection notice: '.($ok?'OK':'FAIL'), __METHOD__);
                    if ($ok) {
                        $cache->set($keySelect, 1, $dedupeTtl);
                        $el->setFieldValue('selectionSent', true);
                        $didChange = true;
                    }
                } elseif ($cache->get($keySelect)) {
                    Craft::info('Skip: selection de-dup (cache)', __METHOD__);
                }

                // Persist flags without re-triggering
                if ($didChange) {
                    try {
                        self::$inProgress = true;
                        Craft::$app->getElements()->saveElement($el, false, false, false);
                        Craft::info('Entry flags persisted (no re-trigger).', __METHOD__);
                    } catch (\Throwable $ex) {
                        Craft::error('Flag persist failed: '.$ex->getMessage(), __METHOD__);
                    } finally {
                        self::$inProgress = false;
                    }
                }
            }
        );
    }

    private function sendTemplateMail(
        string $toEmail,
        string $toName,
        string $subject,
        string $template,
        array $params,
        ?string $fallbackHtml = null
    ): bool {
        try {
            $html = null;
            try {
                $html = Craft::$app->getView()->renderTemplate($template, $params);
            } catch (\Throwable $tplEx) {
                Craft::warning("Email template '{$template}' missing or failed: ".$tplEx->getMessage(), __METHOD__);
                $html = $fallbackHtml ?? '<p>Bericht zonder template.</p>';
            }

            return Craft::$app->getMailer()
                ->compose()
                ->setTo([$toEmail => $toName])
                ->setSubject($subject)
                ->setHtmlBody($html)
                ->send();
        } catch (\Throwable $e) {
            Craft::error('Mail send failed: '.$e->getMessage(), __METHOD__);
            return false;
        }
    }
}
