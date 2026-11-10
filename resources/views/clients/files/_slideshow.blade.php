{{-- the team's working file with the posts prepared to go up: one file, read as text like the folder's documents --}}
<x-section icon="presentation-chart-bar" x-show="tab === 'slideshow'" x-cloak :title="__('Posts slideshow')" :description="__('The Google Slides file where the team keeps the posts that will go up. Claude reads it when it plans or makes posts for this client: it does not plan them again, plans around them and writes the same way.')">
    <div class="py-5 text-sm">
        @error('slideshow') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
        @if ($slideshow)
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ \App\Planning\ClientReference::url($slideshow->external_id, (string) ($slideshowDocument?->mime ?? $slideshow->meta['mime'] ?? '')) }}" target="_blank" rel="noopener" class="font-medium text-gray-900 hover:text-brand-600">{{ $slideshow->name }}</a>
                <span class="text-gray-600">
                    @if ($slideshowDocument && $slideshowDocument->error === null && ($slideshow->meta['synced_at'] ?? null))
                        {{ __('Last read :when, :n characters', ['when' => \Illuminate\Support\Carbon::parse($slideshow->meta['synced_at'])->format('d-m-Y H:i'), 'n' => number_format($slideshowDocument->chars)]) }}
                    @elseif (! ($slideshow->meta['synced_at'] ?? null))
                        {{ __('Not read yet') }}
                    @endif
                </span>
                <form method="POST" action="{{ route('clients.files.slideshow.sync', $client) }}">@csrf<x-secondary-button type="submit"><x-icon name="arrow-path" class="h-4 w-4" />{{ __('Read it now') }}</x-secondary-button></form>
                <form method="POST" action="{{ route('accounts.destroy', $slideshow) }}" onsubmit="return confirm('{{ __('Unlink this slideshow? Its text is forgotten here; nothing changes on Drive.') }}')">@csrf @method('DELETE')<button type="submit" class="inline-flex items-center gap-1 text-sm text-red-600 hover:underline"><x-icon name="link-slash" class="h-4 w-4" />{{ __('Unlink') }}</button></form>
            </div>
            @if ($slideshow->meta['error'] ?? null)
                <p class="mt-2 text-sm text-red-600">{{ $slideshow->meta['error'] }}</p>
            @elseif ($slideshowDocument?->error)
                <p class="mt-2 text-sm text-red-600">{{ $slideshowDocument->errorText() }}</p>
            @endif
            <p class="mt-2 text-gray-600">{{ __('Read again every hour, and when you press Read it now.') }}</p>
            @if ($slideshowDocument?->text)
                <details class="mt-3">
                    <summary class="cursor-pointer font-medium text-gray-900">{{ __('What Claude reads') }}</summary>
                    <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded-md border border-gray-300 bg-gray-50 p-3 font-sans text-xs text-gray-900">{{ \Illuminate\Support\Str::limit($slideshowDocument->text, \App\Planning\ClientReference::UPCOMING_TOTAL) }}</pre>
                </details>
            @endif
        @elseif ($driveEmail)
            <p class="text-gray-600">{{ __('Share the file with :email (Viewer is enough), or open it to anyone with the link, before linking it.', ['email' => $driveEmail]) }}</p>
            <form method="POST" action="{{ route('clients.files.slideshow', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">@csrf
                <x-text-input name="slideshow" type="text" class="block w-full min-w-0 text-sm" :value="old('slideshow')" placeholder="https://docs.google.com/presentation/d/…" required />
                <x-primary-button class="flex-none"><x-icon name="link" class="h-4 w-4" />{{ __('Link') }}</x-primary-button>
            </form>
        @else
            <p class="text-gray-600">{{ __('Add GOOGLE_SERVICE_ACCOUNT_JSON to .env to pull files from a shared Drive folder (docs/pilot-setup.md).') }}</p>
        @endif
    </div>
</x-section>
