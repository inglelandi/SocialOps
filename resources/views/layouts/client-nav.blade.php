@php
    // Same tab row on every page of a client; the switcher (phones: the side navigation lists the clients on wider screens) keeps the page when the route is client-scoped.
    $name = request()->route()->getName();
    $switch = fn ($c) => in_array($name, ['clients.assets', 'clients.files', 'clients.grid', 'clients.reviews', 'clients.deadlines', 'clients.comments', 'clients.accounts', 'clients.reports', 'clients.edit', 'clients.bin', 'posts.create']) ? route($name, $c) : route('calendar', ['client' => $c->slug]);
    $tabs = [
        [__('Calendar'), route('calendar', ['client' => $client->slug]), request()->routeIs('calendar', 'posts.*'), null, 'calendar-days'],
        [__('Assets'), route('clients.assets', $client), request()->routeIs('clients.assets'), null, 'photo'],
        [__('Files'), route('clients.files', $client), request()->routeIs('clients.files'), $client->documents()->where('chars', '>', 0)->whereNot('kind', 'design')->count(), 'folder-open'], // the documents read from the client's Drive folder (its designs are counted on the tab)
        [__('Grid'), route('clients.grid', $client), request()->routeIs('clients.grid'), null, 'squares-2x2'],
        [__('Approvals'), route('clients.reviews', $client), request()->routeIs('clients.reviews'), $client->posts()->where('state', \App\Posts\PostState::InReview)->count(), 'check-badge'],
        [__('Deadlines'), route('clients.deadlines', $client), request()->routeIs('clients.deadlines'), $client->deadlines()->dueSoon()->count(), 'flag'],
        ...(config('features.publishing') ? [
            [__('Comments'), route('clients.comments', $client), request()->routeIs('clients.comments'), $client->comments()->open()->count(), 'chat-bubble-left-right'],
            [__('Accounts'), route('clients.accounts', $client), request()->routeIs('clients.accounts'), null, 'link'],
        ] : []),
        [__('Reports'), route('clients.reports', $client), request()->routeIs('clients.reports', 'reports.show'), null, 'chart-bar'],
        [__('Profile'), route('clients.edit', $client), request()->routeIs('clients.edit'), null, 'identification'],
        [__('Recycle bin'), route('clients.bin', $client), request()->routeIs('clients.bin'), null, 'trash'],
    ];
@endphp
<nav class="mt-4 flex items-center gap-1 overflow-x-auto text-sm" aria-label="{{ $client->name }}">
    <select onchange="location.href = this.value" class="mr-2 rounded-md border-gray-300 py-1 text-sm font-medium md:hidden" aria-label="{{ __('Client') }}">
        @foreach (auth()->user()->clients()->get() as $other)
            <option value="{{ $switch($other) }}" @selected($other->is($client))>{{ $other->name }}</option>
        @endforeach
    </select>
    @foreach ($tabs as [$label, $url, $active, $count, $icon])
        <a href="{{ $url }}" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 {{ $active ? 'bg-white font-medium text-gray-900 shadow-sm ring-1 ring-gray-200' : 'text-gray-600 hover:bg-white/60 hover:text-gray-900' }}"><x-icon :name="$icon" class="h-4 w-4 {{ $active ? 'text-brand-600' : '' }}" />{{ $label }}@if ($count)<span class="ml-1 rounded-full bg-brand-100 px-1.5 text-xs text-brand-700">{{ $count }}</span>@endif</a>
    @endforeach
    <a href="{{ route('posts.create', $client) }}" class="ml-auto inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-600"><x-icon name="plus" class="h-4 w-4" />{{ __('New post') }}</a>
</nav>
