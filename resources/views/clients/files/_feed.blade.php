{{-- the shop's product feed: the products Claude links in the posts it plans, read daily as text --}}
<x-section icon="tag" x-show="tab === 'feed'" x-cloak :title="__('Product feed')" :description="__('The XML file the client\'s e-shop makes for Skroutz, BestPrice, Google Shopping or Facebook. Claude links real products from it in the posts it plans and writes.')">
    <div class="py-5 text-sm">
        @error('feed') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
        @if ($feed)
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ $feed->meta['url'] ?? '#' }}" target="_blank" rel="noopener" class="font-medium text-gray-900 hover:text-brand-600">{{ $feed->name }}</a>
                <span class="text-gray-600">
                    @if (isset($feed->meta['count'], $feed->meta['synced_at']))
                        {{ __('Last read :when, :n products (:m in stock)', ['when' => \Illuminate\Support\Carbon::parse($feed->meta['synced_at'])->format('d-m-Y H:i'), 'n' => number_format($feed->meta['count']), 'm' => number_format($feed->meta['in_stock'] ?? 0)]) }}
                    @elseif (! ($feed->meta['synced_at'] ?? null))
                        {{ __('Not read yet') }}
                    @endif
                </span>
                <form method="POST" action="{{ route('clients.files.feed.sync', $client) }}">@csrf<x-secondary-button type="submit"><x-icon name="arrow-path" class="h-4 w-4" />{{ __('Read the feed now') }}</x-secondary-button></form>
                <form method="POST" action="{{ route('accounts.destroy', $feed) }}" onsubmit="return confirm('{{ __('Unlink this product feed? Its products are forgotten here; nothing changes on the shop.') }}')">@csrf @method('DELETE')<button type="submit" class="inline-flex items-center gap-1 text-sm text-red-600 hover:underline"><x-icon name="link-slash" class="h-4 w-4" />{{ __('Unlink') }}</button></form>
            </div>
            @if ($feed->meta['error'] ?? null)
                <p class="mt-2 text-sm text-red-600">{{ $feed->meta['error'] }}</p>
            @endif
            @if ($feed->meta['capped'] ?? false)
                <p class="mt-2 text-sm text-amber-700">{{ __('The feed holds more than :max products; the first :max are kept.', ['max' => number_format(\App\Products\ProductFeed::MAX_PRODUCTS)]) }}</p>
            @endif
            <p class="mt-2 text-gray-600">{{ __('Read again every day, and when you press Read the feed now.') }}</p>
            @if ($productTotal > 0)
                <form method="GET" action="{{ route('clients.files', $client) }}" class="mt-4 flex gap-2">
                    <x-text-input name="product" type="search" class="block w-full min-w-0 text-sm" :value="$productQuery" placeholder="{{ __('Search the products, as Claude does') }}" />
                    <x-secondary-button type="submit" class="flex-none"><x-icon name="magnifying-glass" class="h-4 w-4" />{{ __('Search') }}</x-secondary-button>
                </form>
                <ul class="mt-3 divide-y divide-gray-200 rounded-lg border border-gray-300">
                    @forelse ($products as $product)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                            <a href="{{ $product->url }}" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate font-medium text-gray-900 hover:text-brand-600">{{ $product->name }}</a>
                            @if ($product->category)<span class="max-w-xs truncate text-xs text-gray-600">{{ $product->category }}</span>@endif
                            @if ($product->sale_price ?: $product->price)<span class="tabular-nums text-gray-900">{{ $product->sale_price ?: $product->price }}</span>@endif
                            @if ($product->in_stock === false)<span class="rounded bg-gray-100 px-1.5 text-xs text-gray-600">{{ __('Out of stock') }}</span>@endif
                        </li>
                    @empty
                        <li class="px-3 py-3 text-gray-600">{{ __('No product matches these words.') }}</li>
                    @endforelse
                </ul>
                @if ($productQuery === '' && $productTotal > $products->count())
                    <p class="mt-2 text-xs text-gray-600">{{ __('The first :n of :total by name; search to find the others.', ['n' => $products->count(), 'total' => number_format($productTotal)]) }}</p>
                @endif
            @endif
        @else
            <p class="text-gray-600">{{ __('Ask whoever runs the client\'s e-shop for the link of its product feed; most Greek shops have one for Skroutz or BestPrice.') }}</p>
            <form method="POST" action="{{ route('clients.files.feed', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">@csrf
                <x-text-input name="feed" type="text" class="block w-full min-w-0 text-sm" :value="old('feed')" placeholder="https://shop.gr/feeds/skroutz.xml" required />
                <x-primary-button class="flex-none"><x-icon name="link" class="h-4 w-4" />{{ __('Link') }}</x-primary-button>
            </form>
        @endif
    </div>
</x-section>
