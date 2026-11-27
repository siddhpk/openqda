<?php

namespace App\Services\Collab;

use App\Models\Project;
use App\Models\Team;
use App\Services\Collab\Merge\ReviewPolicy;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invitations to a collaborative project.
 *
 * A Primary creates an invitation for a role. It is a text to send
 * privately: "openqda-invite:" + base64url(JSON) with the project, the
 * founder, the inviter's node and network address, a single-use secret
 * (only its hash is kept) and, optionally, a one-time Tailscale key so the
 * invited person's OpenQDA joins the team network without a Tailscale
 * account. The key belongs to the project invitation, not to a person.
 *
 * Two ways to reach each other:
 *   share    (default) everyone keeps their own Tailscale account and network;
 *            the invitation carries a share link for the inviter's OpenQDA
 *            device, which the invited person accepts once. Nobody
 *            administers anyone else's account or devices.
 *   network  the invited laptop joins the inviter's network, with a one-time
 *            key or after a Tailscale user invitation (the inviter is the
 *            admin of that network).
 *
 * The invited laptop presents the secret to the inviter over the private
 * network (through the node agents); the inviter adds it to the roster and
 * answers with a welcome. Then the project replicates.
 */
final class Invites
{
    public const PREFIX = 'openqda-invite:';

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64url(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }

    /**
     * @return array{text: string, invite: object}
     */
    public static function create(Project $project, int $userId, string $role, string $keyMode, ?string $pastedKey = null, ?string $shareLink = null): array
    {
        $gid = Collab::projectGid($project->id);
        if (! $gid || Roster::myRole($gid) !== ReviewPolicy::PRIMARY) {
            throw ValidationException::withMessages(['invite' => 'Only a Primary can invite people to this project.']);
        }
        if (! in_array($role, [ReviewPolicy::PRIMARY, ReviewPolicy::ASSISTANT], true)) {
            throw ValidationException::withMessages(['role' => 'Choose Primary or Assistant.']);
        }
        $ts = ControlClient::status()['tailscale'] ?? [];
        if (($ts['state'] ?? '') !== 'Running' || empty($ts['ip'])) {
            throw ValidationException::withMessages(['invite' => 'Connect this computer to Tailscale first.']);
        }
        $share = $keyMode === 'share' ? self::checkShareLink($shareLink) : null;
        $key = match ($keyMode) {
            'auto' => TailscaleApi::createAuthKey(),
            'paste' => self::checkKey($pastedKey),
            default => null,
        };
        $token = random_bytes(32);
        $expires = now()->addDays((int) config('collab.invite_days', 7));
        $id = DB::table('collab_invites')->insertGetId(['project_gid' => $gid, 'token_hash' => hash('sha256', $token), 'role' => $role,
            'key_mode' => $share ? 'share' : ($key ? $keyMode : 'none'), 'created_by' => $userId, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
        $collab = Collab::project($gid);
        $identity = Collab::identity();
        $payload = [
            'v' => 1,
            'p' => $gid,
            'pn' => $project->name,
            'f' => $collab->founder,
            'fp' => $collab->founder_pub,
            'i' => ['node' => $identity->node, 'pub' => $identity->pub, 'name' => Collab::displayName(), 'addr' => $ts['ip'], 'dns' => $ts['dns'] ?? ''],
            'tn' => $ts['tailnet'] ?? '',
            't' => self::b64url($token),
            'r' => $role,
            'k' => $key,
            'm' => $share ? 'share' : 'network',
            'sl' => $share,
            'x' => $expires->getTimestamp(),
        ];

        return ['text' => self::PREFIX.self::b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'invite' => DB::table('collab_invites')->find($id)];
    }

    /** A share link for this computer's OpenQDA device, made in the Tailscale admin console. */
    private static function checkShareLink(?string $link): string
    {
        $link = trim((string) $link);
        if (! preg_match('#^https://login\.tailscale\.com/\S{4,400}$#', $link)) {
            throw ValidationException::withMessages(['share_link' => 'Paste the share link of this computer from the Tailscale admin console (it starts with https://login.tailscale.com/).']);
        }

        return $link;
    }

    private static function checkKey(?string $key): string
    {
        $key = trim((string) $key);
        if (! preg_match('/^tskey-auth-[A-Za-z0-9-]+$/', $key)) {
            throw ValidationException::withMessages(['key' => 'Paste a Tailscale auth key (it starts with tskey-auth-).']);
        }

        return $key;
    }

    /** Decode and check an invitation text. */
    public static function parse(string $text): array
    {
        $text = preg_replace('/\s+/', '', trim($text));
        $raw = str_starts_with($text, self::PREFIX) ? self::unb64url(substr($text, strlen(self::PREFIX))) : false;
        $p = $raw ? json_decode($raw, true) : null;
        $valid = is_array($p) && ($p['v'] ?? null) === 1
            && preg_match('/^[0-9a-f-]{36}$/', (string) ($p['p'] ?? ''))
            && preg_match('/^n[0-9a-f]{20}$/', (string) ($p['f'] ?? ''))
            && is_array($p['i'] ?? null) && preg_match('/^n[0-9a-f]{20}$/', (string) ($p['i']['node'] ?? ''))
            && NodeIdentity::nodeOfPub((string) ($p['i']['pub'] ?? '')) === $p['i']['node']
            && NodeIdentity::nodeOfPub((string) ($p['fp'] ?? '')) === $p['f']
            && filter_var($p['i']['addr'] ?? '', FILTER_VALIDATE_IP)
            && in_array($p['r'] ?? null, [ReviewPolicy::PRIMARY, ReviewPolicy::ASSISTANT], true)
            && is_string($p['t'] ?? null) && is_int($p['x'] ?? null)
            && ($p['k'] === null || preg_match('/^tskey-auth-[A-Za-z0-9-]+$/', (string) $p['k']))
            && in_array($p['m'] ?? 'network', ['share', 'network'], true)
            && (($p['m'] ?? 'network') !== 'share' || preg_match('#^https://login\.tailscale\.com/\S+$#', (string) ($p['sl'] ?? '')));
        if (! $valid) {
            throw ValidationException::withMessages(['invite' => 'This is not an OpenQDA invitation. Paste the whole text you received.']);
        }
        if ($p['x'] < time()) {
            throw ValidationException::withMessages(['invite' => 'This invitation has expired. Ask for a new one.']);
        }
        if ($p['i']['node'] === Collab::node()) {
            throw ValidationException::withMessages(['invite' => 'This invitation was created on this computer.']);
        }

        return $p;
    }

    /**
     * Accept an invitation on this computer (the joining side).
     *
     * @return array{status: string, current?: string, target?: string, message?: string}
     */
    public static function accept(int $userId, string $text, bool $moveNetwork = false): array
    {
        $p = self::parse($text);
        if (Collab::projectId($p['p'])) {
            throw ValidationException::withMessages(['invite' => 'This project is already on this computer.']);
        }
        $ts = ControlClient::status()['tailscale'] ?? [];
        $running = ($ts['state'] ?? '') === 'Running';
        if (($p['m'] ?? 'network') === 'share') {
            // your own account and network; the inviter shares their computer with you
            if (! $running) {
                return ['status' => 'needs_signin',
                    'message' => 'Connect OpenQDA to your own Tailscale account first (above), then join again.'];
            }
            if (! empty($ts['tags'])) {
                return ['status' => 'needs_signin', 'message' => 'Only a person can accept a shared computer: sign out, connect again with your own Tailscale account, then join.'];
            }
            self::storeJoin($userId, $p);

            return ['status' => 'needs_share', 'share_link' => $p['sl'], 'project' => $p['p'], 'inviter' => $p['i']['name'],
                'message' => 'Open the share link from '.$p['i']['name'].' and press Accept. OpenQDA connects by itself afterwards.'];
        }
        $sameNetwork = $running && ($p['tn'] === '' || ($ts['tailnet'] ?? '') === $p['tn']);
        if (! $sameNetwork) {
            if (! $p['k']) {
                return ['status' => 'needs_signin', 'target' => $p['tn'],
                    'message' => 'Accept the Tailscale invitation from '.$p['i']['name'].', then connect this computer to Tailscale and join again.'];
            }
            if ($running && ! $moveNetwork) {
                return ['status' => 'needs_confirm', 'current' => $ts['tailnet'] ?? '', 'target' => $p['tn']];
            }
            $r = ControlClient::authKey($p['k'], true);
            if (! $r['ok']) {
                throw ValidationException::withMessages(['invite' => 'Joining the team network failed: '.($r['error'] ?? 'unknown error')]);
            }
        }
        self::storeJoin($userId, $p);

        return ['status' => 'waiting', 'project' => $p['p'], 'inviter' => $p['i']['name'], 'message' => 'Connecting to '.$p['i']['name'].'…'];
    }

    private static function storeJoin(int $userId, array $p): void
    {
        DB::table('collab_joins')->where('project_gid', $p['p'])->where('status', '!=', 'joined')->delete();
        DB::table('collab_joins')->insert(['user_id' => $userId, 'project_gid' => $p['p'], 'project_name' => mb_substr((string) $p['pn'], 0, 250),
            'invite' => Crypt::encryptString(json_encode($p)), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        ControlClient::rosterChanged();
    }

    /** Joins of a person for the Collaboration page (with the share link to accept). */
    public static function joinsOf(int $userId): array
    {
        return DB::table('collab_joins')->where('user_id', $userId)->where('status', '!=', 'joined')->orderByDesc('id')->get()
            ->map(function ($j) {
                try {
                    $p = json_decode(Crypt::decryptString($j->invite), true);
                } catch (\Throwable) {
                    $p = [];
                }

                return ['id' => $j->id, 'project_name' => $j->project_name, 'status' => $j->status, 'message' => $j->message,
                    'created_at' => $j->created_at, 'project_gid' => $j->project_gid, 'share_link' => ($p['m'] ?? null) === 'share' ? ($p['sl'] ?? null) : null,
                    'inviter' => $p['i']['name'] ?? null];
            })->all();
    }

    /** Pending joins for the node agent (what to present to whom). */
    public static function pendingJoins(): array
    {
        $out = [];
        foreach (DB::table('collab_joins')->where('status', 'pending')->get() as $j) {
            try {
                $p = json_decode(Crypt::decryptString($j->invite), true);
            } catch (\Throwable) {
                continue;
            }
            $out[] = ['id' => (int) $j->id, 'project' => $p['p'],
                'inviter' => ['node' => $p['i']['node'], 'pub' => $p['i']['pub'], 'name' => $p['i']['name'], 'role' => ReviewPolicy::PRIMARY,
                    'addr' => $p['i']['addr'], 'dns' => $p['i']['dns'] ?? ''],
                'invite' => ['p' => $p['p'], 't' => $p['t'], 'name' => Collab::displayName()]];
        }

        return $out;
    }

    /**
     * The inviting side: check a presented invitation and add the node.
     *
     * @return array welcome for the joiner
     */
    public static function join(array $req): array
    {
        $invite = (array) ($req['invite'] ?? []);
        $gid = (string) ($invite['p'] ?? '');
        $token = self::unb64url((string) ($invite['t'] ?? ''));
        $node = (string) ($req['node'] ?? '');
        $pub = (string) ($req['pub'] ?? '');
        if (! $token || NodeIdentity::nodeOfPub($pub) !== $node || ! Collab::projectId($gid)) {
            throw new \DomainException('this invitation is not valid');
        }
        if (Roster::myRole($gid) !== ReviewPolicy::PRIMARY) {
            throw new \DomainException('the inviter is no longer a Primary of this project');
        }
        $op = DB::transaction(function () use ($gid, $token, $node, $pub, $req, $invite) {
            $row = DB::table('collab_invites')->where('project_gid', $gid)->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $row || $row->revoked_at || $row->expires_at < now()) {
                throw new \DomainException('this invitation is not valid or has expired');
            }
            if ($row->used_at && $row->used_by !== $node) {
                throw new \DomainException('this invitation has already been used');
            }
            DB::table('collab_invites')->where('id', $row->id)->update(['used_at' => now(), 'used_by' => $node, 'updated_at' => now()]);
            $name = mb_substr(trim((string) ($invite['name'] ?? $req['name'] ?? '')), 0, 80) ?: 'Collaborator';
            $op = OpLog::append($gid, '_members', $node, 'put', ['role' => $row->role, 'name' => $name, 'pub' => $pub,
                'addr' => (string) ($req['addr'] ?? ''), 'dns' => (string) ($req['dns'] ?? '')]);
            Roster::forget($gid);

            return $op;
        });
        ControlClient::publish([['project' => $gid, 'ops' => [$op], 'events' => []]]);
        $collab = Collab::project($gid);
        $project = Project::find($collab->project_id);

        // the roster comes along, so the new member knows its peers right away
        $roster = DB::table('collab_ops')->where('project_gid', $gid)->where('tbl', '_members')->where('status', '!=', 'waiting')
            ->orderBy('hlc')->pluck('payload')->map(fn ($p) => json_decode($p, true))->all();

        return ['project' => ['gid' => $gid, 'name' => $project?->name, 'description' => $project?->description,
            'founder' => $collab->founder, 'founder_pub' => $collab->founder_pub], 'role' => $op['f']['role'], 'inviter' => Collab::node(),
            'roster' => $roster];
    }

    /** The joining side: the inviter accepted; create the local project. */
    public static function joined(int $joinId, array $welcome): void
    {
        $join = DB::table('collab_joins')->where('id', $joinId)->where('status', 'pending')->first();
        if (! $join) {
            return;
        }
        $p = json_decode(Crypt::decryptString($join->invite), true);
        $w = (array) ($welcome['project'] ?? []);
        if (($w['gid'] ?? null) !== $p['p'] || ($w['founder'] ?? null) !== $p['f'] || ($w['founder_pub'] ?? null) !== $p['fp']) {
            DB::table('collab_joins')->where('id', $joinId)->update(['status' => 'failed', 'message' => 'The answer did not match the invitation.']);

            return;
        }
        Engine::applying(function () use ($join, $p, $w) {
            Hlc::ensureSettingsRow();
            $projectId = DB::table('projects')->insertGetId(['name' => mb_substr((string) ($w['name'] ?: $p['pn']), 0, 250),
                'description' => $w['description'] ?? null, 'creating_user_id' => $join->user_id, 'origin' => 'collaboration',
                'created_at' => now(), 'updated_at' => now()]);
            $team = Team::forceCreate(['user_id' => $join->user_id, 'name' => mb_substr((string) ($w['name'] ?: $p['pn']), 0, 250), 'personal_team' => false]);
            DB::table('projects')->where('id', $projectId)->update(['team_id' => $team->id]);
            DB::table('collab_projects')->insert(['project_id' => $projectId, 'gid' => $p['p'], 'founder' => $p['f'], 'founder_pub' => $p['fp'],
                'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            Collab::reset();
            IdMap::put('projects', $projectId, $p['p']);
            DB::table('collab_joins')->where('id', $join->id)->update(['status' => 'joined', 'message' => null, 'updated_at' => now()]);
        });
        Applier::apply($p['p'], array_values((array) ($welcome['roster'] ?? [])));
    }

    public static function failed(int $joinId, string $message): void
    {
        DB::table('collab_joins')->where('id', $joinId)->where('status', 'pending')
            ->update(['status' => 'failed', 'message' => mb_substr($message, 0, 250), 'updated_at' => now()]);
    }
}
