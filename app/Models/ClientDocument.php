<?php

namespace App\Models;

use App\Media\DocumentText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Normalizer;

/**
 * One file of a client's reference folder on Google Drive, kept as text so Claude can plan from it: the posts the client
 * published before (not to repeat them) or a reference file (menus, prices, services: facts). Never a photo or a video.
 */
#[Fillable(['source_id', 'name', 'path', 'mime', 'kind', 'kind_set_by_user', 'modified_at', 'size', 'text', 'chars', 'fetched_at', 'error'])]
class ClientDocument extends Model
{
    /** What a file is for the planner, with its label on the Files tab. */
    public const KINDS = ['previous_posts' => 'Previous posts', 'reference' => 'Reference', 'ignored' => 'Not used'];

    /** The most text kept per file; a longer file is cut with a note. */
    public const MAX_CHARS = 80000;

    /** Words (lowercase, accents folded) in a file name that mean "the posts so far" or "the content calendar", Greek and English. */
    public const PREVIOUS_POSTS_WORDS = ['θεματολογ', 'αναρτ', 'δημοσιευ', 'ημερολογ', 'πλανο', 'λεζαντ', 'post', 'published', 'calendar', 'plan', 'caption', 'content'];

    protected function casts(): array
    {
        return ['kind_set_by_user' => 'boolean', 'modified_at' => 'datetime', 'fetched_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** previous_posts when the name speaks of past posts or a content calendar, else reference; the team corrects it on the Files tab. */
    public static function guessKind(string $name): string
    {
        $lower = mb_strtolower($name);
        $folded = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($lower, Normalizer::FORM_D) ?: $lower);
        foreach (self::PREVIOUS_POSTS_WORDS as $word) {
            if (str_contains($folded, $word)) {
                return 'previous_posts';
            }
        }

        return 'reference';
    }

    /** The error in the viewer's language when it is one of the reasons DocumentText gives, else as stored. */
    public function errorText(): ?string
    {
        if ($this->error === null) {
            return null;
        }

        return in_array($this->error, DocumentText::REASONS, true) ? __($this->error) : $this->error;
    }
}
