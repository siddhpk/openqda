<?php

namespace App\Http\Controllers;

use App\Models\AiUsageLog;
use App\Models\Project;
use App\Models\ProjectAiSetting;
use App\Services\AI\AiException;
use App\Services\AI\AiManager;
use App\Services\AI\EmbeddingService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints of the optional AI layer (text embeddings for autocoding).
 * All of them answer 404 while AI_ENABLED is false and 403 while the
 * project is not opted in for the currently configured embedding service.
 */
class AiController extends Controller
{
    /** usage of the current request (filled by the actions) */
    protected array $usage = ['items' => 0, 'characters' => 0, 'redactions' => 0];

    public function __construct(protected AiManager $ai) {}

    public function status(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->statusPayload($project));
    }

    /**
     * Owner opts the project in or out. Opting in to a configuration with a
     * third-party (cloud) provider requires an explicit acknowledgement.
     * Opting out purges the project's cached embeddings.
     */
    public function consent(Request $request, Project $project, EmbeddingService $embeddings): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($this->ai->isEnabled(), 404);
        abort_unless($this->canChangeConsent($project), 403, 'Only the project owner can change this setting.');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'acknowledge_cloud' => ['sometimes', 'boolean'],
        ]);

        if ($data['enabled'] && $this->ai->blockedByLocalOnly()) {
            return response()->json([
                'message' => 'The configured providers include a third-party cloud service, but AI_LOCAL_ONLY is on. Use a local provider or ask an administrator to change the setting.',
            ], 422);
        }

        if ($data['enabled'] && $this->ai->usesCloud() && empty($data['acknowledge_cloud'])) {
            return response()->json([
                'message' => 'This configuration sends data to a third-party provider. Please confirm that this is covered by your consent forms and data protection agreements.',
            ], 422);
        }

        $setting = ProjectAiSetting::firstOrNew(['project_id' => $project->id]);
        $setting->enabled = $data['enabled'];
        $setting->changed_by = Auth::id();
        if ($data['enabled']) {
            $setting->consent_fingerprint = $this->ai->configFingerprint();
            $setting->cloud_acknowledged_at = $this->ai->usesCloud() ? now() : null;
        } else {
            $setting->consent_fingerprint = null;
            $setting->cloud_acknowledged_at = null;
            $embeddings->purge($project);
        }
        $setting->save();

        return response()->json($this->statusPayload($project));
    }

    /**
     * Owner maintains the project's list of terms to pseudonymise.
     */
    public function redactionTerms(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($this->ai->isEnabled(), 404);
        abort_unless($this->canChangeConsent($project), 403, 'Only the project owner can change this setting.');

        $data = $request->validate([
            'terms' => ['present', 'array', 'max:500'],
            'terms.*' => ['string', 'min:2', 'max:100'],
        ]);

        $setting = ProjectAiSetting::firstOrNew(['project_id' => $project->id]);
        $setting->redaction_terms = array_values(array_unique(array_map('trim', $data['terms'])));
        $setting->changed_by = Auth::id();
        $setting->save();

        return response()->json($this->statusPayload($project));
    }

    /**
     * Owner deletes all cached embeddings of the project.
     */
    public function purge(Project $project, EmbeddingService $embeddings): JsonResponse
    {
        $this->authorize('view', $project);
        abort_unless($this->ai->isEnabled(), 404);
        abort_unless($this->canChangeConsent($project), 403, 'Only the project owner can change this setting.');

        return response()->json(['deleted' => $embeddings->purge($project)]);
    }

    public function embed(Request $request, Project $project, EmbeddingService $embeddings): JsonResponse
    {
        $max = $this->ai->limit('max_texts_per_request', 256);
        $maxChars = $this->ai->limit('max_chars_per_text', 8000);
        $data = $request->validate([
            'texts' => ['required', 'array', 'min:1', "max:{$max}"],
            'texts.*' => ['required', 'string', "max:{$maxChars}"],
        ]);

        return $this->guarded($project, 'embed', function () use ($project, $data, $embeddings) {
            $result = $embeddings->embedMany($project, $data['texts']);
            $this->usage = [
                'items' => count($data['texts']) - $result['cached'],
                'characters' => $result['characters'],
                'redactions' => $result['redactions'],
            ];

            return [
                'provider' => $this->ai->embeddingProviderName(),
                'model' => $this->ai->embeddingModel(),
                'dimensions' => $result['dimensions'],
                'encoding' => 'float32-le-base64',
                'vectors' => array_map('base64_encode', $result['vectors']),
            ];
        });
    }

    /**
     * Shared guard: global flag, membership, project opt-in, error mapping, usage log.
     */
    protected function guarded(Project $project, string $action, Closure $fn): JsonResponse
    {
        abort_unless($this->ai->isEnabled(), 404);
        $this->authorize('view', $project);
        abort_unless($this->ai->isEnabledForProject($project), 403, 'AI features are not enabled for this project and provider configuration.');

        try {
            $result = $fn();
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $this->logUsage($project, $action);

        return response()->json($result);
    }

    protected function logUsage(Project $project, string $action): void
    {
        $provider = $this->ai->embeddingProviderName();
        $model = $this->ai->embeddingModel();

        // counts only, never content
        AiUsageLog::create([
            'project_id' => $project->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'provider' => $provider,
            'model' => $model,
            'cloud' => $this->ai->isCloud($provider),
            'items' => $this->usage['items'],
            'characters' => $this->usage['characters'],
            'redactions' => $this->usage['redactions'],
        ]);
        Log::info('ai.request', ['action' => $action, 'project' => $project->id, 'user' => Auth::id(), 'provider' => "{$provider}:{$model}"]);
    }

    protected function statusPayload(Project $project): array
    {
        $setting = ProjectAiSetting::for($project);
        $owner = $this->canChangeConsent($project);
        $since = now()->subDays(30);
        $usage = AiUsageLog::where('project_id', $project->id)->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as requests, COALESCE(SUM(characters),0) as characters, COALESCE(SUM(redactions),0) as redactions, COALESCE(SUM(CASE WHEN cloud THEN characters ELSE 0 END),0) as cloud_characters')
            ->first();

        return $this->ai->describe($project) + [
            'canChangeConsent' => $owner,
            'consentState' => $this->ai->consentState($project, $setting),
            'redactionTerms' => $owner ? ($setting?->terms() ?? []) : null,
            'redactionTermsCount' => count($setting?->terms() ?? []),
            'usage30d' => [
                'requests' => (int) ($usage->requests ?? 0),
                'characters' => (int) ($usage->characters ?? 0),
                'cloudCharacters' => (int) ($usage->cloud_characters ?? 0),
                'redactions' => (int) ($usage->redactions ?? 0),
            ],
        ];
    }

    protected function canChangeConsent(Project $project): bool
    {
        $user = Auth::user();

        return $user && ($user->id === $project->creating_user_id || in_array($user->email, (array) config('app.admins'), true));
    }
}
