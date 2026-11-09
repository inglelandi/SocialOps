<?php

namespace App\Models;

use App\Media\DocumentText;
use App\Media\GoogleDrive;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Normalizer;

/**
 * One file of a client's reference folder on Google Drive, kept as text so Claude can plan from it: the posts the client
 * published before (not to repeat them), a reference file (menus, prices, services: facts), or a design (a graphic, a
 * photo, a PDF, a video: kind design) whose text is the description Claude wrote from Drive's preview through the
 * connector, once (`described_at`). The file itself, or a picture of it, is never kept. The team's posts slideshow of the
 * client (a drive_slides account) is one more row, kind upcoming_posts: the posts prepared to go up.
 */
#[Fillable(['source_id', 'name', 'path', 'mime', 'kind', 'kind_set_by_user', 'modified_at', 'size', 'text', 'chars', 'fetched_at', 'described_at', 'error'])]
class ClientDocument extends Model
{
    /** What a file is for the planner, with its label on the Files tab. */
    public const KINDS = ['upcoming_posts' => 'Posts slideshow', 'previous_posts' => 'Previous posts', 'reference' => 'Reference', 'design' => 'Design', 'ignored' => 'Not used'];

    /** The kinds the team can give a document (read as text) and a design (described from its picture). */
    public const DOCUMENT_KINDS = ['previous_posts', 'reference', 'ignored'];

    public const DESIGN_KINDS = ['design', 'ignored'];

    /** The most text kept per file; a longer file is cut with a note. */
    public const MAX_CHARS = 80000;

    /** The longest description of a design, its fields included. */
    public const DESCRIPTION_MAX = 1500;

    /** What a design showed, as save-design-description takes it, with the word the description uses. */
    public const POST_TYPES = ['offer' => 'offer', 'event' => 'event', 'menu' => 'menu', 'product' => 'product', 'season' => 'season', 'behind_the_scenes' => 'behind the scenes', 'other' => 'other'];

    /** A design Drive lists without a thumbnail: never offered to Claude; the next sync looks again. */
    public const NO_PREVIEW = 'Drive has no preview of this file';

    /** get-design could not fetch the preview: out of the queue for a day (SyncClientDocuments::RETRY_HOURS), or until the file changes. */
    public const PREVIEW_FAILED = 'Drive did not hand over the preview; tried again tomorrow';

    /** Words (lowercase, accents folded) in a file name that mean "the posts so far" or "the content calendar", Greek and English. */
    public const PREVIOUS_POSTS_WORDS = ['θεματολογ', 'αναρτ', 'δημοσιευ', 'ημερολογ', 'πλανο', 'λεζαντ', 'post', 'published', 'calendar', 'plan', 'caption', 'content'];

    protected function casts(): array
    {
        return ['kind_set_by_user' => 'boolean', 'modified_at' => 'datetime', 'fetched_at' => 'datetime', 'described_at' => 'datetime'];
    }

    /** A design file (by its type, whatever kind the team gave it): described from its picture, never read as text. */
    public function isDesign(): bool
    {
        return GoogleDrive::isDesign($this->mime, $this->name);
    }

    /** A design Claude still has to describe: kind design, a preview, no description yet. */
    public function isWaiting(): bool
    {
        return $this->kind === 'design' && $this->text === null && $this->error === null;
    }

    /** The kinds the team can choose for this file on the Files tab. */
    public function kindChoices(): array
    {
        return array_intersect_key(self::KINDS, array_flip($this->isDesign() ? self::DESIGN_KINDS : self::DOCUMENT_KINDS));
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

    /** The error in the viewer's language when it is one of the reasons DocumentText or a design gives, else as stored. */
    public function errorText(): ?string
    {
        if ($this->error === null) {
            return null;
        }

        return in_array($this->error, [...DocumentText::REASONS, self::NO_PREVIEW, self::PREVIEW_FAILED], true) ? __($this->error) : $this->error;
    }
}
