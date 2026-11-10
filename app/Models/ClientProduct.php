<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Normalizer;

/**
 * One product of a client's shop, as its product feed lists it (App\Products\ProductFeed, read daily by SyncProductFeed):
 * the text Claude needs to link the right product in a post. The picture stays on the shop: only its link is kept.
 */
#[Fillable(['external_id', 'name', 'url', 'price', 'sale_price', 'category', 'brand', 'image', 'in_stock', 'description', 'search'])]
class ClientProduct extends Model
{
    /** The most products find-products answers with. */
    public const SEARCH_MAX = 20;

    /** The most products one post links. */
    public const PER_POST = 10;

    /** Categories named in the planning brief's shop summary, the biggest first. */
    public const SUMMARY_CATEGORIES = 25;

    protected function casts(): array
    {
        return ['in_stock' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Lowercase without accents or a final sigma, so "Σεντόνια", "σεντονια" and "ΣΕΝΤΟΝΙΑ" meet in a search. */
    public static function fold(string $text): string
    {
        $lower = mb_strtolower($text);
        $folded = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($lower, Normalizer::FORM_D) ?: $lower);

        return trim(preg_replace('/\s+/u', ' ', str_replace('ς', 'σ', $folded)));
    }

    /**
     * The client's products that best match the words, most matches first (a word in the name counts most, then the
     * category and the brand, then the description), in stock only unless asked otherwise.
     *
     * @return Collection<int, self>
     */
    public static function search(Client $client, string $query, int $limit = 8, bool $inStockOnly = true): Collection
    {
        $words = array_values(array_unique(array_filter(explode(' ', self::fold(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query))), fn ($w) => mb_strlen($w) >= 2)));
        if ($words === []) {
            return collect();
        }
        $candidates = $client->products()
            ->when($inStockOnly, fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('in_stock', true)->orWhereNull('in_stock')))
            ->where(function (Builder $q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('search', 'like', '%'.addcslashes($word, '%_\\').'%');
                }
            })
            ->limit(1000)->get();

        return $candidates->map(function (self $product) use ($words) {
            $score = 0;
            foreach ($words as $word) {
                $score += (str_contains(self::fold($product->name), $word) ? 6 : 0)
                    + (str_contains(self::fold((string) $product->category), $word) ? 3 : 0)
                    + (str_contains(self::fold((string) $product->brand), $word) ? 3 : 0)
                    + (str_contains(self::fold((string) $product->description), $word) ? 1 : 0);
            }

            return [$score, $product];
        })->filter(fn ($pair) => $pair[0] > 0)
            ->sortBy([fn ($a, $b) => $b[0] <=> $a[0], fn ($a, $b) => ($b[1]->in_stock ? 1 : 0) <=> ($a[1]->in_stock ? 1 : 0), fn ($a, $b) => strnatcasecmp($a[1]->name, $b[1]->name)])
            ->take(max(1, min($limit, self::SEARCH_MAX)))->map(fn ($pair) => $pair[1])->values();
    }

    /** What a post keeps of a product it links: enough to show and paste it even after the feed has changed. */
    public function snapshot(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'url' => $this->url, 'price' => $this->sale_price ?: $this->price];
    }

    /** The product as the tools answer it. */
    public function toToolArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'url' => $this->url, 'price' => $this->price, 'sale_price' => $this->sale_price,
            'category' => $this->category, 'brand' => $this->brand, 'in_stock' => $this->in_stock];
    }

    /**
     * The shop in a few lines for the planning brief: how many products, how many in stock, when the feed was read, and the
     * biggest categories with their counts. '' while the client has no feed or the feed gave no product.
     */
    public static function summary(Client $client): string
    {
        $feed = $client->accounts()->where('platform', 'product_feed')->first();
        $total = $feed ? $client->products()->count() : 0;
        if ($total === 0) {
            return '';
        }
        $inStock = $client->products()->where('in_stock', true)->count();
        $categories = $client->products()->whereNotNull('category')->where('category', '!=', '')
            ->selectRaw('category, count(*) as n')->groupBy('category')->orderByDesc('n')->orderBy('category')->limit(self::SUMMARY_CATEGORIES)->get()
            ->map(fn ($row) => "{$row->category} ({$row->n})")->implode('; ');
        $read = isset($feed->meta['synced_at']) ? ', feed read '.date('d-m-Y', strtotime($feed->meta['synced_at'])) : '';

        return "The client's shop has {$total} products ({$inStock} marked in stock{$read}).".($categories !== '' ? " Biggest categories: {$categories}." : '');
    }
}
