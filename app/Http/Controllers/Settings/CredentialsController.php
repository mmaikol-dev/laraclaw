<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CredentialsUpdateRequest;
use App\Services\Tools\CredentialsFile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CredentialsController extends Controller
{
    /**
     * Show the credentials file editor.
     */
    public function edit(): Response
    {
        $path = CredentialsFile::path();

        return Inertia::render('settings/credentials', [
            'path' => $path,
            'exists' => is_file($path),
            'content' => $this->read(),
        ]);
    }

    /**
     * Persist the credentials file.
     *
     * The file always lives at the configured AGENT_CREDENTIALS_FILE path; the
     * editor cannot target any other location on disk.
     */
    public function update(CredentialsUpdateRequest $request): RedirectResponse
    {
        $path = CredentialsFile::path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return back()->withErrors([
                'content' => "Could not create the credentials directory at {$directory}.",
            ]);
        }

        if (file_put_contents($path, $request->validated('content')) === false) {
            return back()->withErrors([
                'content' => "Could not write the credentials file at {$path}.",
            ]);
        }

        @chmod($path, 0600);

        return back()->with('status', 'Credentials saved to '.$path);
    }

    /**
     * Read the credentials file, returning an empty string when absent.
     */
    private function read(): string
    {
        $path = CredentialsFile::path();

        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $content = file_get_contents($path);

        return $content === false ? '' : $content;
    }
}
