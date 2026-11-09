<x-guest-layout>
    <p class="text-sm text-gray-700">{{ __(':client wants to work in Social Ops as :user: read the clients and posts this login sees, file Drafts and plans. Nothing is published.', ['client' => $client->name, 'user' => $user->name]) }}</p>
    <div class="mt-4 flex items-center gap-3">
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <x-primary-button><x-icon name="shield-check" class="h-4 w-4" />{{ __('Allow') }}</x-primary-button>
        </form>
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <x-secondary-button type="submit"><x-icon name="x-mark" class="h-4 w-4" />{{ __('Cancel') }}</x-secondary-button>
        </form>
    </div>
</x-guest-layout>
