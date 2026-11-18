<?php

namespace App\Policies;

use App\Models\CodeRelation;
use App\Models\Project;
use App\Models\User;

class CodeRelationPolicy extends BasePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->isUserInProjectOrTeam($user, null, $project) || in_array($user->email, $this->allowedEmails);
    }

    public function create(User $user, Project $project): bool
    {
        return $this->isUserInProjectOrTeam($user, null, $project) || in_array($user->email, $this->allowedEmails);
    }

    public function update(User $user, CodeRelation $relation): bool
    {
        return $this->isUserInProjectOrTeam($user, null, $relation->project) || in_array($user->email, $this->allowedEmails);
    }

    public function delete(User $user, CodeRelation $relation): bool
    {
        return $this->isUserInProjectOrTeam($user, null, $relation->project) || in_array($user->email, $this->allowedEmails);
    }
}
