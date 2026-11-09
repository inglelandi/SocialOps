{{-- The posts of the period in date order, one row each with a thumbnail: what phones get instead of the grid, and the "List" button on a desktop. --}}
@php($groups = $days->filter(fn ($d) => $posts->has($d->toDateString()) || isset($marks[$d->toDateString()]))->mapWithKeys(fn ($d) => [$d->toDateString() => $posts->get($d->toDateString(), collect())]))
@php($unscheduled->isEmpty() || $groups->put('', $unscheduled))
<form method="POST" action="{{ route('posts.bulk') }}" class="bg-white rounded-xl border border-gray-300 divide-y divide-gray-200 text-sm {{ $class }}" data-view="list" data-bulk>
    @csrf
    @foreach ($groups as $key => $dayPosts)
        @php($day = $key ? \Illuminate\Support\Carbon::parse($key) : null)
        <div class="p-3">
            <div class="flex items-center gap-2 font-medium {{ $day?->isToday() ? 'text-brand-600' : 'text-gray-800' }}">
                {{ $day ? $day->translatedFormat('l d-m-Y') : __('No date yet') }}
                @foreach ($marks[$key] ?? [] as $mark)
                    <span class="font-normal text-amber-700 opacity-40">· {{ $mark }}</span>
                @endforeach
                @if ($day && $clientSlug)
                    <a href="{{ route('posts.create', [$clients->firstWhere('slug', $clientSlug), 'date' => $key]) }}" class="ml-auto text-gray-500 hover:text-gray-700" title="{{ __('New post') }}">+</a>
                @endif
            </div>
            @foreach ($dayPosts as $post)
                <div class="mt-2 flex items-center gap-3">
                    @if ($post->state === \App\Posts\PostState::Published)
                        <span class="w-4 flex-none" title="{{ __('Published posts stay as they are') }}"></span>
                    @else
                        <input type="checkbox" name="posts[]" value="{{ $post->id }}" class="flex-none rounded border-gray-300" aria-label="{{ __('Tick :name', ['name' => $post->name]) }}">
                    @endif
                    @if ($thumb = $post->assets->firstWhere('kind', 'image'))
                        <img src="{{ route('assets.thumb', $thumb) }}" alt="" loading="lazy" class="h-12 w-12 flex-none rounded object-cover">
                    @else
                        <span class="flex h-12 w-12 flex-none items-center justify-center rounded bg-gray-100 text-center text-[10px] uppercase leading-tight text-gray-500">{{ $post->assets->isEmpty() ? __('no media') : __('video') }}</span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('posts.edit', $post) }}" class="block truncate font-mono text-xs text-gray-700 hover:underline">{{ $post->name }}</a>
                        @include('calendar._chip', ['post' => $post])
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
    @if ($posts->isEmpty() && $unscheduled->isEmpty())
        <x-empty-state icon="calendar-days" class="m-3">
            {{ __('Nothing scheduled here yet.') }}
            <x-slot:action>
                @if ($clientSlug)
                    <a href="{{ route('posts.create', $clients->firstWhere('slug', $clientSlug)) }}" class="inline-flex items-center rounded-full bg-brand-500 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-600">{{ __('New post') }}</a>
                @else
                    <button type="button" data-new-post="" class="inline-flex items-center rounded-full bg-brand-500 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-600">{{ __('New post') }}</button>
                @endif
            </x-slot:action>
        </x-empty-state>
    @else
        <div class="sticky bottom-0 flex flex-wrap items-center gap-2 rounded-b-xl border-t border-gray-200 bg-white/95 p-3 backdrop-blur">
            <label class="flex items-center gap-2 text-gray-600"><input type="checkbox" data-bulk-all class="rounded border-gray-300"> <span data-bulk-count>0</span> {{ __('ticked') }}</label>
            <button name="action" value="review" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">{{ __('Send for approval') }}</button>
            <span class="flex items-center gap-1">
                <input type="date" name="date" value="{{ old('date') }}" class="rounded-md border-gray-300 py-1.5 text-sm" aria-label="{{ __('Move to') }}">
                <button name="action" value="move" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40">{{ __('Move') }}</button>
            </span>
            <button name="action" value="delete" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-40" onclick="return confirm('{{ __('Move the ticked posts to the recycle bin? Published posts stay.') }}')"><x-icon name="trash" class="h-4 w-4" />{{ __('Delete') }}</button>
            @if ($errors->hasAny(['posts', 'date']))
                <span class="text-red-600">{{ $errors->first('posts') ?: $errors->first('date') }}</span>
            @endif
        </div>
    @endif
</form>
<script>
    // Tick posts in the list: the bar counts them and its buttons wake up; the first box ticks all.
    document.querySelectorAll('[data-bulk]').forEach(form => {
        const boxes = () => [...form.querySelectorAll('input[name="posts[]"]')];
        const sync = () => {
            const n = boxes().filter(b => b.checked).length;
            form.querySelector('[data-bulk-count]')?.replaceChildren(String(n));
            form.querySelectorAll('button[name=action]').forEach(b => b.disabled = !n);
        };
        form.addEventListener('change', e => { if (e.target.matches('[data-bulk-all]')) boxes().forEach(b => b.checked = e.target.checked); sync(); });
        sync();
    });
</script>
