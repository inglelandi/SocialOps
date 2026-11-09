<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Staff') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="flex items-center gap-1.5 text-sm text-green-600"><x-icon name="check-circle" class="h-5 w-5" />{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif
            <div class="bg-white overflow-hidden rounded-xl border border-gray-300">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="px-6 py-3 font-medium">{{ __('Name') }}</th>
                            <th class="px-6 py-3 font-medium">{{ __('Email') }}</th>
                            <th class="px-6 py-3 font-medium">{{ __('Clients') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($users as $user)
                            <tr>
                                <td class="px-6 py-3 font-medium text-gray-900">{{ $user->name }}@if ($user->is_admin) <span class="ml-1 text-xs font-normal text-gray-600">{{ __('admin') }}</span>@endif</td>
                                <td class="px-6 py-3">{{ $user->email }}</td>
                                <td class="px-6 py-3">
                                    {{ $user->client_ids === null ? __('all') : $clients->whereIn('id', $user->client_ids)->pluck('name')->join(', ') }}
                                    @unless ($user->is(auth()->user()))
                                        <details class="mt-1 text-xs">
                                            <summary class="cursor-pointer text-brand-600">{{ __('Limit') }}</summary>
                                            <form method="POST" action="{{ route('staff.update', $user) }}" class="mt-2 space-y-2">
                                                @csrf
                                                @method('PATCH')
                                                @foreach ($clients as $client)
                                                    <label class="flex items-center gap-1"><input type="checkbox" name="client_ids[]" value="{{ $client->id }}" class="rounded border-gray-300" @checked(in_array($client->id, $user->client_ids ?? [])) /> {{ $client->name }}</label>
                                                @endforeach
                                                @can('admin')
                                                    <label class="flex items-center gap-1 pt-1 border-t"><input type="checkbox" name="is_admin" value="1" class="rounded border-gray-300" @checked($user->is_admin) /> {{ __('Admin: sees the Settings page and can make other admins') }}</label>
                                                @endcan
                                                <button type="submit" class="rounded-lg border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50">{{ __('Save (none ticked: every client)') }}</button>
                                            </form>
                                        </details>
                                    @endunless
                                </td>
                                <td class="px-6 py-3 text-right">
                                    @if ($user->is(auth()->user()))
                                        <span class="text-gray-600">{{ __('you') }}</span>
                                    @else
                                        <form method="POST" action="{{ route('staff.destroy', $user) }}" class="inline" onsubmit="return confirm('{{ __('Remove this login?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:underline">{{ __('Remove') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (session('token'))
                <p class="text-sm text-yellow-800 bg-yellow-50 rounded-md p-3">{{ __('New API token, copy it now, it is not shown again:') }} <code class="font-mono select-all">{{ session('token') }}</code></p>
            @endif
            <x-section icon="key" :title="__('API tokens')" :description="__('for scripts and other tools, see docs/api.md')">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-600"><tr><th class="py-2 pr-6 font-medium">{{ __('Name') }}</th><th class="py-2 pr-6 font-medium">{{ __('Client') }}</th><th class="py-2 pr-6 font-medium">{{ __('Last used') }}</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($tokens as $token)
                            <tr>
                                <td class="py-2 pr-6 font-medium text-gray-900">{{ $token->name }}</td>
                                <td class="py-2 pr-6">{{ $token->client?->name ?? __('all') }}</td>
                                <td class="py-2 pr-6 text-gray-600">{{ $token->last_used_at?->diffForHumans() ?? __('never') }}</td>
                                <td class="py-2 text-right"><form method="POST" action="{{ route('staff.tokens.destroy', $token) }}" onsubmit="return confirm('{{ __('Revoke this token? Scripts using it stop working at once.') }}')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">{{ __('Revoke') }}</button></form></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-gray-600">{{ __('None yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <form method="POST" action="{{ route('staff.tokens.store') }}" class="flex flex-wrap items-end gap-3 py-4 text-sm">
                    @csrf
                    <div><x-input-label for="token-name" :value="__('Token name')" /><x-text-input id="token-name" name="name" type="text" class="mt-1 block" required maxlength="60" placeholder="{{ __('e.g. Zapier, reporting script') }}" /></div>
                    <div><x-input-label for="token-client" :value="__('Limited to')" />
                        <select id="token-client" name="client_id" class="mt-1 block border-gray-300 rounded-md shadow-sm text-sm"><option value="">{{ __('All clients') }}</option>@foreach ($clients as $client)<option value="{{ $client->id }}">{{ $client->name }}</option>@endforeach</select></div>
                    <x-primary-button><x-icon name="key" class="h-4 w-4" />{{ __('Create token') }}</x-primary-button>
                </form>
            </x-section>
            <x-section icon="user-plus" :title="__('Add a login')" class="max-w-xl">
                <form method="POST" action="{{ route('staff.store') }}" class="space-y-4 py-5">
                    @csrf
                    @include('staff._fields')
                    <x-primary-button><x-icon name="plus" class="h-4 w-4" />{{ __('Add') }}</x-primary-button>
                </form>
            </x-section>
        </div>
    </div>
</x-app-layout>
