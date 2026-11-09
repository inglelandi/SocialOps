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
 * export()); its designs are only listed, and their previews handed on as they come (preview()), never kept. The team's posts
 * slideshow of a client is one file read the same way (file()). Read only, nothing is written to Drive.
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

    /** The file id out of a pasted Google Slides, Docs, Sheets or Drive link (`/d/<id>/`, `/folders/<id>`, `?id=<id>`), or the bare id. */
    public static function fileId(string $link): string
    {
        // a folder link gives its id too, so file() can say it is a folder
        if (preg_match('~/d/([\w-]{10,})~', $link, $m) || preg_match('~/folders/([\w-]{10,})~', $link, $m) || preg_match('~[?&]id=([\w-]{10,})~', $link, $m)) {
            return $m[1];
        }

        return trim(explode('?', trim($link))[0]);
    }

    /**
     * The team's posts slideshow of a client (or a Doc, Sheet, PowerPoint or Word file in its place) as one entry shaped like
     * documents() lists them, when the service account can see it: shared with its address, or open to anyone with the link.
     *
     * @return array{id: string, name: string, mime: string, modified: ?string, size: ?int, path: string, design: bool, preview: bool}
     */
    public function file(string $id): array
    {
        $file = $this->call("files/{$id}", ['fields' => 'id,name,mimeType,modifiedTime,size'], 'file not found. Share it with '.self::email().' (Viewer is enough), or open it to anyone with the link, first.');
        $mime = (string) $file['mimeType'];
        throw_if($mime === 'application/vnd.google-apps.folder', new RuntimeException('Google Drive: that link is a folder; link the slideshow file itself.'));
        throw_unless(isset(self::EXPORTS[$mime]) || in_array($mime, self::OFFICE_TEXT, true), new RuntimeException('Google Drive: “'.$file['name'].'” is not a Google Slides, Docs or Sheets file, nor a PowerPoint, Word or Excel file.'));

        return ['id' => $file['id'], 'name' => $file['name'], 'mime' => $mime, 'modified' => $file['modifiedTime'] ?? null, 'size' => isset($file['size']) ? (int) $file['size'] : null, 'path' => '', 'design' => false, 'preview' => false];
    }

    /** Office files a posts slideshow may be instead of a Google file: PowerPoint, Word, Excel. */
    private const OFFICE_TEXT = [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

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

    /**
     * Subfolders of the reference folder are walked this deep, and this many files are listed at most, documents and designs
     * together: a client's folder, not its whole Drive. A client folder keeps its posts four or five levels down (Instagram
     * Posts / 2. October 2026 / Posts / Next posts); the listing is one `files` call per folder page, designs are never downloaded.
     */
    public const DOCUMENTS_DEPTH = 6;

    public const DOCUMENTS_MAX = 1000;

    /** What Drive exports a Google-native file as; Sheets come out as the first sheet only. */
    public const EXPORTS = [
        'application/vnd.google-apps.document' => 'text/plain',
        'application/vnd.google-apps.spreadsheet' => 'text/csv',
        'application/vnd.google-apps.presentation' => 'text/plain',
    ];

    /** Google-native types that are no document: shortcuts (their target is listed where it lives), forms, sites, drawings, maps, jams. */
    private const NOT_DOCUMENTS = ['application/vnd.google-apps.shortcut', 'application/vnd.google-apps.form', 'application/vnd.google-apps.site', 'application/vnd.google-apps.drawing', 'application/vnd.google-apps.map', 'application/vnd.google-apps.jam'];

    /**
     * Designs: files whose text is not read but whose picture Drive can preview (photos, graphics, PDFs, Illustrator,
     * Photoshop, InDesign, EPS, videos), for Claude to describe through the connector. By type, or by extension when Drive
     * only says application/octet-stream.
     */
    public const DESIGN_MIMES = ['application/pdf', 'application/postscript', 'application/illustrator', 'application/vnd.adobe.illustrator', 'application/x-illustrator', 'application/eps', 'application/x-eps', 'application/photoshop', 'application/x-photoshop', 'application/psd', 'application/x-indesign', 'application/vnd.adobe.indesign-idml-package'];

    public const DESIGN_EXTENSIONS = ['pdf', 'ai', 'eps', 'psd', 'psb', 'indd', 'idml', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'tif', 'tiff', 'svg', 'mp4', 'mov', 'm4v', 'webm'];

    /** Hosts a preview link may point to: the service account's token goes to Google only. */
    private const PREVIEW_HOSTS = ['googleusercontent.com', 'google.com', 'googleapis.com'];

    /** A file the reference folder lists as a design (its picture is described), not as a document (its text is read). */
    public static function isDesign(string $mime, string $name): bool
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if (self::isNative($mime) || str_starts_with($mime, 'audio/')) {
            return false;
        }

        return (bool) preg_match('~^(image|video)/~', $mime) || in_array($mime, self::DESIGN_MIMES, true) || in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::DESIGN_EXTENSIONS, true);
    }

    /**
     * The files of the reference folder (`external_id`) and its subfolders, DOCUMENTS_DEPTH deep and DOCUMENTS_MAX files at
     * most: the documents (read as text) and the designs (`design` true, `preview` when Drive has a thumbnail of it); no
     * audio, shortcuts, forms or sites. A folder's own files come before its subfolders', so a cap never drops the files at
     * the top. `capped` says the folder held more than DOCUMENTS_MAX.
     *
     * @return array{files: list<array{id: string, name: string, mime: string, modified: ?string, size: ?int, path: string, design: bool, preview: bool}>, capped: bool}
     */
    public function documents(Account $account): array
    {
        $files = [];
        $capped = $this->walk($account->external_id, '', 0, $files);

        return ['files' => $files, 'capped' => $capped];
    }

    /** One folder's files, then its subfolders' (DOCUMENTS_DEPTH deep for documents and designs alike); true when the cap stopped it. */
    private function walk(string $folderId, string $path, int $depth, array &$files): bool
    {
        $folders = [];
        $page = null;
        do {
            $result = $this->call('files', array_filter(['q' => "'{$folderId}' in parents and trashed = false", 'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,size,shortcutDetails,hasThumbnail,thumbnailLink)', 'pageSize' => 1000, 'includeItemsFromAllDrives' => 'true', 'orderBy' => 'folder,name', 'pageToken' => $page]));
            foreach ($result['files'] ?? [] as $file) {
                $mime = (string) $file['mimeType'];
                if ($mime === 'application/vnd.google-apps.folder') {
                    $folders[] = $file;

                    continue;
                }
                if (in_array($mime, self::NOT_DOCUMENTS, true) || isset($file['shortcutDetails']) || str_starts_with($mime, 'audio/')) {
                    continue;
                }
                if (count($files) >= self::DOCUMENTS_MAX) {
                    return true;
                }
                $design = self::isDesign($mime, (string) $file['name']);
                $files[] = ['id' => $file['id'], 'name' => $file['name'], 'mime' => $mime, 'modified' => $file['modifiedTime'] ?? null, 'size' => isset($file['size']) ? (int) $file['size'] : null, 'path' => $path,
                    'design' => $design, 'preview' => $design && (($file['hasThumbnail'] ?? false) === true || ! empty($file['thumbnailLink']))];
            }
        } while ($page = $result['nextPageToken'] ?? null);

        foreach ($depth < self::DOCUMENTS_DEPTH ? $folders : [] as $folder) {
            if ($this->walk($folder['id'], ltrim("{$path}/{$folder['name']}", '/'), $depth + 1, $files)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drive's own preview of a file (a graphic, a photo, a PDF's first page, a video frame) about $size pixels on its long
     * side, fetched now from a fresh thumbnail link with the service account's token and handed on as it comes: never
     * written to disk, the cache or the database.
     *
     * @return array{bytes: string, mime: string}
     *
     * @throws RuntimeException when Drive has no preview of the file or the fetch fails
     */
    public function preview(string $fileId, int $size = 1024): array
    {
        $file = $this->call("files/{$fileId}", ['fields' => 'hasThumbnail,thumbnailLink']);
        $link = (string) ($file['thumbnailLink'] ?? '');
        throw_if($link === '', new RuntimeException("Google Drive has no preview of {$fileId}."));
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        throw_unless(collect(self::PREVIEW_HOSTS)->contains(fn (string $h) => $host === $h || str_ends_with($host, ".{$h}")), new RuntimeException("Google Drive: the preview of {$fileId} points outside Google ({$host})."));

        $response = $this->http()->timeout(60)->get(self::sized($link, $size));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        throw_unless($response->successful() && str_starts_with($mime, 'image/'), new RuntimeException("Google Drive: the preview of {$fileId} could not be fetched ({$response->status()})."));

        return ['bytes' => $response->body(), 'mime' => $mime];
    }

    /** The thumbnail link at $size pixels: its size suffix (`=s220`, or `sz=s220` in the query) set to `=s{$size}`. */
    public static function sized(string $link, int $size): string
    {
        if (preg_match('~=s\d+(-[\w-]*)?$~', $link)) {
            return preg_replace('~=s\d+(-[\w-]*)?$~', "=s{$size}", $link);
        }
        if (preg_match('~[?&]sz=s?\d+~', $link)) {
            return preg_replace('~([?&]sz=)s?\d+~', '${1}s'.$size, $link);
        }

        return str_contains($link, '?') ? $link : "{$link}=s{$size}";
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

    private function call(string $path, array $query, ?string $notFound = null): array
    {
        $response = $this->http()->get(self::API.$path, $query + ['supportsAllDrives' => 'true']);
        throw_unless($response->successful(), new RuntimeException('Google Drive: '.($response->status() === 404 ? ($notFound ?? 'folder not found. Share it with '.self::email().' first.') : ($response->json('error.message') ?? $response->body()))));

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
