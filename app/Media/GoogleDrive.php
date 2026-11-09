<?php

namespace App\Media;

use App\Models\Account;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Google Drive API as our own service account: a client shares a folder with its address, the hourly pull files new photos
 * and videos in the inbox (files()), and the reference folder of the Files tab is read as text for the planner (documents(),
 * export()). Read only, nothing is written to Drive.
 */
final class GoogleDrive
{
    private const API = 'https://www.googleapis.com/drive/v3/';

    /** The address a folder must be shared with, empty until GOOGLE_SERVICE_ACCOUNT_JSON points to a key file. */
    public static function email(): string
    {
        $path = (string) config('services.google.service_account');

        return $path ? (string) (json_decode((string) @file_get_contents($path), true)['client_email'] ?? '') : '';
    }

    /** The folder id out of a pasted Drive link, or the bare id. */
    public static function folderId(string $link): string
    {
        return preg_match('~/folders/([\w-]+)~', $link, $m) ? $m[1] : trim(explode('?', trim($link))[0]);
    }

    /** The folder as Drive describes it (id, name), when it is a folder the service account can see. */
    public function folder(string $id): array
    {
        $file = $this->call("files/{$id}", ['fields' => 'id,name,mimeType']);
        throw_unless($file['mimeType'] === 'application/vnd.google-apps.folder', new RuntimeException('Google Drive: that link is a file, not a folder.'));

        return $file;
    }

    /**
     * The photos and videos directly in the watched folder (`external_id`).
     *
     * @return list<array{id: string, name: string, mime: string}>
     */
    public function files(Account $account): array
    {
        // shortcut: subfolders are not read, a client drops files in the shared folder itself; walk them when a client asks for albums
        $files = [];
        $page = null;
        do {
            $result = $this->call('files', array_filter(['q' => "'{$account->external_id}' in parents and trashed = false and (mimeType contains 'image/' or mimeType contains 'video/')", 'fields' => 'nextPageToken,files(id,name,mimeType)', 'pageSize' => 1000, 'includeItemsFromAllDrives' => 'true', 'pageToken' => $page]));
            foreach ($result['files'] ?? [] as $file) {
                $files[] = ['id' => $file['id'], 'name' => $file['name'], 'mime' => $file['mimeType']];
            }
        } while ($page = $result['nextPageToken'] ?? null);

        return $files;
    }

    /** The file's bytes, to be streamed from the response body. */
    public function download(Account $account, string $id): Response
    {
        $response = $this->http()->timeout(900)->get(self::API."files/{$id}", ['alt' => 'media', 'supportsAllDrives' => 'true']);
        throw_unless($response->successful(), new RuntimeException("Google Drive: download of {$id} failed ({$response->status()})."));

        return $response;
    }

    /** Subfolders of the reference folder are read this deep, and this many files at most: a client's folder, not its whole Drive. */
    public const DOCUMENTS_DEPTH = 3;

    public const DOCUMENTS_MAX = 300;

    /** What Drive exports a Google-native file as; Sheets come out as the first sheet only. */
    public const EXPORTS = [
        'application/vnd.google-apps.document' => 'text/plain',
        'application/vnd.google-apps.spreadsheet' => 'text/csv',
        'application/vnd.google-apps.presentation' => 'text/plain',
    ];

    /** Google-native types that are no document: shortcuts (their target is listed where it lives), forms, sites, drawings, maps, jams. */
    private const NOT_DOCUMENTS = ['application/vnd.google-apps.shortcut', 'application/vnd.google-apps.form', 'application/vnd.google-apps.site', 'application/vnd.google-apps.drawing', 'application/vnd.google-apps.map', 'application/vnd.google-apps.jam'];

    /**
     * The documents of the reference folder (`external_id`) and its subfolders, DOCUMENTS_DEPTH deep and DOCUMENTS_MAX files at
     * most: everything but photos, videos and audio (the inbox's business), shortcuts, forms and sites.
     *
     * @return list<array{id: string, name: string, mime: string, modified: ?string, size: ?int, path: string}>
     */
    public function documents(Account $account): array
    {
        $files = [];
        $this->walk($account->external_id, '', 0, $files);

        return $files;
    }

    private function walk(string $folderId, string $path, int $depth, array &$files): void
    {
        $page = null;
        do {
            $result = $this->call('files', array_filter(['q' => "'{$folderId}' in parents and trashed = false", 'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,size,shortcutDetails)', 'pageSize' => 1000, 'includeItemsFromAllDrives' => 'true', 'orderBy' => 'folder,name', 'pageToken' => $page]));
            foreach ($result['files'] ?? [] as $file) {
                if (count($files) >= self::DOCUMENTS_MAX) {
                    return;
                }
                $mime = (string) $file['mimeType'];
                if ($mime === 'application/vnd.google-apps.folder') {
                    if ($depth < self::DOCUMENTS_DEPTH) {
                        $this->walk($file['id'], ltrim("{$path}/{$file['name']}", '/'), $depth + 1, $files);
                    }

                    continue;
                }
                if (in_array($mime, self::NOT_DOCUMENTS, true) || isset($file['shortcutDetails']) || preg_match('~^(image|video|audio)/~', $mime)) {
                    continue;
                }
                $files[] = ['id' => $file['id'], 'name' => $file['name'], 'mime' => $mime, 'modified' => $file['modifiedTime'] ?? null, 'size' => isset($file['size']) ? (int) $file['size'] : null, 'path' => $path];
            }
        } while ($page = $result['nextPageToken'] ?? null);
    }

    /** A Google Doc, Sheet or Slides file as text (EXPORTS); any other file goes through download(). */
    public function export(string $id, string $mime): string
    {
        $as = self::EXPORTS[$mime] ?? throw new RuntimeException("Google Drive: {$mime} cannot be exported as text.");
        $response = $this->http()->timeout(300)->get(self::API."files/{$id}/export", ['mimeType' => $as, 'supportsAllDrives' => 'true']);
        throw_unless($response->successful(), new RuntimeException("Google Drive: export of {$id} failed ({$response->status()})."));

        return $response->body();
    }

    /** A file Drive holds in its own format (Docs, Sheets, Slides…), to be exported rather than downloaded. */
    public static function isNative(string $mime): bool
    {
        return str_starts_with($mime, 'application/vnd.google-apps.');
    }

    private function call(string $path, array $query): array
    {
        $response = $this->http()->get(self::API.$path, $query + ['supportsAllDrives' => 'true']);
        throw_unless($response->successful(), new RuntimeException('Google Drive: '.($response->status() === 404 ? 'folder not found. Share it with '.self::email().' first.' : ($response->json('error.message') ?? $response->body()))));

        return $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::withToken(Cache::remember('google-drive-token', 3000, fn () => $this->token()));
    }

    /** A one-hour access token for the service account: a JWT signed with its key, exchanged at Google. */
    private function token(): string
    {
        $key = json_decode((string) @file_get_contents((string) config('services.google.service_account')), true);
        throw_unless(isset($key['client_email'], $key['private_key']), new RuntimeException('GOOGLE_SERVICE_ACCOUNT_JSON does not point to a service account key file (docs/pilot-setup.md).'));
        $encode = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $jwt = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode(['iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/drive.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600]));
        openssl_sign($jwt, $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt.'.'.$encode($signature)]);
        throw_unless($response->successful(), new RuntimeException('Google Drive login: '.($response->json('error_description') ?? $response->body())));

        return $response->json('access_token');
    }
}
