<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('deadlines') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="flex items-center gap-1.5 text-sm text-green-600"><x-icon name="check-circle" class="h-5 w-5" />{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif

            <x-section icon="plus-circle" :title="__('Add a deadline')" :description="__('What the client owes us (products, footage, answers) and what we owe the client (the topic plan, the edited videos), by when. Tick it when it is done.')">
                <form method="POST" action="{{ route('deadlines.store', $client) }}" class="grid gap-3 py-5 md:grid-cols-6">
                    @csrf
                    <div class="md:col-span-3">
                        <x-input-label for="title" :value="__('What')" />
                        <x-text-input id="title" name="title" type="text" class="mt-1 block w-full" :value="old('title')" placeholder="{{ __('December topic plan') }}" required />
                        <x-input-error class="mt-1" :messages="$errors->get('title')" />
                    </div>
                    <div>
                        <x-input-label for="side" :value="__('Who')" />
                        <select id="side" name="side" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                            @foreach (\App\Models\Deadline::SIDES as $value => $label)<option value="{{ $value }}" @selected(old('side', 'agency') === $value)>{{ __($label) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="due_on" :value="__('Due')" />
                        <x-text-input id="due_on" name="due_on" type="date" class="mt-1 block w-full" :value="old('due_on')" />
                    </div>
                    <div class="flex items-end"><x-primary-button><x-icon name="plus" class="h-4 w-4" />{{ __('Add') }}</x-primary-button></div>
                </form>
            </x-section>

            @forelse ($months as $month => $deadlines)
                <x-section icon="calendar" :title="$month ? \Carbon\Carbon::parse($month.'-01')->translatedFormat('F Y') : __('No date')" flush>
                    <ul class="divide-y divide-gray-200 text-sm">
                        @foreach ($deadlines as $deadline)
                            <li class="flex flex-wrap items-center gap-3 px-6 py-2.5">
                                <form method="POST" action="{{ route('deadlines.toggle', $deadline) }}">@csrf @method('PATCH')<button type="submit" class="flex h-5 w-5 items-center justify-center rounded-full border text-xs {{ $deadline->done_at ? 'border-green-600 bg-green-600 text-white' : 'border-gray-300 text-transparent hover:border-gray-500' }}" title="{{ $deadline->done_at ? __('Reopen') : __('Mark done') }}">✓</button></form>
                                <span class="{{ $deadline->done_at ? 'text-gray-500 line-through' : 'text-gray-900' }}">{{ $deadline->title }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $deadline->side === 'client' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800' }}">{{ __(\App\Models\Deadline::SIDES[$deadline->side]) }}</span>
                                @if ($deadline->due_on)<span class="text-xs {{ ! $deadline->done_at && $deadline->due_on->lt(today()) ? 'text-red-600 font-medium' : 'text-gray-600' }}">{{ $deadline->due_on->translatedFormat('D d-m-Y') }}</span>@endif
                                @if ($deadline->done_at)<span class="text-xs text-gray-500">{{ __('done') }} {{ $deadline->done_at->format('d-m-Y') }}</span>@endif
                                <form method="POST" action="{{ route('deadlines.destroy', $deadline) }}" class="ml-auto" onsubmit="return confirm('{{ __('Delete this deadline?') }}')">@csrf @method('DELETE')<button type="submit" class="inline-flex items-center gap-1 text-xs text-red-600 hover:underline"><x-icon name="trash" class="h-4 w-4" />{{ __('Delete') }}</button></form>
                            </li>
                        @endforeach
                    </ul>
                </x-section>
            @empty
                <x-empty-state icon="flag" class="bg-white">{{ __('No deadlines yet. Add what the client owes and what we owe for the month.') }}</x-empty-state>
            @endforelse
        </div>
    </div>
</x-app-layout>
