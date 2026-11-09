<x-dynamic-component :component="auth()->check() ? 'app-layout' : 'guest-layout'">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('which accounts belong to this client?') }}</h2>
    </x-slot>

    <div class="{{ auth()->check() ? 'py-6' : '' }}">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @guest <p class="mb-3 text-sm text-gray-600">{{ __('Tick the page, profile or location that belongs to :client.', ['client' => $client->name]) }}</p> @endguest
            <form method="POST" action="{{ route('accounts.attach', $client) }}" class="bg-white p-6 rounded-xl border border-gray-300 text-sm space-y-3">
                @csrf
                @forelse ($candidates as $i => $candidate)
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="picked[]" value="{{ $i }}" class="rounded border-gray-300" />
                        <span class="w-32 text-gray-600">{{ str_replace('_', ' ', $candidate['platform']) }}</span>
                        <span class="font-medium">{{ $candidate['name'] }}</span>
                    </label>
                @empty
                    <p class="text-gray-600">{{ __('The login worked but this user manages no page, creator profile or business location we can publish to.') }}</p>
                @endforelse
                <x-input-error class="mt-2" :messages="$errors->get('picked')" />
                @if ($candidates)
                    <x-primary-button><x-icon name="link" class="h-4 w-4" />{{ __('Connect the ticked accounts') }}</x-primary-button>
                @endif
                <a href="{{ $return }}" class="ml-4 text-gray-600 hover:underline">{{ __('Cancel') }}</a>
            </form>
        </div>
    </div>
</x-dynamic-component>
