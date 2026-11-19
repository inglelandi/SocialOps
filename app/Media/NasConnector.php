<?php

namespace App\Media;

use App\Http\Requests\InboxUploadRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Copies a photo or video from the office NAS through the Inglelandi NAS connector (D30, github.com/inglelandi/nas-connector).
 * Claude asks the connector for a one-time link (its get_download_link tool) and hands it to attach-nas-file; this server
 * fetches it with the download key the connector and Social Ops share (NAS_DOWNLOAD_KEY, the connector's
 * secrets/download_key), so a link is of no use to anyone else. Only links on the connector's own address
 * (NAS_CONNECTOR_URL) are fetched: a tool call cannot make the server download from anywhere else. The NAS stays read-only.
 */
final class NasConnector
{
    /** The header the connector reads the key from. */
    public const HEADER = 'X-Download-Key';

    /** Seconds for one file over the tunnel. */
    public const TIMEOUT = 120;

    /** The connector's address without a trailing slash, null when none is set. */
    public static function url(): ?string
    {
        $url = rtrim(trim((string) config('services.nas.url')), '/');

        return preg_match('#^https?://[^/\s]+$#', $url) ? $url : null;
    }

    public static function configured(): bool
    {
        return self::url() !== null && strlen(trim((string) config('services.nas.download_key'))) >= 32;
    }

    /** Why Social Ops cannot copy files from the NAS, for the tools (English, as every tool message); null when it can. */
    public static function problem(): ?string
    {
        return self::configured() ? null : 'Social Ops cannot copy files from the NAS yet: NAS_CONNECTOR_URL (the connector\'s address) and NAS_DOWNLOAD_KEY (the connector\'s download key, 32 characters or more) are not both set in its Settings. Leave the post without files and say so.';
    }

    /** A link the connector made: on its address, under /download/, one token and nothing else. */
    public static function isLink(string $url): bool
    {
        $base = self::url();

        return $base !== null && preg_match('#^'.preg_quote($base, '#').'/download/[A-Za-z0-9_-]{16,}$#', trim($url)) === 1;
    }

    /**
     * The file behind a link, as an upload to file through Client::storeAsset(); the caller unlinks it when done.
     *
     * @throws RuntimeException in words Claude can act on: the wrong key, a used link, a file too big or not a photo or video
     */
    public static function fetch(string $url, string $name): UploadedFile
    {
        throw_unless(self::configured(), new RuntimeException(self::problem()));
        throw_unless(self::isLink($url), new RuntimeException('Only links the connector made are fetched ('.self::url().'/download/…): ask get_download_link for one.'));
        $max = InboxUploadRequest::MAX_KB * 1024;
        $tooBig = new RuntimeException('Too big: Social Ops takes files up to '.(InboxUploadRequest::MAX_KB / 1024).' MB. Pick another file.');
        $tmp = tempnam(sys_get_temp_dir(), 'nas');
        try {
            $response = Http::withHeaders([self::HEADER => trim((string) config('services.nas.download_key'))])->timeout(self::TIMEOUT)->withOptions(['stream' => true])->get(trim($url));
            throw_if($response->status() === 403, new RuntimeException('The connector refused the download key: NAS_DOWNLOAD_KEY in Social Ops\' Settings and secrets/download_key on the NAS must be the same, and the project on the NAS restarted after the file was added. Tell the team; leave the post without files.'));
            throw_if($response->status() === 410, new RuntimeException('The link expired or was used already: ask get_download_link for a new one and call attach-nas-file again.'));
            throw_unless($response->successful(), new RuntimeException("The connector answered {$response->status()} for this link."));
            throw_if((int) $response->header('Content-Length') > $max, $tooBig);
            $body = $response->toPsrResponse()->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $out = fopen($tmp, 'w');
            $bytes = 0;
            while (! $body->eof()) {
                $chunk = $body->read(1 << 20);
                $bytes += strlen($chunk);
                if ($bytes > $max) {
                    fclose($out);
                    throw $tooBig;
                }
                fwrite($out, $chunk);
            }
            fclose($out);
            $upload = new UploadedFile($tmp, $name, null, null, true);
            $mime = (string) $upload->getMimeType();
            throw_unless(in_array($mime, explode(',', InboxUploadRequest::MIMES), true), new RuntimeException("\"{$name}\" is not a photo or video Social Ops takes ({$mime}); JPEG, PNG, WebP, HEIC, MP4 and MOV are."));

            return $upload;
        } catch (ConnectionException $e) {
            @unlink($tmp);
            throw new RuntimeException('The connector could not be reached ('.$e->getMessage().'). Try once more later; leave the post without files for now.');
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }
}
