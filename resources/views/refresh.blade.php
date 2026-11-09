<x-guest-layout>
    <form method="POST" action="{{ route('refresh') }}" class="text-sm">
        @csrf
        <h2 class="font-semibold text-lg text-gray-800 mb-2">{{ __('Refresh after update') }}</h2>
        <p class="text-gray-600 mb-4">{{ __('Press this once after "Pull now" in Plesk Git: it runs new database migrations and rebuilds the caches.') }} {{ __('This page works even when the dashboard fails after an update.') }}</p>
        <x-primary-button><x-icon name="arrow-path" class="h-4 w-4" />{{ __('Refresh after update') }}</x-primary-button>
    </form>
</x-guest-layout>
