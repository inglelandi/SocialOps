<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('connected accounts') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="flex items-center gap-1.5 text-sm text-green-600"><x-icon name="check-circle" class="h-5 w-5" />{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif
            @error('connect') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="bg-white rounded-xl border border-gray-300 divide-y divide-gray-200 text-sm">
                @foreach ($providers as $provider => $platforms)
                    @foreach ($platforms as $platform)
                        @php($account = $accounts->get($platform))
                        <div class="px-6 py-4 flex flex-wrap items-center gap-4">
                            <span class="w-40 font-medium">{{ str_replace('_', ' ', ucfirst($platform)) }}</span>
                            @if ($account)
                                <span>{{ $account->name }}</span>
                                <span class="text-xs {{ $account->token_expires_at?->isPast() ? 'text-red-600' : ($account->token_expires_at?->lt(now()->addDays(\App\Console\Commands\CheckTokens::ALERT_DAYS)) ? 'text-orange-600' : 'text-gray-600') }}">
                                    {{ $account->token_expires_at ? __('token until :date', ['date' => $account->token_expires_at->format('d-m-Y')]) : __('token does not expire') }}
                                </span>
                                @if (in_array($platform, ['instagram', 'facebook']))
                                    <form method="POST" action="{{ route('accounts.import', $account) }}" class="ml-auto">
                                        @csrf
                                        <button type="submit" class="text-xs hover:underline" title="{{ __('Posts the client published in the app, last 30 days, as Published posts on the calendar') }}">{{ __('Import last 30 days') }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="{{ in_array($platform, ['instagram', 'facebook']) ? '' : 'ml-auto' }}" onsubmit="return confirm('{{ __('Disconnect this account?') }}')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="inline-flex items-center gap-1 text-red-600 hover:underline"><x-icon name="link-slash" class="h-4 w-4" />{{ __('Disconnect') }}</button>
                                </form>
                            @else
                                <span class="text-gray-500">{{ __('not connected') }}</span>
                            @endif
                            @if ($loop->first)
                                <a href="{{ route('accounts.connect', [$client, $provider]) }}" class="{{ $account ? '' : 'ml-auto' }} inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"><x-icon name="link" class="h-4 w-4" />{{ $account ? __('Reconnect') : __('Connect') }} {{ ['meta' => 'Facebook / Instagram', 'tiktok' => 'TikTok'][$provider] }}</a>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>
            <p class="text-xs text-gray-600">{{ __('Connecting opens the platform\'s own login; we never see or store a password. The profile under "accounts" in the brand profile is only the handle for reference.') }}</p>

            <div class="bg-white p-6 rounded-xl border border-gray-300">
                <x-input-label for="connect-url" :value="__('Link for the client to connect their own accounts (no login, valid :days days; they log in at the platform themselves)', ['days' => \App\Http\Controllers\AccountController::LINK_DAYS])" />
                <x-text-input id="connect-url" type="text" class="mt-1 block w-full font-mono text-xs" :value="$connectUrl" readonly onclick="this.select()" />
            </div>
        </div>
    </div>
</x-app-layout>
