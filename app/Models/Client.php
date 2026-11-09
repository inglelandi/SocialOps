<?php

namespace App\Models;

use App\Assets\AssetNamer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * A client account. Every other client-owned table (accounts, assets, posts)
 * carries a client_id so data stays isolated per client.
 */
#[Fillable(['slug', 'code', 'name', 'type', 'location', 'profile', 'status', 'owner', 'priority', 'plan_due', 'posts_per_week', 'stories_per_week', 'tiktoks_per_month', 'pending', 'links'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /** Suggestions for the type field, which takes any text. */
    public const TYPES = ['villa', 'hotel', 'apartments', 'rooms', 'beach_bar', 'restaurant', 'taverna', 'cafe', 'bar', 'club', 'winery', 'tour_operator', 'car_rental', 'shop', 'pharmacy', 'clinic', 'gym', 'spa', 'real_estate', 'brand'];

    /** Where the client stands for the agency, on the Clients board. */
    public const STATUSES = ['active' => 'Active', 'waiting_dates' => 'Client tells us when', 'waiting' => 'Waiting', 'seasonal' => 'Seasonal', 'stopped' => 'Stopped'];

    public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    protected function casts(): array
    {
        return ['profile' => 'array', 'plan_due' => 'date', 'links' => 'array'];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * File an upload in this client's inbox under the agency name and record it as an asset; $attrs adds pillar, tags, note,
     * uploaded_via (Asset::sources(), link by default) and source_id. A file whose source_id is filed already is not filed
     * again: the asset that has it comes back instead (wasRecentlyCreated false).
     */
    public function storeAsset(UploadedFile $file, array $attrs = []): Asset
    {
        $disk = config('filesystems.default');
        $dir = "clients/{$this->slug}/inbox";
        $source = $attrs['source_id'] ?? null;
        for ($attempt = 1; ; $attempt++) {
            if ($source !== null && $filed = $this->assets()->firstWhere('source_id', $source)) {
                return $filed;
            }
            // never over another file: a name whose file is already on the disk is skipped
            $name = AssetNamer::name($this, $file, $attrs['pillar'] ?? null, taken: fn (string $name) => Storage::disk($disk)->exists("$dir/$name"));
            try {
                // the row before the file: the unique name and source are taken first, so two uploads or folder pulls at once
                // can neither write over each other's file nor file one twice
                $asset = $this->assets()->create($attrs + [
                    'name' => $name,
                    'original_name' => $file->getClientOriginalName(),
                    'disk' => $disk,
                    'path' => "$dir/$name",
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'kind' => str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image',
                    'uploaded_via' => 'link',
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt < 3) {
                    continue; // taken a moment ago by another upload (the next version), or the source filed meanwhile (returned above)
                }
                throw $e;
            }
            try {
                Storage::disk($disk)->putFileAs($dir, $file, $name) ?: throw new RuntimeException("Could not write $dir/$name");
            } catch (Throwable $e) {
                $asset->delete();
                throw $e;
            }

            return $asset;
        }
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function deadlines(): HasMany
    {
        return $this->hasMany(Deadline::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * The brand profile in the shape of config/clients/example.yaml,
     * for the caption prompt and the publishing drivers.
     *
     * @return array<string, mixed>
     */
    /** The first weekly posting slot after $after (default now) with no post of this client on it; null when the profile has no slots. */
    /** Titles and trend signals of the last 90 days of posts, so a planner does not repeat an angle or a signal. */
    public function recentTopics(): array
    {
        // the planner writes the trend line in the client's language ("Τάση:" for a Greek-first client), so every label counts
        $labels = collect(array_keys(config('app.locales')))->map(fn (string $locale) => preg_quote(__('Trend:', [], $locale), '/'))->unique()->join('|');

        return $this->posts()->where('scheduled_at', '>=', now()->subDays(90))->orderByDesc('scheduled_at')->limit(60)->pluck('brief')
            ->map(fn (?string $brief) => strtok((string) $brief, "\n").(preg_match("/^(?:{$labels}) ([^.]+)/mu", (string) $brief, $m) ? " (trend: {$m[1]})" : ''))
            ->filter()->values()->all();
    }

    public function nextFreeSlot(?CarbonInterface $after = null): ?CarbonImmutable
    {
        $after = CarbonImmutable::instance($after ?? now());
        $slots = collect($this->profile['slots'] ?? [])->map(fn (string $slot) => explode(' ', strtolower($slot)));
        // shortcut: eight weeks ahead is enough for a planning batch; a full calendar is a different problem
        for ($day = $after->startOfDay(); $slots->isNotEmpty() && $day->lt($after->addWeeks(8)); $day = $day->addDay()) {
            foreach ($slots->where(0, strtolower($day->format('D')))->sortBy(1) as [, $time]) {
                $at = $day->setTimeFromTimeString($time);
                if ($at->gt($after) && ! $this->posts()->where('scheduled_at', $at)->exists()) {
                    return $at;
                }
            }
        }

        return null;
    }

    /** The interface language of this client's own pages and mails: its first profile language when the app speaks it, else the default. */
    public function uiLocale(): string
    {
        $first = $this->profile['languages'][0] ?? null;

        return isset(config('app.locales')[$first]) ? $first : config('app.locale');
    }

    /** Fill {{phone}}-style placeholders from the profile's fields; an unknown one stays as written. */
    public function substitute(?string $text): string
    {
        return preg_replace_callback('/\{\{\s*([\p{L}\p{N}_]+)\s*\}\}/u', fn ($m) => (string) ($this->profile['fields'][mb_strtolower($m[1])] ?? $m[0]), (string) $text);
    }

    /** With `utm: true` in the profile, every http(s) link in the text gets utm_source=platform, utm_medium=social and utm_campaign=post name; links that already carry utm_ stay as written. */
    public function tagLinks(?string $text, string $platform, string $campaign): string
    {
        if (! ($this->profile['utm'] ?? false)) {
            return (string) $text;
        }
        $query = http_build_query(['utm_source' => $platform, 'utm_medium' => 'social', 'utm_campaign' => $campaign]);

        // a link ends before whitespace or a closing bracket; trailing sentence punctuation stays outside it
        return preg_replace_callback('~https?://[^\s<>"\')\]]+~i', function ($m) use ($query) {
            $url = rtrim($m[0], '.,;:!?');
            $tail = substr($m[0], strlen($url));
            if (str_contains($url, 'utm_')) {
                return $m[0];
            }

            return $url.(str_contains($url, '?') ? '&' : '?').$query.$tail;
        }, (string) $text);
    }

    public function toProfileArray(): array
    {
        return [
            'slug' => $this->slug,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'location' => $this->location,
        ] + $this->profile;
    }
}
