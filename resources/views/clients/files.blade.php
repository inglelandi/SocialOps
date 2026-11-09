<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('files') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="text-sm font-medium text-green-700">{{ session('status') }}.</p>
            @endif

            {{-- the team's working file with the posts prepared to go up: one file, read as text like the folder's documents --}}
            <x-section :title="__('Posts slideshow')" :description="__('The Google Slides file where the team keeps the posts that will go up. Claude reads it when it plans or makes posts for this client: it does not plan them again, plans around them and writes the same way.')">
                <div class="py-5 text-sm">
                    @error('slideshow') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    @if ($slideshow)
                        <div class="flex flex-wrap items-center gap-3">
                            <a href="{{ \App\Planning\ClientReference::url($slideshow->external_id, (string) ($slideshowDocument?->mime ?? $slideshow->meta['mime'] ?? '')) }}" target="_blank" rel="noopener" class="font-medium text-gray-900 hover:text-brand-600">{{ $slideshow->name }}</a>
                            <span class="text-gray-600">
                                @if ($slideshowDocument && $slideshowDocument->error === null && ($slideshow->meta['synced_at'] ?? null))
                                    {{ __('Last read :when, :n characters', ['when' => \Illuminate\Support\Carbon::parse($slideshow->meta['synced_at'])->format('d-m-Y H:i'), 'n' => number_format($slideshowDocument->chars)]) }}
                                @elseif (! ($slideshow->meta['synced_at'] ?? null))
                                    {{ __('Not read yet') }}
                                @endif
                            </span>
                            <form method="POST" action="{{ route('clients.files.slideshow.sync', $client) }}">@csrf<x-secondary-button type="submit">{{ __('Read it now') }}</x-secondary-button></form>
                            <form method="POST" action="{{ route('accounts.destroy', $slideshow) }}" onsubmit="return confirm('{{ __('Unlink this slideshow? Its text is forgotten here; nothing changes on Drive.') }}')">@csrf @method('DELETE')<button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Unlink') }}</button></form>
                        </div>
                        @if ($slideshow->meta['error'] ?? null)
                            <p class="mt-2 text-sm text-red-600">{{ $slideshow->meta['error'] }}</p>
                        @elseif ($slideshowDocument?->error)
                            <p class="mt-2 text-sm text-red-600">{{ $slideshowDocument->errorText() }}</p>
                        @endif
                        <p class="mt-2 text-gray-600">{{ __('Read again every hour, and when you press Read it now.') }}</p>
                        @if ($slideshowDocument?->text)
                            <details class="mt-3">
                                <summary class="cursor-pointer font-medium text-gray-900">{{ __('What Claude reads') }}</summary>
                                <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded-md border border-gray-300 bg-gray-50 p-3 font-sans text-xs text-gray-900">{{ \Illuminate\Support\Str::limit($slideshowDocument->text, \App\Planning\ClientReference::UPCOMING_TOTAL) }}</pre>
                            </details>
                        @endif
                    @elseif ($driveEmail)
                        <p class="text-gray-600">{{ __('Share the file with :email (Viewer is enough), or open it to anyone with the link, before linking it.', ['email' => $driveEmail]) }}</p>
                        <form method="POST" action="{{ route('clients.files.slideshow', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">@csrf
                            <x-text-input name="slideshow" type="text" class="block w-full min-w-0 text-sm" :value="old('slideshow')" placeholder="https://docs.google.com/presentation/d/…" required />
                            <x-primary-button class="flex-none">{{ __('Link') }}</x-primary-button>
                        </form>
                    @else
                        <p class="text-gray-600">{{ __('Add GOOGLE_SERVICE_ACCOUNT_JSON to .env to pull files from a shared Drive folder (docs/pilot-setup.md).') }}</p>
                    @endif
                </div>
            </x-section>

            <x-section :title="__('Google Drive folder')" :description="__('What Claude reads when it plans this client: the previous posts, so it does not repeat them, and the reference files, as facts.')">
                <div class="py-5 text-sm">
                    @error('folder') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    @if ($folder)
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="font-medium text-gray-900">{{ $folder->name }}</span>
                            <span class="text-gray-600">
                                @if (isset($folder->meta['synced_at'], $folder->meta['design_count']))
                                    {{ __('Last read :when, :n documents and :d designs', ['when' => \Illuminate\Support\Carbon::parse($folder->meta['synced_at'])->format('d-m-Y H:i'), 'n' => $folder->meta['file_count'] ?? 0, 'd' => $folder->meta['design_count']]) }}
                                @elseif ($folder->meta['synced_at'] ?? null)
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
                        @if ($folder->meta['capped'] ?? false)
                            <p class="mt-2 text-sm text-amber-700">{{ __('The folder holds more files than the :max read here: its own files first, then its subfolders, :depth levels deep at most.', ['max' => number_format(\App\Media\GoogleDrive::DOCUMENTS_MAX), 'depth' => \App\Media\GoogleDrive::DOCUMENTS_DEPTH]) }}</p>
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
                            <x-empty-state>{{ __('No documents in this folder yet. Word, Excel, PowerPoint, Google Docs, Sheets, Slides and text files are read as text; photos, graphics, PDFs and videos are listed under Designs.') }}</x-empty-state>
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
                                            @foreach ($document->kindChoices() as $value => $label)<option value="{{ $value }}" @selected($document->kind === $value)>{{ __($label) }}</option>@endforeach
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

                {{-- the pictures come from Drive on each view (clients.files.preview) and are never kept; only Claude's description is --}}
                <x-section :title="__('Designs')" :description="__('Photos, graphics, PDFs and videos of the posts the client published: Claude looks at Drive\'s preview of each one and describes the post it shows, once. Only that text is kept here, never the picture.')" flush>
                    @if ($designTotal === 0)
                        <div class="p-6">
                            <x-empty-state>{{ __('No designs in this folder yet. Photos, Illustrator, Photoshop, InDesign and EPS files, PDFs and videos are listed here.') }}</x-empty-state>
                        </div>
                    @else
                        <p class="px-6 py-3 text-sm text-gray-600">{{ __(':done of :total described; Claude describes up to 25 an hour.', $described) }}</p>
                        <ul class="divide-y divide-gray-200 text-sm">
                            @foreach ($designs as $design)
                                <li class="flex gap-4 px-6 py-3">
                                    @if ($design->error === \App\Models\ClientDocument::NO_PREVIEW)
                                        <div class="h-24 w-24 flex-none rounded-md border border-gray-300 bg-gray-100" aria-hidden="true"></div>
                                    @else
                                        <img src="{{ route('clients.files.preview', [$client, $design]) }}" loading="lazy" alt="" width="96" height="96" class="h-24 w-24 flex-none rounded-md border border-gray-300 bg-gray-100 object-cover">
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="truncate font-medium text-gray-900">{{ $design->name }}</p>
                                                <p class="truncate text-xs text-gray-600">@if ($design->path !== ''){{ $design->path }} · @endif<span class="tabular-nums">{{ $design->modified_at?->format('d-m-Y') }}</span></p>
                                            </div>
                                            <form method="POST" action="{{ route('clients.files.kind', [$client, $design]) }}" class="w-36 flex-none">
                                                @csrf @method('PATCH')
                                                <select name="kind" onchange="this.form.requestSubmit()" aria-label="{{ __('Type') }}" class="block w-full rounded-md border-gray-300 py-1 text-sm focus:border-brand-500 focus:ring-brand-500">
                                                    @foreach ($design->kindChoices() as $value => $label)<option value="{{ $value }}" @selected($design->kind === $value)>{{ __($label) }}</option>@endforeach
                                                </select>
                                            </form>
                                        </div>
                                        <div class="mt-1.5 text-xs">
                                            @if ($design->text !== null)
                                                <details>
                                                    <summary class="cursor-pointer text-gray-900">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::before($design->text, "\n"), 160) }}</summary>
                                                    <p class="mt-1 whitespace-pre-line text-gray-600">{{ $design->text }}</p>
                                                </details>
                                            @elseif ($design->error)
                                                <span class="text-red-600">{{ $design->errorText() }}</span>
                                            @elseif ($design->kind === 'design')
                                                <span class="text-gray-600">{{ __('Waiting for Claude') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        @if ($designs->count() < $designTotal)
                            <p class="px-6 py-3 text-sm"><a href="{{ route('clients.files', [$client, 'all' => 1]) }}" class="font-medium text-gray-900 hover:text-brand-600">{{ __('Show all :n designs', ['n' => $designTotal]) }}</a></p>
                        @endif
                    @endif
                </x-section>
            @endif
        </div>
    </div>
</x-app-layout>
