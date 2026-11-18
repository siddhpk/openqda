<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\UserGlobalPreference;
use App\Models\UserProjectPreference;
use App\Services\Desktop\Channel;
use App\Services\ProjectTeamResolverService;
use App\Services\Qda\Vocabulary;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private readonly ProjectTeamResolverService $teamResolver)
    {
        // parent Inertia\Middleware has no constructor arguments
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     */
    public function share(Request $request): array
    {
        // Get the current URL
        $currentUrl = $request->fullUrl();

        // Parse the URL to get the path segments
        $path = parse_url($currentUrl, PHP_URL_PATH);
        $segments = explode('/', $path);

        // Find the project ID segment in the URL - assuming the URL structure is fixed
        $projectIdIndex = array_search('projects', $segments) + 1;
        $projectId = $segments[$projectIdIndex] ?? null;

        // Now that we have the project ID, we can find the project and its team
        $team = null;
        if ($projectId && is_numeric($projectId)) {
            $project = Project::find($projectId);
            $team = $project?->team;
        }

        // Due to the collaborative setting,
        // we need to resolve the team and team members for nearly every request.
        // If no team exists or the user is not part of any team, these will simply be null/empty.
        ['team' => $team, 'teamMembers' => $teamMembers] = $this->teamResolver->resolveFromRequest($request);

        $privacyFile = resource_path('markdown/privacy.md');
        $termsFile = resource_path('markdown/terms.md');
        $privacy = file_exists($privacyFile) ? date('c', filemtime($privacyFile)) : null;
        $terms = file_exists($termsFile) ? date('c', filemtime($termsFile)) : null;

        return array_merge(parent::share($request), [
            // Synchronously...
            'logo' => asset(config('app.logo')),
            'appName' => config('app.name'),
            'flash' => [
                'message' => session('message'),
            ],
            'privacy' => $privacy,
            'terms' => $terms,
            // laptop edition: show the Sharing page only to the person at this computer
            'desktop' => [
                'enabled' => Channel::desktop(),
                'host' => Channel::isHost($request),
            ],
            'projectId' => session('projectId'),
            'sharedTeam' => $team?->only('id', 'name'),
            'usersInPages' => [],
            'teamMembers' => $teamMembers,
            // optional AI layer: only the global flag, project opt-in is fetched on demand
            'ai' => ['enabled' => (bool) config('ai.enabled', false)],
            // Lazily...
            'auth.user' => fn () => $request->user()
                ? $request->user()->only('id', 'name', 'email', 'profile_photo_url', 'research_requested', 'research_consent', 'privacy_consent', 'terms_consent', 'email_verified_at')
                : null,
            'preferences' => fn () => $request->user() ? [
                'global' => UserGlobalPreference::where('user_id', $request->user()->id)->first(),
                'project' => ($projectId && is_numeric($projectId))
                    ? UserProjectPreference::where('user_id', $request->user()->id)
                        ->where('project_id', (int) $projectId)
                        ->first()
                    : null,
            ] : null,
            // Analysis Toolkit: the vocabulary of the project shown on this page (if any)
            'vocabulary' => function () use ($request) {
                $project = $request->route('project');
                if (! $project instanceof Project) {
                    $project = is_scalar($project) ? Project::find($project) : null;
                }
                if (! $project || ! $request->user()?->can('view', $project)) {
                    return null;
                }

                return Vocabulary::for($project)->toArray();
            },
        ], [
            'shouldInterpolate' => true,
        ]);
    }
}
