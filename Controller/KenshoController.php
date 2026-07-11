<?php

namespace Kanboard\Plugin\Kensho\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Kensho\Model\KenshoAiGate;
use Kanboard\Plugin\Kensho\Model\KenshoAiModel;
use Kanboard\Plugin\Kensho\Model\KenshoDataModel;
use Kanboard\Plugin\Kensho\Model\KenshoProfileModel;
use Kanboard\Plugin\Kensho\Model\KenshoScoreModel;

/**
 * KenshoController — personal reflection dashboard + mutations + AI actions.
 *
 * Every action operates on the current user's OWN data (userSession id); there
 * is no project-admin gate (login is enforced by the router). Every mutation
 * calls checkCSRFForm(); the three AI actions additionally require the gate to
 * be open and re-check it (degrade to manual when AiConnector is absent).
 */
class KenshoController extends BaseController
{
    // ── Dashboard ──────────────────────────────────────────────────────────────

    public function show(): void
    {
        $userId = $this->userSession->getId();
        $profile = $this->profileModel()->getProfile($userId);
        $aggregate = $this->dataModel()->aggregate($userId, $profile['window_days']);
        $alignment = KenshoScoreModel::alignment($profile['areas'], $aggregate['dimensions']);

        $aiReady = $this->isAiEnabled();
        $profiles = [];
        $defaultProfileId = '';
        if ($aiReady) {
            $registry = new \Kanboard\Plugin\AiConnector\Model\ProviderRegistry($this->container);
            $profiles = $registry->listProfiles();
            $defaultProfileId = $registry->getDefaultProfileId();
        }

        $this->response->html($this->helper->layout->app('Kensho:dashboard/show', [
            'title'              => t('Kensho'),
            'profile'            => $profile,
            'aggregate'          => $aggregate,
            'alignment'          => $alignment,
            'ai_ready'           => $aiReady,
            'profiles'           => $profiles,
            'default_profile_id' => $defaultProfileId,
        ]));
    }

    // ── Mutations (CSRF on every one) ──────────────────────────────────────────

    public function analyze(): void
    {
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $profile = $this->profileModel()->getProfile($userId);
        $profile['window_days'] = $this->postInt('window_days', $profile['window_days']);
        $profile['settings']['show_score'] = $this->postInt('show_score', $profile['settings']['show_score'] ? 1 : 0) === 1;
        $this->profileModel()->saveProfile($userId, $profile);
        $this->response->redirect($this->helper->url->to('KenshoController', 'show', ['plugin' => 'Kensho']));
    }

    public function areas(): void
    {
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $areas = $this->decodeJsonParam('areas');

        $profile = $this->profileModel()->getProfile($userId);
        $profile['areas'] = is_array($areas) ? $areas : [];
        $this->profileModel()->saveProfile($userId, $profile); // saveProfile sanitizes each area
        $this->flash->success(t('Your areas were saved.'));
        $this->response->redirect($this->helper->url->to('KenshoController', 'show', ['plugin' => 'Kensho']));
    }

    public function goals(): void
    {
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $updates = $this->decodeJsonParam('goals'); // [{id, importance, identity, goals:[]}, ...]

        if (is_array($updates)) {
            foreach ($updates as $u) {
                if (! is_array($u) || empty($u['id'])) {
                    continue;
                }
                $profile = $this->profileModel()->getProfile($userId);
                foreach ($profile['areas'] as $existing) {
                    if ($existing['id'] === $u['id']) {
                        $merged = array_merge($existing, [
                            'importance' => (int) ($u['importance'] ?? $existing['importance']),
                            'identity'   => (string) ($u['identity'] ?? $existing['identity']),
                            'goals'      => is_array($u['goals'] ?? null) ? $u['goals'] : $existing['goals'],
                        ]);
                        $this->profileModel()->upsertArea($userId, $merged);
                        break;
                    }
                }
            }
        }
        $this->flash->success(t('Your goals were saved.'));
        $this->response->redirect($this->helper->url->to('KenshoController', 'show', ['plugin' => 'Kensho']));
    }

    public function reflection(): void
    {
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $this->profileModel()->addReflection($userId, [
            'date'             => time(),
            'window_days'      => $this->postInt('window_days', 90),
            'score'            => $this->postString('score', '') !== '' ? $this->postInt('score', 0) : null,
            'discrepancies'    => $this->decodeJsonParam('discrepancies') ?: [],
            'narrative'        => $this->postString('narrative', ''),
            'provider_profile' => $this->postString('provider_profile', ''),
        ]);
        $this->flash->success(t('Reflection saved.'));
        $this->response->redirect($this->helper->url->to('KenshoController', 'show', ['plugin' => 'Kensho']));
    }

    // ── AI actions (CSRF + gate; JSON responses) ──────────────────────────────

    public function propose(): void
    {
        $this->requireAi();
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $profile = $this->profileModel()->getProfile($userId);
        $aggregate = $this->dataModel()->aggregate($userId, $profile['window_days']);
        $out = $this->aiModel()->proposeAreas($aggregate, $this->resolveProfileId());
        $this->response->json($out);
    }

    public function clarify(): void
    {
        $this->requireAi();
        $this->checkCSRFForm();
        $out = $this->aiModel()->clarifyGoals(
            $this->postString('area_label', ''),
            $this->postString('note', ''),
            $this->resolveProfileId()
        );
        $this->response->json($out);
    }

    public function reflect(): void
    {
        $this->requireAi();
        $this->checkCSRFForm();
        $userId = $this->userSession->getId();
        $profile = $this->profileModel()->getProfile($userId);
        $aggregate = $this->dataModel()->aggregate($userId, $profile['window_days']);
        $alignment = KenshoScoreModel::alignment($profile['areas'], $aggregate['dimensions']);

        $identities = [];
        foreach ($profile['areas'] as $a) {
            if ($a['identity'] !== '') {
                $identities[$a['id']] = $a['identity'];
            }
        }
        $out = $this->aiModel()->reflect($alignment['rows'], $identities, $this->resolveProfileId());
        $this->response->json($out);
    }

    // ── Assets (buildless; served with correct content-type) ───────────────────

    public function css(): void
    {
        $this->response->css(file_get_contents(__DIR__ . '/../Assets/css/kensho.css'));
    }

    public function js(): void
    {
        $this->response->js(file_get_contents(__DIR__ . '/../Assets/js/kensho.js'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Reject with 403 unless the AI gate is open. Mirrors GeneratorController. */
    private function requireAi(): void
    {
        if (! $this->isAiEnabled()) {
            throw new AccessForbiddenException();
        }
    }

    protected function isAiEnabled(): bool
    {
        return KenshoAiGate::isReady($this->container);
    }

    /** Validate the posted provider profile id against known ids (else null). */
    private function resolveProfileId(): ?string
    {
        $profileId = trim($this->postString('provider_profile', ''));
        if ($profileId === '') {
            return null;
        }
        $registry = new \Kanboard\Plugin\AiConnector\Model\ProviderRegistry($this->container);
        $known = array_column($registry->listProfiles(), 'id');
        return in_array($profileId, $known, true) ? $profileId : null;
    }

    private function decodeJsonParam(string $name)
    {
        $raw = $this->postString($name, '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Read a POST body field directly (bypasses request->getStringParam(), which
     * only reads $_GET — see Request::getStringParam() in core). checkCSRFForm()
     * already validated the request via the csrf_token field before any of these
     * are read, so reading raw POST values here is safe.
     */
    private function postString(string $name, string $default = ''): string
    {
        $value = $this->request->getRawValue($name);
        return is_scalar($value) ? (string) $value : $default;
    }

    private function postInt(string $name, int $default = 0): int
    {
        $value = $this->request->getRawValue($name);
        return is_numeric($value) ? (int) $value : $default;
    }

    protected function profileModel(): KenshoProfileModel
    {
        return new KenshoProfileModel($this->container);
    }

    protected function dataModel(): KenshoDataModel
    {
        return new KenshoDataModel($this->container);
    }

    protected function aiModel(): KenshoAiModel
    {
        return new KenshoAiModel($this->container);
    }
}
