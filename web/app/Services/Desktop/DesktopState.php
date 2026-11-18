<?php

namespace App\Services\Desktop;

/**
 * Small JSON file shared between the app and the launcher:
 *
 *   sharing   - current sharing session (the launcher opens the network
 *               listener only while sharing.mode is "lan")
 *   autostart - start OpenQDA when the user logs in to the computer
 *   shutdown  - timestamp of a shutdown request from the host
 *
 * Writes are atomic (temporary file + rename) and the file is readable by
 * the current user only; the access code inside is encrypted with APP_KEY.
 */
class DesktopState
{
    public function path(): string
    {
        return config('desktop.state_file') ?: storage_path('app/desktop-state.json');
    }

    public function read(): array
    {
        $raw = @file_get_contents($this->path());
        $data = $raw ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->read(), $key, $default);
    }

    /** Apply $change to the current content under an exclusive lock. */
    public function update(callable $change): array
    {
        $path = $this->path();
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }
        $lock = fopen($path.'.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $data = $change($this->read());
            $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            @chmod($tmp, 0600);
            if (! @rename($tmp, $path)) {
                // Windows cannot rename over an open file: copy instead
                copy($tmp, $path);
                @unlink($tmp);
            }

            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
