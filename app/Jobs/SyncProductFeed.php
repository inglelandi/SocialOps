<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\ClientProduct;
use App\Products\ProductFeed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Reads a client's product feed (the product_feed account, `meta.url`) into client_products: every product the feed lists,
 * at most ProductFeed::MAX_PRODUCTS, updated in place, and the ones gone from the feed dropped. A feed that cannot be
 * fetched or read keeps the products read before, and the Files tab says why. Daily from products:pull, and on Link and
 * "Read the feed now".
 */
class SyncProductFeed implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** Rows written per query. */
    private const CHUNK = 500;

    public function __construct(public Account $account) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('products:'.$this->account->id))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(): void
    {
        $started = Carbon::now()->startOfSecond();
        try {
            $path = ProductFeed::download((string) ($this->account->meta['url'] ?? ''));
        } catch (RuntimeException $e) {
            $this->meta(['synced_at' => $started->toDateTimeString(), 'error' => $e->getMessage()]);

            return;
        }

        try {
            $seen = [];
            $rows = [];
            $count = $inStock = 0;
            $capped = false;
            $format = null;
            foreach (ProductFeed::parse($path) as $product) {
                if (isset($seen[$product['external_id']])) {
                    continue; // a product listed twice (one row per size or colour) keeps its first row
                }
                if ($count >= ProductFeed::MAX_PRODUCTS) {
                    $capped = true;
                    break;
                }
                $seen[$product['external_id']] = true;
                $count++;
                $inStock += $product['in_stock'] ? 1 : 0;
                $format ??= ProductFeed::RECORDS[$product['record']];
                $rows[] = $this->row($product, $started);
                if (count($rows) === self::CHUNK) {
                    $this->write($rows);
                    $rows = [];
                }
            }
            $this->write($rows);
        } catch (Throwable $e) {
            $this->meta(['synced_at' => $started->toDateTimeString(), 'error' => $e->getMessage()]);

            return;
        } finally {
            @unlink($path);
        }

        if ($count === 0) { // a page instead of a feed, or an empty one: what was read before stays
            $this->meta(['synced_at' => $started->toDateTimeString(), 'error' => 'No products in this file. Is it the shop\'s product feed (the XML for Skroutz, BestPrice, Google Shopping or Facebook)?']);

            return;
        }
        $this->account->products()->where('updated_at', '<', $started)->delete(); // gone from the feed since the last read
        $this->meta(['synced_at' => $started->toDateTimeString(), 'count' => $count, 'in_stock' => $inStock, 'format' => $format, 'capped' => $capped, 'error' => null]);
    }

    private function row(array $product, Carbon $now): array
    {
        $fields = array_intersect_key($product, array_flip(['external_id', 'name', 'url', 'price', 'sale_price', 'category', 'brand', 'image', 'in_stock', 'description']));

        return $fields + [
            'client_id' => $this->account->client_id,
            'account_id' => $this->account->id,
            'search' => ClientProduct::fold(implode(' ', array_filter([$product['name'], $product['category'], $product['brand'], $product['description']]))),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function write(array $rows): void
    {
        if ($rows !== []) {
            ClientProduct::upsert($rows, ['client_id', 'external_id'], ['account_id', 'name', 'url', 'price', 'sale_price', 'category', 'brand', 'image', 'in_stock', 'description', 'search', 'updated_at']);
        }
    }

    private function meta(array $values): void
    {
        $this->account->forceFill(['meta' => array_merge($this->account->meta ?? [], $values)])->save();
    }

    public function failed(?Throwable $e): void
    {
        $this->meta(['synced_at' => now()->toDateTimeString(), 'error' => $e?->getMessage() ?? 'unknown error']);
    }
}
