<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use App\Services\Collab\Collab;

class NotePolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any notes in the project.
     */
    public function viewAny(User $user, Project $project): bool
    {
        return $this->isUserInProjectOrTeam($user, null, $project) || in_array($user->email, $this->allowedEmails);
    }

    /**
     * Determine whether the user can view the note.
     */
    public function view(User $user, Note $note): bool
    {
        $project = $note->project;

        if (in_array($user->email, $this->allowedEmails)) {
            return true;
        }

        if (! $this->isUserInProjectOrTeam($user, null, $project)) {
            return false;
        }

        // User can view their own note, or any note shared with the team (visibility = 1).
        return $user->id === $note->creating_user_id || $note->visibility === 1;
    }

    /**
     * Determine whether the user can create notes in the project.
     */
    public function create(User $user, Project $project): bool
    {
        return $this->isUserInProjectOrTeam($user, null, $project) || in_array($user->email, $this->allowedEmails);
    }

    /**
     * Determine whether the user can update the note.
     */
    public function update(User $user, Note $note): bool
    {
        $project = $note->project;

        return ($this->isUserInProjectOrTeam($user, null, $project) && $user->id === $note->creating_user_id)
            || $this->sharedInCollaboration($user, $note)
            || in_array($user->email, $this->allowedEmails);
    }

    /**
     * Laptop collaboration: shared memos of a collaborative project may be
     * edited by its members here; the change then follows the roles
     * (a Primary's merges, an Assistant's waits for review).
     */
    private function sharedInCollaboration(User $user, Note $note): bool
    {
        return $note->visibility === 1 && Collab::isCollaborative($note->project_id)
            && $this->isUserInProjectOrTeam($user, null, $note->project);
    }

    /**
     * Determine whether the user can delete the note.
     */
    public function delete(User $user, Note $note): bool
    {
        $project = $note->project;

        return ($this->isUserInProjectOrTeam($user, null, $project) && $user->id === $note->creating_user_id)
            || $this->sharedInCollaboration($user, $note)
            || in_array($user->email, $this->allowedEmails);
    }
}
