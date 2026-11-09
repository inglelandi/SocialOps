<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('files') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="text-sm font-medium text-green-700">{{ session('status') }}.</p>
            @endif

            <x-section :title="__('Google Drive folder')" :description="__('What Claude reads when it plans this client: the previous posts, so it does not repeat them, and the reference files, as facts.')">
                <div class="py-5 text-sm">
                    @error('folder') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    @if ($folder)
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="font-medium text-gray-900">{{ $folder->name }}</span>
                            <span class="text-gray-600">
                                @if ($folder->meta['synced_at'] ?? null)
                                    {{ __('Last read :when, :n files', ['when' => \Illuminate\Support\Carbon::parse($folder->meta['synced_at'])->format('d-m-Y H:i'), 'n' => $folder->meta['file_count'] ?? 0]) }}
                                @else
                                    {{ __('Not read yet') }}
                                @endif
                            </span>
                            <form method="POST" action="{{ route('clients.files.sync', $client) }}">@csrf<x-secondary-button type="submit">{{ __('Read the folder now') }}</x-secondary-button></form>
                            <form method="POST" action="{{ route('accounts.destroy', $folder) }}" onsubmit="return confirm('{{ __('Unlink this folder? Its files are forgotten here; nothing changes on Drive.') }}')">@csrf @method('DELETE')<button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Unlink') }}</button></form>
                        </div>
                        @if ($folder->meta['error'] ?? null)
                            <p class="mt-2 text-sm text-red-600">{{ $folder->meta['error'] }}</p>
                        @endif
                    @elseif ($driveEmail)
                        @if ($inbox)
                            {{-- the photo inbox folder is already shared and known: one click links it here too --}}
                            <form method="POST" action="{{ route('clients.files.link', $client) }}" class="mb-4">@csrf
                                <input type="hidden" name="folder" value="{{ $inbox->external_id }}">
                                <x-secondary-button type="submit">{{ __('Use the photo inbox folder “:name”', ['name' => $inbox->name]) }}</x-secondary-button>
                            </form>
                        @endif
                        <p class="text-gray-600">{{ __('Share the folder with :email (Viewer is enough) before linking it.', ['email' => $driveEmail]) }}</p>
                        <form method="POST" action="{{ route('clients.files.link', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">@csrf
                            <x-text-input name="folder" type="text" class="block w-full min-w-0 text-sm" :value="old('folder')" placeholder="https://drive.google.com/drive/folders/…" required />
                            <x-primary-button class="flex-none">{{ __('Link') }}</x-primary-button>
                        </form>
                    @else
                        <p class="text-gray-600">{{ __('Add GOOGLE_SERVICE_ACCOUNT_JSON to .env to pull files from a shared Drive folder (docs/pilot-setup.md).') }}</p>
                    @endif
                </div>
            </x-section>

            @if ($folder)
                <x-section :title="__('Files')" :description="__('Previous posts: what the client published, so Claude does not repeat it. Reference: facts Claude plans from. Not used: left out.')" flush>
                    @if ($documents->isEmpty())
                        <div class="p-6">
                            <x-empty-state>{{ __('No readable files in this folder yet. Word, Excel, PowerPoint, Google Docs, Sheets, Slides and text files are read; photos, videos and PDFs are not.') }}</x-empty-state>
                        </div>
                    @else
                        <ul class="divide-y divide-gray-200 text-sm">
                            @foreach ($documents as $document)
                                <li class="grid gap-3 px-6 py-3 sm:grid-cols-12 sm:items-center">
                                    <div class="min-w-0 sm:col-span-5">
                                        <p class="truncate font-medium text-gray-900">{{ $document->name }}</p>
                                        @if ($document->path !== '')<p class="truncate text-xs text-gray-600">{{ $document->path }}</p>@endif
                                        @if ($document->mime === 'application/vnd.google-apps.spreadsheet')<p class="text-xs text-gray-600">{{ __('Google Sheets: only the first sheet is read.') }}</p>@endif
                                    </div>
                                    <form method="POST" action="{{ route('clients.files.kind', [$client, $document]) }}" class="sm:col-span-3">
                                        @csrf @method('PATCH')
                                        <select name="kind" onchange="this.form.requestSubmit()" aria-label="{{ __('Type') }}" class="block w-full rounded-md border-gray-300 py-1 text-sm focus:border-brand-500 focus:ring-brand-500">
                                            @foreach (\App\Models\ClientDocument::KINDS as $value => $label)<option value="{{ $value }}" @selected($document->kind === $value)>{{ __($label) }}</option>@endforeach
                                        </select>
                                    </form>
                                    <div class="text-xs sm:col-span-2">
                                        @if ($document->error)
                                            <span class="text-red-600">{{ $document->errorText() }}</span>
                                        @else
                                            <span class="text-gray-600">{{ __(':n characters read', ['n' => number_format($document->chars)]) }}</span>
                                        @endif
                                    </div>
                                    <div class="text-xs tabular-nums text-gray-600 sm:col-span-2">{{ $document->modified_at?->format('d-m-Y') }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-section>
            @endif
        </div>
    </div>
</x-app-layout>
