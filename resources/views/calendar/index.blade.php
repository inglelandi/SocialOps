<x-app-layout :client="$client">
    @php($step = $view === 'week' ? '1 week' : '1 month')
    @php($len = $range ? (int) $range[0]->diffInDays($range[1]) + 1 : 0)
    {{-- the dates every link keeps: the from–to range, or the date shown; the arrows move by the range's own length --}}
    @php($when = $range ? ['from' => $range[0]->toDateString(), 'to' => $range[1]->toDateString()] : ['date' => $date->toDateString()])
    @php($span = [...$filters, 'client' => $clientSlug, ...$when])
    @php($move = fn (int $dir) => $range ? ['from' => $range[0]->copy()->addDays($dir * $len)->toDateString(), 'to' => $range[1]->copy()->addDays($dir * $len)->toDateString()] : ['date' => ($dir > 0 ? $date->copy()->add($step) : $date->copy()->sub($step))->toDateString()])
    @php($btn = 'inline-flex items-center rounded-lg border border-gray-300 bg-white px-2.5 py-1 text-gray-700 hover:bg-gray-50')
    @php($input = 'rounded-md border-gray-300 py-1.5 text-sm')
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Calendar') }}</h2>
            @if ($clientSlug)
                <div class="flex flex-wrap items-center gap-2">
                    @if ($clientUrl)
                        {{-- the view-only calendar for the client: one click copies it (or shows it to copy by hand where the browser refuses) --}}
                        <button type="button" x-data="{ copied: false }" data-url="{{ $clientUrl }}" x-on:click="navigator.clipboard.writeText($el.dataset.url).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => prompt('', $el.dataset.url))" title="{{ __('View-only link for the client (no login, valid :days days, this month and the next)', ['days' => \App\Http\Controllers\CalendarController::LINK_DAYS]) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-400 bg-white px-3.5 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50"><x-icon name="link" class="h-4 w-4" /><span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy link for the client') }}'">{{ __('Copy link for the client') }}</span></button>
                    @endif
                    <a class="inline-flex items-center gap-1.5 rounded-lg border border-gray-400 bg-white px-3.5 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50" href="{{ route('posts.csv', [$clients->firstWhere('slug', $clientSlug), 'view' => $view, ...$when]) }}" title="{{ __('The posts shown, one row per platform, for Canva Bulk Create or a spreadsheet') }}"><x-icon name="table-cells" class="h-4 w-4" />{{ __('Export CSV') }}</a>
                </div>
            @else
                <button type="button" data-new-post="" class="inline-flex items-center rounded-lg bg-brand-500 px-3.5 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ __('New post') }}</button>
            @endif
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="flex items-center gap-1.5 text-sm text-green-600"><x-icon name="check-circle" class="h-5 w-5" />{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif
            @error('state') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm">
                <a class="{{ $btn }}" href="{{ route('calendar', [...$span, 'view' => $view, ...$move(-1)]) }}" aria-label="{{ __('Previous') }}">&larr;</a>
                <span class="px-1 font-medium text-gray-900">{{ $range ? $range[0]->format('d-m-Y').' – '.$range[1]->format('d-m-Y') : ($view === 'week' ? $date->copy()->startOfWeek()->format('d-m-Y').' – '.$date->copy()->endOfWeek()->format('d-m-Y') : $date->translatedFormat('F Y')) }}</span>
                <a class="{{ $btn }}" href="{{ route('calendar', [...$span, 'view' => $view, ...$move(1)]) }}" aria-label="{{ __('Next') }}">&rarr;</a>
                <span class="ml-1 inline-flex rounded-lg bg-gray-100 p-0.5">
                    @foreach (['month' => __('Month'), 'week' => __('Week'), 'list' => __('List')] as $one => $label)
                        <a class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 {{ $view === $one ? 'bg-white font-medium text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}" href="{{ route('calendar', [...$span, 'view' => $one]) }}"><x-icon :name="['month' => 'calendar-days', 'week' => 'calendar', 'list' => 'list-bullet'][$one]" class="h-4 w-4" />{{ $label }}</a>
                    @endforeach
                </span>
                {{-- the everyday filters stay in view; the date range, platform and pillar wait behind "More filters" and open by themselves when one is in use --}}
                <form method="GET" action="{{ route('calendar') }}" class="flex flex-wrap items-center gap-2 sm:ml-auto" x-data="{ more: {{ $range || filled($filters['platform'] ?? null) || filled($filters['pillar'] ?? null) ? 'true' : 'false' }} }">
                    <input type="hidden" name="view" value="{{ $view }}">
                    <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.from.value = this.form.to.value = ''; this.form.submit()" title="{{ __('Go to a date') }}" class="{{ $input }}">
                    <select name="client" onchange="this.form.submit()" class="{{ $input }}">
                        <option value="">{{ __('All clients') }}</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->slug }}" @selected($clientSlug === $client->slug)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                    <select name="state" onchange="this.form.submit()" class="{{ $input }}">
                        <option value="">{{ __('Any state') }}</option>
                        @foreach (\App\Posts\PostState::cases() as $state)
                            <option value="{{ $state->value }}" @selected(($filters['state'] ?? '') === $state->value)>{{ __($state->label()) }}</option>
                        @endforeach
                    </select>
                    <button type="button" x-on:click="more = ! more" x-show="! more" class="inline-flex items-center gap-1 rounded-md px-2 py-1.5 font-medium text-gray-900 underline decoration-gray-400 underline-offset-4 hover:text-brand-600"><x-icon name="funnel" class="h-4 w-4" />{{ __('More filters') }}</button>
                    <span x-show="more" x-cloak class="contents">
                    <label class="text-gray-600">{{ __('From') }} <input type="date" name="from" value="{{ $range ? $range[0]->toDateString() : '' }}" onchange="this.form.to.value && this.form.submit()" class="{{ $input }}"></label>
                    <label class="text-gray-600">{{ __('To') }} <input type="date" name="to" value="{{ $range ? $range[1]->toDateString() : '' }}" onchange="this.form.from.value && this.form.submit()" class="{{ $input }}"></label>
                    <select name="platform" onchange="this.form.submit()" class="{{ $input }}">
                        <option value="">{{ __('Any platform') }}</option>
                        @foreach (\App\Models\Post::PLATFORMS as $platform)
                            <option value="{{ $platform }}" @selected(($filters['platform'] ?? '') === $platform)>{{ str_replace('_', ' ', ucfirst($platform)) }}</option>
                        @endforeach
                    </select>
                    <select name="pillar" onchange="this.form.submit()" class="{{ $input }}">
                        <option value="">{{ __('Any pillar') }}</option>
                        @foreach ($pillars as $pillar)
                            <option value="{{ $pillar }}" @selected(($filters['pillar'] ?? '') === $pillar)>{{ str_replace('_', ' ', $pillar) }}</option>
                        @endforeach
                    </select>
                    </span>
                </form>
            </div>

            @if ($view !== 'list')
            @foreach ($grids as $grid)
            @if ($grid['title'])<h3 class="hidden sm:block font-semibold text-gray-800 {{ $loop->first ? '' : 'pt-4' }}">{{ $grid['title'] }}</h3>@endif
            <div class="hidden sm:grid grid-cols-7 gap-px overflow-hidden rounded-xl border border-gray-300 bg-gray-200 text-xs">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow)
                    <div class="bg-gray-50 px-2 py-1.5 font-medium text-gray-600">{{ __($dow) }}</div>
                @endforeach
                @foreach ($grid['days'] as $day)
                    @php($key = $day->toDateString())
                    <div class="day bg-white min-h-24 p-1.5 cursor-pointer {{ ($grid['month'] ? $day->month !== $grid['month'] || $day->lt($range[0]) || $day->gt($range[1]) : $day->month !== $date->month && $view === 'month') ? 'text-gray-500' : '' }}" data-date="{{ $key }}">
                        <div class="flex justify-between">
                            <span class="{{ $day->isToday() ? 'rounded-full bg-brand-500 px-1.5 font-semibold text-white' : '' }}">{{ $day->day }}</span>
                            @if ($clientSlug)
                                <a href="{{ route('posts.create', [$clients->firstWhere('slug', $clientSlug), 'date' => $key]) }}" class="text-gray-500 hover:text-gray-700" title="{{ __('New post') }}">+</a>
                            @else
                                <button type="button" data-new-post="{{ $key }}" class="text-gray-500 hover:text-gray-700" title="{{ __('New post') }}">+</button>
                            @endif
                        </div>
                        @foreach ($marks[$key] ?? [] as $mark)
                            <div class="truncate text-amber-700 opacity-40" title="{{ $mark }}">{{ $mark }}</div>
                        @endforeach
                        @foreach ($posts->get($key, []) as $post)
                            @include('calendar._chip', ['post' => $post])
                        @endforeach
                    </div>
                @endforeach
            </div>
            @endforeach

            <div class="day hidden sm:block rounded-xl border border-dashed border-gray-300 bg-white p-3 text-xs" data-date="">
                <p class="font-medium text-gray-600 mb-1">{{ __('No date yet (drag onto a day to schedule)') }}</p>
                @forelse ($unscheduled as $post)
                    @include('calendar._chip', ['post' => $post])
                @empty
                    <span class="text-gray-500">{{ __('None') }}</span>
                @endforelse
            </div>
            @endif
            @include('calendar._list', ['class' => $view === 'list' ? '' : 'sm:hidden', 'days' => $range ? collect($range[0]->toPeriod($range[1], '1 day')) : $days])

            <p class="flex flex-wrap items-center gap-1.5 text-xs text-gray-600" title="{{ __('Click a state to show only those posts; click it again to show all.') }}">
                {{ __('States') }}:
                @foreach (\App\Posts\PostState::cases() as $state)
                    @php($on = ($filters['state'] ?? '') === $state->value)
                    <a href="{{ route('calendar', [...$filters, 'state' => $on ? null : $state->value, 'view' => $view, 'client' => $clientSlug, ...$when]) }}"><x-state-badge :state="$state" :class="$on ? 'ring-2 ring-gray-800' : ''" /></a>
                @endforeach
            </p>
        </div>
    </div>

    @unless ($clientSlug)
        <dialog id="pick-client" class="rounded-xl p-4 text-sm shadow-xl backdrop:bg-black/30">
            <p class="mb-2 font-medium">{{ __('New post for which client?') }}</p>
            <div class="flex flex-col gap-1">
                @foreach ($clients as $other)
                    <a href="{{ route('posts.create', $other) }}" data-href="{{ route('posts.create', $other) }}" class="rounded-md px-3 py-1.5 hover:bg-gray-100">{{ $other->name }}</a>
                @endforeach
            </div>
            <form method="dialog" class="mt-3 text-right"><button class="text-gray-600">{{ __('Cancel') }}</button></form>
        </dialog>
    @endunless

    <script>
        // Click an empty spot on a day (or "+", or "New post") to start a post on that date; with no client selected, pick one first.
        const create = @json($clientSlug ? route('posts.create', $clients->firstWhere('slug', $clientSlug)) : null), pick = document.getElementById('pick-client');
        const newPost = date => {
            if (create) { location.href = create + (date ? '?date=' + date : ''); return; }
            pick.querySelectorAll('a').forEach(a => a.href = a.dataset.href + (date ? '?date=' + date : ''));
            pick.showModal();
        };
        document.querySelectorAll('[data-new-post]').forEach(b => b.addEventListener('click', () => newPost(b.dataset.newPost)));
        document.querySelectorAll('.day[data-date]').forEach(day => day.addEventListener('click', e => {
            if (day.dataset.date && !e.target.closest('a, button')) newPost(day.dataset.date);
        }));

        // Drag a post chip onto a day: PATCH /posts/{id}/reschedule, then reload.
        document.querySelectorAll('.chip').forEach(chip => {
            chip.addEventListener('dragstart', e => e.dataTransfer.setData('text/plain', chip.dataset.id));
        });
        document.querySelectorAll('.day[data-date]').forEach(day => {
            if (!day.dataset.date) return;
            day.addEventListener('dragover', e => { e.preventDefault(); day.classList.add('bg-brand-50'); });
            day.addEventListener('dragleave', () => day.classList.remove('bg-brand-50'));
            day.addEventListener('drop', async e => {
                e.preventDefault();
                const id = e.dataTransfer.getData('text/plain');
                const body = new URLSearchParams({ _method: 'PATCH', _token: '{{ csrf_token() }}', date: day.dataset.date });
                const res = await fetch(`/posts/${id}/reschedule`, { method: 'POST', body, headers: { 'Accept': 'text/html' } });
                if (res.ok) location.reload(); else alert('{{ __('Could not move this post.') }}');
            });
        });
    </script>
</x-app-layout>
