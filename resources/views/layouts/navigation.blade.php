{{-- The side navigation: the pages, then every client of this login, then the login itself. A top bar with a menu button on phones. --}}
@php($clients = auth()->user()->clients()->orderBy('name')->get(['id', 'name', 'slug']))
<nav x-data="{ open: false }" class="bg-ink text-neutral-200 md:sticky md:top-0 md:flex md:h-screen md:w-60 md:shrink-0 md:flex-col">
    <div class="flex h-14 items-center justify-between px-4 md:h-16 md:px-5">
        <a href="{{ route('dashboard') }}"><x-application-logo class="block h-9 w-auto" /></a>
        <button type="button" @click="open = ! open" class="rounded-md p-2 text-neutral-300 hover:text-white md:hidden" aria-label="{{ __('Menu') }}">
            <x-icon name="bars-3" class="h-6 w-6" x-show="! open" />
            <x-icon name="x-mark" class="h-6 w-6" x-show="open" x-cloak />
        </button>
    </div>

    <div :class="open ? 'block' : 'hidden'" class="hidden flex-1 overflow-y-auto px-3 pb-4 md:block">
        <div class="space-y-0.5">
            <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="home">{{ __('Dashboard') }}</x-nav-link>
            <x-nav-link :href="route('calendar')" :active="request()->routeIs('calendar') && ! request('client')" icon="calendar-days">{{ __('Calendar') }}</x-nav-link>
            @php($toPost = \App\Http\Controllers\TodayController::toPost(auth()->user(), today())->count())
            <x-nav-link :href="route('today')" :active="request()->routeIs('today')" icon="device-phone-mobile">{{ __('Post today') }}@if ($toPost)<span class="ml-auto rounded-full bg-brand-500 px-2 py-0.5 text-xs font-semibold text-white" title="{{ __('Posts still to post today') }}">{{ $toPost }}</span>@endif</x-nav-link>
            <x-nav-link :href="route('clients.index')" :active="request()->routeIs('clients.index', 'clients.create')" icon="building-storefront">{{ __('Clients') }}</x-nav-link>
            <x-nav-link :href="route('jobs.index')" :active="request()->routeIs('jobs.*')" icon="queue-list">{{ __('Jobs') }}</x-nav-link>
        </div>

        @can('admin')
            {{-- admins only: the logins and API tokens, the .env settings, the migrations after a pull --}}
            <p class="mb-1 mt-6 px-4 text-xs font-semibold uppercase tracking-wide text-neutral-400">{{ __('Admin') }}</p>
            <div class="space-y-0.5">
                <x-nav-link :href="route('staff.index')" :active="request()->routeIs('staff.*')" icon="users">{{ __('Staff') }}</x-nav-link>
                <x-nav-link :href="route('settings.edit')" :active="request()->routeIs('settings.*')" icon="cog-6-tooth">{{ __('Settings') }}</x-nav-link>
                <x-nav-link :href="url('/refresh')" :active="request()->is('refresh')" icon="arrow-path">{{ __('Refresh after update') }}</x-nav-link>
            </div>
        @endcan

        @if ($clients->isNotEmpty())
            <p class="mb-1 mt-6 px-4 text-xs font-semibold uppercase tracking-wide text-neutral-400">{{ __('Clients') }}</p>
            <div class="space-y-0.5">
                @foreach ($clients as $one)
                    <x-nav-link :href="route('calendar', ['client' => $one->slug])" :active="isset($client) && $client?->is($one)" class="min-w-0"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-white/15 text-[11px] font-semibold uppercase text-white" aria-hidden="true">{{ mb_substr($one->name, 0, 1) }}</span><span class="truncate">{{ $one->name }}</span></x-nav-link>
                @endforeach
            </div>
        @endif
    </div>

    <div :class="open ? 'block' : 'hidden'" class="hidden border-t border-white/10 px-5 py-3 text-sm md:block">
        <p class="flex items-center gap-2 truncate font-medium text-white"><x-icon name="user" class="h-4 w-4 text-neutral-400" />{{ Auth::user()->name }}</p>
        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-neutral-300">
            <a href="{{ route('profile.edit') }}" class="inline-flex items-center gap-1 hover:text-white"><x-icon name="user-circle" class="h-4 w-4" />{{ __('Profile') }}</a>
            <x-locale-switch class="hover:text-white" />
            <button type="button" onclick="document.documentElement.classList.toggle('dark'); localStorage.theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light'" class="inline-flex items-center hover:text-white" title="{{ __('Light or dark') }}" aria-label="{{ __('Light or dark') }}"><x-icon name="moon" class="h-4 w-4" /></button>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1 hover:text-white"><x-icon name="arrow-right-start-on-rectangle" class="h-4 w-4" />{{ __('Log Out') }}</button>
            </form>
        </div>
    </div>
</nav>
