<?php

namespace App\Models;

use App\Http\Requests\InboxUploadRequest;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** A media file in a client's inbox. The file itself lives on `disk` at `path`. */
#[Fillable(['name', 'original_name', 'disk', 'path', 'mime', 'size', 'kind', 'pillar', 'season', 'people_consent', 'ugc_permission', 'note', 'uploaded_via', 'source_id'])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['people_consent' => 'boolean', 'ugc_permission' => 'boolean', 'size' => 'integer'];
    }

    /**
     * Every `uploaded_via` value with its label: the client's no-login link, a staff upload on the assets page,
     * a watched Google Drive or Dropbox folder, a Canva import, a Reel made from clips, a copy Claude made from the office NAS for a post.
     *
     * @return array<string, string>
     */
    public static function sources(): array
    {
        return ['link' => __('Client upload link'), 'staff' => __('Team upload'), 'drive' => 'Google Drive', 'dropbox' => 'Dropbox', 'canva' => 'Canva', 'reel' => __('Reel made here'), 'nas' => __('Office NAS')];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    /** The image as a JPEG no wider than $max on its long edge, for Claude to look at; null for videos and files GD cannot read. */
    /**
     * The file shown in the browser (the client's review page). On the local disk it is sent with byte ranges, which iPhones
     * need to play a video; the headers keep a browser from running a file that only pretends to be a photo.
     */
    public function inline(): Response
    {
        $media = in_array($this->mime, explode(',', InboxUploadRequest::MIMES), true) ? $this->mime : 'application/octet-stream';
        $headers = ['Content-Type' => $media, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox'];
        $disk = Storage::disk($this->disk);
        $path = rescue(fn () => $disk->path($this->path), null, false);

        return $path && is_file($path) ? response()->file($path, $headers) : $disk->response($this->path, null, $headers);
    }

    public function preview(int $max = 1568): ?string
    {
        $bytes = $this->kind === 'image' ? Storage::disk($this->disk)->get($this->path) : null;
        if (! $bytes || ! $img = @imagecreatefromstring($bytes)) {
            return null;
        }
        if (($scale = $max / max(imagesx($img), imagesy($img))) < 1) {
            $img = imagescale($img, (int) round(imagesx($img) * $scale), (int) round(imagesy($img) * $scale));
        }
        ob_start();
        imagejpeg($img, null, 80);

        return ob_get_clean();
    }

    /** A URL the platforms can fetch the file from: a 2-hour signed URL, or the disk's plain URL when it cannot sign one. */
    public function publicUrl(): string
    {
        try {
            return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addHours(2));
        } catch (RuntimeException) {
            return Storage::disk($this->disk)->url($this->path);
        }
    }
}
