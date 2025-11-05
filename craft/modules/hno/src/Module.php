<?php
namespace modules\hno;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\services\Elements;
use yii\base\Event;
use yii\base\Model;
use yii\base\ModelEvent;



class Module extends \yii\base\Module
{
    private static bool $inProgress = false;

   public function init(): void
{
    parent::init();
    Craft::info('HNO module booted', __METHOD__);

    // 0) Helper closure to resolve matchId/playerId even before normalization
    $resolveIds = static function(Entry $el): array {
        $matchId = null;
        $playerId = null;

        // Try normalized relations first
        try { $m = $el->getFieldValue('match');  $matchId  = $m ? ($m->one()->id ?? null) : null; } catch (\Throwable $e) {}
        try { $p = $el->getFieldValue('player'); $playerId = $p ? ($p->one()->id ?? null) : null; } catch (\Throwable $e) {}

        // Fallback to POST payload if still missing
        if (!$matchId || !$playerId) {
            $req = Craft::$app->getRequest();
            if ($req->getIsPost()) {
                $fields = (array)$req->getBodyParam('fields', []);
                // Accept both [0] and flat values
                $rawMatch  = $fields['match']  ?? null;
                $rawPlayer = $fields['player'] ?? null;

                $rawMatchIds  = is_array($rawMatch)  ? $rawMatch  : [$rawMatch];
                $rawPlayerIds = is_array($rawPlayer) ? $rawPlayer : [$rawPlayer];

                $matchId  = $matchId  ?: (int)($rawMatchIds[0]  ?? 0) ?: null;
                $playerId = $playerId ?: (int)($rawPlayerIds[0] ?? 0) ?: null;
            }
        }
        return [$matchId, $playerId];
    };

    // 1) EARLY GUARD: BEFORE_VALIDATE (runs before Craft generates unique slugs)
    Event::on(
        Entry::class,
        Model::EVENT_BEFORE_VALIDATE,
        function (ModelEvent $e) use ($resolveIds) {
            /** @var Entry $el */
            $el = $e->sender;
            $section = $el->getSection();
            if (!$section || $section->handle !== 'availability') return;

            // Ignore drafts/revisions/propagations/non-canonical
            if ((method_exists($el, 'getIsDraft') && $el->getIsDraft())
                || (method_exists($el, 'getIsRevision') && $el->getIsRevision())
                || (method_exists($el, 'getIsCanonical') && !$el->getIsCanonical())) {
                return;
            }

            // Only care on create (not edits)
            if ($el->id) return;

            // Resolve IDs as early as possible
            [$matchId, $playerId] = $resolveIds($el);
            if (!$matchId || !$playerId) return;

            // Force a deterministic slug so the user/admin paths behave the same
            $fixedSlug = "m{$matchId}-u{$playerId}";
            $el->slug = $fixedSlug;

            // Slug-based dupe (most reliable because it doesn’t require normalized relations)
            $slugDupe = Entry::find()
                ->section('availability')
                ->siteId($el->siteId)     // use ->site('*') if you want cross-site uniqueness
                ->status(null)
                ->slug($fixedSlug)
                ->one();
    if ($slugDupe) {
    $el->addError('availability', 'Je hebt voor deze wedstrijd al een keuze doorgegeven.');
    Craft::$app->getSession()->setError('Je hebt voor deze wedstrijd al een keuze doorgegeven.');
    Craft::$app->getSession()->setFlash('entry', $el);
    Craft::$app->getUrlManager()->setRouteParams(['entry' => $el]);
    $e->isValid = false;
    return;
}

            // Relation-based dupe (secondary safety net)
            $dupe = Entry::find()
                ->section('availability')
                ->siteId($el->siteId)
                ->status(null)
                ->relatedTo([
                    'and',
                    ['field' => 'match',  'targetElement' => $matchId],
                    ['field' => 'player', 'targetElement' => $playerId],
                ])
                ->one();

            if ($dupe) {
                $el->addError('availability', 'Je hebt je voor deze wedstrijd al een keuze doorgegeven.');
                $e->isValid = false;
            }
        }
    );

    // 2) (Optional) Keep your BEFORE_SAVE guard — it’s fine to leave it,
    //    but it will almost never be reached if BEFORE_VALIDATE blocked already.
    Event::on(
        Elements::class,
        Elements::EVENT_BEFORE_SAVE_ELEMENT,
        function (ElementEvent $e) use ($resolveIds) {
            $el = $e->element;
            if (!$el instanceof Entry) return;

            // Ignore propagations/drafts/revisions/non-canonical
            $isPropagation = isset($e->isPropagation) ? (bool)$e->isPropagation : false;
            if ($isPropagation) return;
            if ((method_exists($el, 'getIsDraft') && $el->getIsDraft())
                || (method_exists($el, 'getIsRevision') && $el->getIsRevision())
                || (method_exists($el, 'getIsCanonical') && !$el->getIsCanonical())) {
                return;
            }

            $section = $el->getSection();
            if (!$section || $section->handle !== 'availability') return;

            // Only for new
            if (!($e->isNew ?? false)) return;

            // Slug guard (in case BEFORE_VALIDATE didn’t run for some reason)
            $incomingSlug = (string)($el->slug ?? '');
            if ($incomingSlug !== '') {
                $slugDupe = Entry::find()
                    ->section('availability')
                    ->siteId($el->siteId)
                    ->status(null)
                    ->slug($incomingSlug)
                    ->one();
         if ($slugDupe) {
    $el->addError('availability', 'Je hebt voor deze wedstrijd al een keuze doorgegeven.');
    Craft::$app->getSession()->setError('Je hebt voor deze wedstrijd al een keuze doorgegeven.');
    Craft::$app->getSession()->setFlash('entry', $el);
    Craft::$app->getUrlManager()->setRouteParams(['entry' => $el]);
    $e->isValid = false;
    return;
}
            }

            // Relation fallback
            [$matchId, $playerId] = $resolveIds($el);
            if ($matchId && $playerId) {
                $dupe = Entry::find()
                    ->section('availability')
                    ->siteId($el->siteId)
                    ->status(null)
                    ->relatedTo([
                        'and',
                        ['field' => 'match',  'targetElement' => $matchId],
                        ['field' => 'player', 'targetElement' => $playerId],
                    ])
                    ->one();
                if ($dupe) {
                    $el->addError('availability', 'Je hebt je voor deze wedstrijd al een keuze doorgegeven.');
                    $e->isValid = false;
                }
            }
        }
    );


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
