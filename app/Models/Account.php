<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's connected platform account. Tokens are encrypted at rest and only ever come from OAuth. The intake and reference
 * folders live here too, with an empty token: platform drive and dropbox (the inbox), drive_docs and drive_slides (the Files
 * tab: the reference folder and the posts slideshow).
 */
#[Fillable(['platform', 'external_id', 'name', 'access_token', 'refresh_token', 'token_expires_at', 'token_alerted_at', 'meta', 'connected_by'])]
class Account extends Model
{
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'token_expires_at' => 'datetime', 'token_alerted_at' => 'datetime', 'meta' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(FollowerSnapshot::class);
    }

    /** The documents read from a drive_docs row (the client's reference folder) or a drive_slides row (its one slideshow); they go with the row. */
    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class);
    }

    /** The staff member who connected it: the one to warn when the token runs out. */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
