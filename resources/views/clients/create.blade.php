<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('New client') }}</h2>
    </x-slot>
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <style>[x-cloak] { display: none !important }</style>
            <div x-data="clientForm()" x-on:hashchange.window="fromHash()" class="md:flex md:gap-8">
                @include('clients._nav')
                <form method="POST" action="{{ route('clients.store') }}" class="min-w-0 flex-1">
                    @include('clients._form')
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
