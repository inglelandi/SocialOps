<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }} <span class="font-mono text-base text-gray-600">{{ $client->code }}</span></h2>
    </x-slot>
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <style>[x-cloak] { display: none !important }</style>
            <div x-data="{ tab: localStorage.getItem('client-tab') || 'basics' }" x-init="if (! $el.querySelector(`[data-tab='${tab}']`)) tab = 'basics'; $watch('tab', t => localStorage.setItem('client-tab', t))" class="md:flex md:gap-8">
                @include('clients._nav')
                <div class="min-w-0 flex-1 space-y-6">
                    <form method="POST" action="{{ route('clients.update', $client) }}">
                        @method('PATCH')
                        @include('clients._form')
                    </form>
                    <form method="POST" action="{{ route('clients.destroy', $client) }}" x-show="tab === 'basics'" x-cloak class="text-right" onsubmit="return confirm('{{ __('Delete this client and everything it owns?') }}')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center gap-1 text-sm text-red-600 hover:underline"><x-icon name="trash" class="h-4 w-4" />{{ __('Delete this client') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
