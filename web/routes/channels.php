<?php

use App\Broadcasting\SourceConversionChannel;
use App\Models\Project;
use App\Models\Source;
use App\Models\Team;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('team.{team}', function ($user, Team $team) {

    if ($team->hasUser($user)) {

        return ['name' => $user->name, 'id' => $user->id];
    }

});

Broadcast::channel('conversion.{project}', SourceConversionChannel::class);

// Live coding: who has a source open, and changes to its coded segments.
Broadcast::channel('coding.{source}', function ($user, Source $source) {
    $project = $source->project;
    if (! $project || ! $user->can('view', $project)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'profile_photo_url' => $user->profile_photo_url];
});

// Live coding: changes to the codes of a project.
Broadcast::channel('coding-project.{project}', function ($user, Project $project) {
    return $user->can('view', $project);
});
