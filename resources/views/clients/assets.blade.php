<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('assets') }}</h2>
    </x-slot>

    @php
        $pillars = array_keys($client->profile['pillars']);
        $pillarName = fn (?string $pillar) => $pillar ? ucfirst(str_replace('_', ' ', $pillar)) : __('No pillar');
        $sources = \App\Models\Asset::sources();
        $keep = collect(request()->only(\App\Http\Controllers\AssetController::FILTERS))->filter(fn ($v) => is_string($v) && $v !== ''); // the grid's filters, carried through a delete
        $mb = fn (int $bytes) => $bytes < 1048576 ? max(1, round($bytes / 1024)).' KB' : number_format($bytes / 1048576, 1).' MB';
        // the other ways stay open while one of them is being set up: a Canva search, a folder to name, an error to read
        $openOther = request()->has('canva') || $errors->hasAny(['canva', 'drive', 'dropbox']) || old('folder') !== null || in_array(session('status'), ['canva-connected', 'dropbox-connected'], true);
        $connected = array_keys(array_filter(['Google Drive' => $drive, 'Dropbox' => $dropbox?->meta['folder'] ?? null, 'Canva' => $canva]));
        $btn = 'inline-flex items-center justify-center rounded-lg border border-gray-400 bg-white px-3.5 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50';
        $fold = 'flex cursor-pointer list-none items-center justify-between gap-3 [&::-webkit-details-marker]:hidden';
        $chevron = '<svg class="h-5 w-5 flex-none text-gray-600 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';
    @endphp

    <div class="py-6" x-data="{ preview: null }" x-on:keydown.escape.window="preview = null">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="text-sm font-medium text-green-700">{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif

            {{-- Add files: the team's own upload first, the other ways folded under it --}}
            <x-section :title="__('Add files')" :description="__('Photos and videos get the agency name and land in the files below.')">
                <form id="upload-form" method="POST" action="{{ route('assets.store', $client) }}" enctype="multipart/form-data" class="space-y-4 py-5"
                      x-data="assetUploader(@js([
                          'maxBytes' => \App\Http\Requests\InboxUploadRequest::MAX_KB * 1024,
                          'text' => [
                              'waiting' => __('Waiting'),
                              'uploading' => __('Uploading… :percent%'),
                              'added' => __('Added as :name'),
                              'tooBig' => \App\Http\Requests\InboxUploadRequest::sizeMessage(),
                              'type' => \App\Http\Requests\InboxUploadRequest::typeMessage(),
                              'expired' => __('The page expired. Reload it and try again.'),
                              'error' => __('Could not be saved, try again.'),
                              'offline' => __('No connection. Try again.'),
                              'button' => __('Upload'),
                              'buttonMany' => __('Upload :n files'),
                              'summary' => __(':done added, :failed not added.'),
                          ],
                      ]))"
                      x-on:submit.prevent="start()" x-on:dragover.window="dragover($event)" x-on:drop.window="drop($event)" x-on:dragleave.window="$event.relatedTarget || (over = false)">
                    @csrf
                    <label for="upload-files" class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-gray-400 bg-gray-50 px-4 py-8 text-center transition hover:border-brand-500"
                           x-bind:class="over && 'border-brand-500 bg-brand-50'" data-dropzone>
                        <svg class="h-8 w-8 text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                        <span class="text-base font-medium text-gray-900">{{ __('Drop photos and videos here') }}</span>
                        <span class="text-sm text-gray-600">{{ __('or') }} <span class="font-medium text-brand-600 underline">{{ __('choose them') }}</span></span>
                        <span class="mt-1 text-xs text-gray-600">{{ __('JPG, PNG, WEBP, HEIC, MP4 or MOV, up to :mb MB each.', ['mb' => \App\Http\Requests\InboxUploadRequest::MAX_KB / 1024]) }}</span>
                    </label>
                    <input id="upload-files" name="files[]" type="file" multiple accept="image/*,video/mp4,video/quicktime" class="sr-only" x-on:change="add($event.target.files); $event.target.value = ''">

                    @if ($errors->upload->any())
                        <ul class="list-disc space-y-1 pl-5 text-sm text-red-600">@foreach ($errors->upload->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    @endif

                    {{-- one line per file: its name and size, a progress bar, then the name it got or why it was refused --}}
                    <ul x-show="items.length" x-cloak class="divide-y divide-gray-200 rounded-lg border border-gray-300 text-sm" data-upload-list>
                        <template x-for="item in items" x-bind:key="item.id">
                            <li class="px-3 py-2">
                                <div class="flex items-center gap-3">
                                    <span class="min-w-0 flex-1 truncate font-medium text-gray-900" x-text="item.file.name"></span>
                                    <span class="flex-none text-xs text-gray-600" x-text="mb(item.file.size)"></span>
                                    <button type="button" x-show="item.state === 'waiting' && ! busy" x-on:click="remove(item)" class="flex-none text-xs font-medium text-red-600 hover:underline">{{ __('Remove') }}</button>
                                </div>
                                <div x-show="item.state !== 'failed'" class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-200">
                                    <div class="h-full rounded-full transition-all" x-bind:class="item.state === 'done' ? 'bg-green-600' : 'bg-brand-500'" x-bind:style="`width: ${item.progress}%`"></div>
                                </div>
                                <p class="mt-1 break-words text-xs" x-bind:class="{ 'text-gray-600': item.state === 'waiting' || item.state === 'uploading', 'text-green-700': item.state === 'done', 'text-red-600': item.state === 'failed' }" x-text="status(item)"></p>
                            </li>
                        </template>
                    </ul>

                    <fieldset>
                        <legend class="text-sm font-medium text-gray-900">{{ __('For every file in this upload') }}</legend>
                        <div class="mt-2 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <x-input-label for="upload-pillar" :value="__('Pillar')" />
                                <select id="upload-pillar" name="pillar" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    <option value="">{{ __('No pillar') }}</option>
                                    @foreach ($pillars as $pillar)
                                        <option value="{{ $pillar }}" @selected(old('pillar') === $pillar)>{{ $pillarName($pillar) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="upload-season" :value="__('Season or event (optional)')" />
                                <x-text-input id="upload-season" name="season" type="text" maxlength="30" class="mt-1 block w-full text-sm" :value="old('season')" :placeholder="__('e.g. summer 2027, opening party')" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="upload-note" :value="__('Note (optional)')" />
                                <x-text-input id="upload-note" name="note" type="text" maxlength="500" class="mt-1 block w-full text-sm" :value="old('note')" />
                            </div>
                            <label class="flex items-start gap-2 text-sm text-gray-900 sm:col-span-2">
                                <input type="checkbox" name="people_consent" value="1" class="mt-0.5 rounded border-gray-300 text-brand-600" @checked(old('people_consent'))>
                                <span>{{ __('The people shown have agreed to appear on social media.') }}</span>
                            </label>
                            <label class="flex items-start gap-2 text-sm text-gray-900 sm:col-span-2">
                                <input type="checkbox" name="ugc_permission" value="1" class="mt-0.5 rounded border-gray-300 text-brand-600" @checked(old('ugc_permission'))>
                                <span>{{ __('This was made by a guest and they allow us to repost it.') }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-primary-button x-bind:disabled="busy"><span x-text="buttonLabel()">{{ __('Upload') }}</span></x-primary-button>
                        <button type="button" x-show="items.length && ! busy" x-cloak x-on:click="items = []" class="text-sm font-medium text-gray-900 hover:text-brand-600">{{ __('Clear the list') }}</button>
                        <p x-show="summary()" x-cloak x-text="summary()" class="text-sm text-gray-600"></p>
                        <button type="button" x-show="done() && ! busy" x-cloak x-on:click="location.reload()" class="{{ $btn }}">{{ __('Show the new files') }}</button>
                    </div>
                </form>

                <details id="other-ways" class="group" @if ($openOther) open @endif>
                    <summary class="{{ $fold }} py-4">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-900">{{ __('Other ways to add files') }}</span>
                            <span class="block text-sm text-gray-600">{{ __('The client’s upload link, Google Drive, Dropbox, Canva') }}@if ($connected) · {{ __('Connected: :list', ['list' => implode(', ', $connected)]) }}@endif</span>
                        </span>
                        {!! $chevron !!}
                    </summary>
                    <div class="divide-y divide-gray-200 border-t border-gray-200">
                        <x-field :label="__('Upload link for the client')" for="inbox-url" :help="__('No login needed, valid :days days. Anyone with the link can upload.', ['days' => \App\Http\Controllers\AssetController::LINK_DAYS])">
                            <div class="flex gap-2" x-data="{ copied: false }">
                                <x-text-input id="inbox-url" type="text" class="block w-full min-w-0 font-mono text-xs" :value="$inboxUrl" readonly onclick="this.select()" />
                                <button type="button" class="{{ $btn }} flex-none" x-on:click="navigator.clipboard.writeText(document.getElementById('inbox-url').value).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => document.getElementById('inbox-url').select())"><span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span></button>
                            </div>
                        </x-field>

                        <x-field label="Google Drive" :help="__('A shared folder the client fills, checked every hour.')">
                            @error('drive') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            @if ($drive)
                                <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                                    <span>{{ __('Watching the folder “:name”: new photos and videos land in this inbox every hour.', ['name' => $drive->name]) }}
                                        @if ($drive->meta['pulled_at'] ?? null) {{ __('Last check :when, :n files in the folder.', ['when' => \Illuminate\Support\Carbon::parse($drive->meta['pulled_at'])->diffForHumans(), 'n' => $drive->meta['files'] ?? 0]) }} @endif</span>
                                    <form method="POST" action="{{ route('folders.pull', [$client, 'drive']) }}">@csrf<button class="font-medium text-gray-900 hover:text-brand-600">{{ __('Check now') }}</button></form>
                                    <form method="POST" action="{{ route('accounts.destroy', $drive) }}" onsubmit="return confirm('{{ __('Stop watching this folder?') }}')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">{{ __('Stop watching') }}</button></form>
                                </div>
                                {{-- the same folder often holds the client's documents too: those are the Files tab's business --}}
                                <p class="mt-2 text-sm text-gray-600"><a href="{{ route('clients.files', $client) }}" class="font-medium text-gray-900 hover:text-brand-600">{{ __('Documents in this folder (previous posts, menus, briefs) are read on the Files tab.') }}</a></p>
                            @elseif ($driveEmail)
                                <p class="text-sm text-gray-600">{{ __('Share a Drive folder with :email (viewer is enough), then paste its link here. Photos and videos the client drops in it reach this inbox within the hour.', ['email' => $driveEmail]) }}</p>
                                <form method="POST" action="{{ route('drive.watch', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">@csrf
                                    <x-text-input name="folder" type="text" class="block w-full min-w-0 text-sm" :value="old('folder')" placeholder="https://drive.google.com/drive/folders/…" />
                                    <x-primary-button class="flex-none">{{ __('Watch folder') }}</x-primary-button>
                                </form>
                            @else
                                <p class="text-sm text-gray-600">{{ __('Add GOOGLE_SERVICE_ACCOUNT_JSON to .env to pull files from a shared Drive folder (docs/pilot-setup.md).') }}</p>
                            @endif
                        </x-field>

                        <x-field label="Dropbox" :help="__('A Dropbox folder the client fills, checked every hour.')">
                            @error('dropbox') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            @if ($dropbox && ($dropbox->meta['folder'] ?? null))
                                <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                                    <span>{{ __('Watching the folder “:name”: new photos and videos land in this inbox every hour.', ['name' => $dropbox->meta['folder_name']]) }}
                                        @if ($dropbox->meta['pulled_at'] ?? null) {{ __('Last check :when, :n files in the folder.', ['when' => \Illuminate\Support\Carbon::parse($dropbox->meta['pulled_at'])->diffForHumans(), 'n' => $dropbox->meta['files'] ?? 0]) }} @endif</span>
                                    <form method="POST" action="{{ route('folders.pull', [$client, 'dropbox']) }}">@csrf<button class="font-medium text-gray-900 hover:text-brand-600">{{ __('Check now') }}</button></form>
                                    <form method="POST" action="{{ route('accounts.destroy', $dropbox) }}" onsubmit="return confirm('{{ __('Stop watching this folder?') }}')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">{{ __('Stop watching') }}</button></form>
                                </div>
                            @elseif ($dropbox)
                                <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                                    <span>{{ __('Connected as :name.', ['name' => $dropbox->name]) }} {{ __('Which folder? Its path in that Dropbox, for example /Clients/Almyra. A folder the client shared sits at the top level.') }}</span>
                                    <form method="POST" action="{{ route('accounts.destroy', $dropbox) }}" onsubmit="return confirm('{{ __('Disconnect Dropbox from this client?') }}')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">{{ __('Disconnect') }}</button></form>
                                </div>
                                <form method="POST" action="{{ route('dropbox.watch', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">@csrf
                                    <x-text-input name="folder" type="text" class="block w-full min-w-0 text-sm" :value="old('folder')" placeholder="/Clients/Almyra" />
                                    <x-primary-button class="flex-none">{{ __('Watch folder') }}</x-primary-button>
                                </form>
                            @elseif ($dropboxReady)
                                <p class="text-sm text-gray-600">{{ __('Bring the photos and videos a client drops in a shared Dropbox folder into this inbox.') }}</p>
                                <a href="{{ route('dropbox.connect', $client) }}" class="{{ $btn }} mt-3">{{ __('Connect Dropbox') }}</a>
                            @else
                                <p class="text-sm text-gray-600">{{ __('Add DROPBOX_APP_KEY to .env to pull files from a Dropbox folder (docs/pilot-setup.md).') }}</p>
                            @endif
                        </x-field>

                        <x-field label="Canva" :help="__('Finished designs from the agency’s Canva.')">
                            @error('canva') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            @if ($canva)
                                <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                                    <span>{{ __('Connected as :name.', ['name' => $canva->name]) }}</span>
                                    <form method="POST" action="{{ route('accounts.destroy', $canva) }}" onsubmit="return confirm('{{ __('Disconnect Canva from this client?') }}')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">{{ __('Disconnect') }}</button></form>
                                </div>
                                <form method="GET" action="{{ route('clients.assets', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">
                                    <x-text-input name="canva" type="text" class="block w-full min-w-0 text-sm" :value="request('canva')" placeholder="{{ __('Search designs, or leave empty for the newest') }}" />
                                    <x-primary-button class="flex-none">{{ __('Find') }}</x-primary-button>
                                </form>
                                @if ($canvaError) <p class="mt-2 text-sm text-red-600">{{ $canvaError }}</p> @endif
                                @if ($designs !== null)
                                    <form method="POST" action="{{ route('canva.import', $client) }}" class="mt-4 space-y-3">
                                        @csrf
                                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                            @forelse ($designs as $design)
                                                <label class="block cursor-pointer rounded-lg border border-gray-300 p-2 text-xs hover:bg-gray-50">
                                                    <input type="checkbox" name="designs[]" value="{{ $design['id'] }}" class="rounded border-gray-300 text-brand-600">
                                                    <input type="hidden" name="titles[{{ $design['id'] }}]" value="{{ $design['title'] ?? '' }}">
                                                    <input type="hidden" name="vertical[{{ $design['id'] }}]" value="{{ ($design['thumbnail']['height'] ?? 0) > ($design['thumbnail']['width'] ?? 0) ? 1 : 0 }}">
                                                    @if (! empty($design['thumbnail']['url']))<img src="{{ $design['thumbnail']['url'] }}" alt="" class="mt-1 aspect-square w-full rounded object-cover">@endif
                                                    <span class="mt-1 block truncate text-gray-900">{{ $design['title'] ?? __('Untitled') }}</span>
                                                    @if (($design['page_count'] ?? 1) > 1)<span class="text-gray-600">{{ $design['page_count'] }} {{ __('pages') }}</span>@endif
                                                </label>
                                            @empty
                                                <p class="col-span-full text-sm text-gray-600">{{ __('No designs found.') }}</p>
                                            @endforelse
                                        </div>
                                        @if ($designs)
                                            <div class="flex flex-wrap items-end gap-3 text-sm">
                                                <div>
                                                    <x-input-label for="canva-type" :value="__('Import as')" />
                                                    <select id="canva-type" name="type" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm"><option value="png">{{ __('PNG, one file per page') }}</option><option value="jpg">JPG</option><option value="mp4">{{ __('MP4 video') }}</option></select>
                                                </div>
                                                <div>
                                                    <x-input-label for="canva-pillar" :value="__('Pillar')" />
                                                    <select id="canva-pillar" name="pillar" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm"><option value="">—</option>@foreach ($pillars as $pillar)<option value="{{ $pillar }}">{{ $pillarName($pillar) }}</option>@endforeach</select>
                                                </div>
                                                <x-primary-button>{{ __('Import ticked designs') }}</x-primary-button>
                                                <span class="text-gray-600">{{ __('They appear in the list below within a minute or two; a failed import shows on the Jobs page and in Slack.') }}</span>
                                            </div>
                                        @endif
                                    </form>
                                @endif
                            @elseif ($canvaReady)
                                <p class="text-sm text-gray-600">{{ __('Bring finished designs straight into this inbox.') }}</p>
                                <a href="{{ route('canva.connect', $client) }}" class="{{ $btn }} mt-3">{{ __('Connect Canva') }}</a>
                            @else
                                <p class="text-sm text-gray-600">{{ __('Add CANVA_CLIENT_ID and CANVA_CLIENT_SECRET to .env to import designs (docs/pilot-setup.md).') }}</p>
                            @endif
                        </x-field>
                    </div>
                </details>
            </x-section>

            {{-- Tools: one fold, so the files come up high on the page --}}
            @if ($videos->isNotEmpty())
                <section class="rounded-xl border border-gray-300 bg-white">
                    <details id="tools" class="group" @if ($errors->reel->any()) open @endif>
                        <summary class="{{ $fold }} px-6 py-4">
                            <span class="min-w-0">
                                <span class="block text-lg font-semibold text-gray-900">{{ __('Tools') }}</span>
                                <span class="block text-sm text-gray-600">{{ __('Make a Reel') }}</span>
                            </span>
                            {!! $chevron !!}
                        </summary>
                        <div class="border-t border-gray-300 px-6 py-5">
                            <h3 class="text-base font-semibold text-gray-900">{{ __('Make a Reel') }}</h3>
                            @if ($reelReady)
                                <p class="mt-1 text-sm text-gray-600">{{ __('Joins 2 to 10 clips in file-name order, each cut to the chosen seconds, vertical 1080×1920 without sound. Add music in the Instagram app.') }}</p>
                                @if ($errors->reel->any())
                                    <ul class="mt-2 list-disc pl-5 text-sm text-red-600">@foreach ($errors->reel->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                                @endif
                                <form id="reel-form" method="POST" action="{{ route('assets.reel', $client) }}" class="mt-4 space-y-4 text-sm">
                                    @csrf
                                    <fieldset>
                                        <legend class="font-medium text-gray-900">{{ __('In the Reel: tick 2 to 10 clips') }}</legend>
                                        <ul class="mt-2 max-h-64 divide-y divide-gray-200 overflow-y-auto rounded-lg border border-gray-300">
                                            @foreach ($videos as $video)
                                                <li>
                                                    <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-gray-50">
                                                        <input type="checkbox" name="clips[]" value="{{ $video->id }}" class="rounded border-gray-300 text-brand-600" @checked(in_array($video->id, old('clips', [])))>
                                                        <span class="min-w-0 flex-1 truncate text-gray-900">{{ $video->original_name }}</span>
                                                        <span class="flex-none text-xs text-gray-600">{{ $mb($video->size) }} · {{ $video->created_at->format('d-m-Y') }}</span>
                                                    </label>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </fieldset>
                                    <div class="flex flex-wrap items-end gap-3">
                                        <div>
                                            <x-input-label for="reel-seconds" :value="__('Seconds per clip')" />
                                            <select id="reel-seconds" name="seconds" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm">@foreach ([2, 3, 4, 5, 8, 10, 15] as $s)<option value="{{ $s }}" @selected((int) old('seconds', 3) === $s)>{{ $s }}</option>@endforeach</select>
                                        </div>
                                        <div class="min-w-0 grow basis-56">
                                            <x-input-label for="reel-headline" :value="__('Headline on the first seconds (optional)')" />
                                            <x-text-input id="reel-headline" name="headline" type="text" class="mt-1 block w-full text-sm" :value="old('headline')" maxlength="80" />
                                        </div>
                                        <div>
                                            <x-input-label for="reel-pillar" :value="__('Pillar')" />
                                            <select id="reel-pillar" name="pillar" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm"><option value="">—</option>@foreach ($pillars as $pillar)<option value="{{ $pillar }}" @selected(old('pillar') === $pillar)>{{ $pillarName($pillar) }}</option>@endforeach</select>
                                        </div>
                                        <x-primary-button>{{ __('Make the Reel') }}</x-primary-button>
                                    </div>
                                </form>
                            @else
                                <p class="mt-1 text-sm text-gray-600">{{ __('Install ffmpeg on the server to assemble Reels from clips (docs/pilot-setup.md).') }}</p>
                            @endif
                        </div>
                    </details>
                </section>
            @endif

            {{-- The files: filters, then a grid of big thumbnails; a click opens the preview --}}
            <x-section :title="__('Files').' ('.$assets->count().')'" flush>
                <form method="GET" action="{{ route('clients.assets', $client) }}" class="grid grid-cols-2 gap-2 px-4 py-3 text-sm sm:flex sm:flex-wrap sm:items-center sm:px-6" data-filters>
                    <div class="col-span-2 flex gap-2 sm:w-80">
                        <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search by name or tag') }}" aria-label="{{ __('Search by name or tag') }}" class="block min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-sm">
                        <button type="submit" class="{{ $btn }} flex-none">{{ __('Search') }}</button>
                    </div>
                    <select name="kind" onchange="this.form.submit()" aria-label="{{ __('Photos and videos') }}" class="col-span-2 min-w-0 rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">{{ __('Photos and videos') }}</option>
                        <option value="image" @selected(request('kind') === 'image')>{{ __('Photos') }}</option>
                        <option value="video" @selected(request('kind') === 'video')>{{ __('Videos') }}</option>
                    </select>
                    <select name="pillar" onchange="this.form.submit()" aria-label="{{ __('Pillar') }}" class="min-w-0 rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">{{ __('Any pillar') }}</option>
                        @foreach ($pillars as $pillar)
                            <option value="{{ $pillar }}" @selected(request('pillar') === $pillar)>{{ $pillarName($pillar) }}</option>
                        @endforeach
                    </select>
                    <select name="used" onchange="this.form.submit()" aria-label="{{ __('Used in a post or not') }}" class="min-w-0 rounded-md border-gray-300 text-sm shadow-sm">
                        <option value="">{{ __('Used or not') }}</option>
                        <option value="yes" @selected(request('used') === 'yes')>{{ __('Used in a post') }}</option>
                        <option value="no" @selected(request('used') === 'no')>{{ __('Not used yet') }}</option>
                    </select>
                    @if ($filtered)
                        <a href="{{ route('clients.assets', $client) }}" class="col-span-2 px-1 font-medium text-gray-900 underline hover:text-brand-600">{{ __('Clear filters') }}</a>
                    @endif
                </form>

                <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 sm:gap-4 sm:p-6 lg:grid-cols-4">
                    @forelse ($assets as $asset)
                        @php
                            $usedIn = $asset->posts->map(fn ($post) => $post->trashed() ? $post->name.' ('.__('recycle bin').')' : $post->name)->join(', ');
                            $confirm = $usedIn === '' ? __('Delete this file?') : __('Delete this file? It is used in :posts and will be removed from it.', ['posts' => $usedIn]);
                            $open = [
                                'kind' => $asset->kind,
                                'name' => $asset->name,
                                'meta' => $asset->original_name.' · '.$mb($asset->size).' · '.$asset->created_at->format('d-m-Y'),
                                'url' => route('assets.view', $asset),
                                'download' => route('assets.download', $asset),
                                'broken' => false,
                            ];
                        @endphp
                        <article class="flex min-w-0 flex-col overflow-hidden rounded-lg border border-gray-300" data-asset="{{ $asset->id }}">
                            @if ($asset->kind === 'image')
                                <button type="button" x-on:click="preview = @js($open)" class="relative block aspect-square w-full overflow-hidden bg-gray-100" title="{{ __('Open') }}: {{ $asset->original_name }}">
                                    <span class="absolute inset-0 flex items-center justify-center text-xs font-medium uppercase text-gray-600">{{ pathinfo($asset->name, PATHINFO_EXTENSION) }}</span>
                                    <img src="{{ route('assets.thumb', $asset) }}" alt="{{ $asset->original_name }}" loading="lazy" class="relative h-full w-full object-cover" onerror="this.remove()">
                                </button>
                            @else
                                <button type="button" x-on:click="preview = @js($open)" class="flex aspect-square w-full flex-col items-center justify-center gap-2 bg-gray-900 text-white" title="{{ __('Open') }}: {{ $asset->original_name }}">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-white/15"><svg class="ml-0.5 h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 001.5.86l11-6.86a1 1 0 000-1.72l-11-6.86A1 1 0 008 5.14z"/></svg></span>
                                    <span class="text-xs font-medium">{{ __('video') }}</span>
                                </button>
                            @endif
                            <div class="flex min-w-0 flex-1 flex-col gap-1 p-3 text-xs">
                                <p class="truncate font-mono text-gray-900" title="{{ $asset->name }}">{{ $asset->name }}</p>
                                <p class="text-gray-600">{{ $pillarName($asset->pillar) }} · {{ $asset->created_at->format('d-m-Y') }} · {{ $sources[$asset->uploaded_via] ?? $asset->uploaded_via }}</p>
                                @if ($asset->season || $asset->people_consent || $asset->ugc_permission)
                                    <p class="flex flex-wrap gap-1">
                                        @if ($asset->season)<span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700">{{ $asset->season }}</span>@endif
                                        @if ($asset->people_consent)<span class="rounded bg-green-100 px-1.5 py-0.5 text-green-800">{{ __('consent') }}</span>@endif
                                        @if ($asset->ugc_permission)<span class="rounded bg-green-100 px-1.5 py-0.5 text-green-800">{{ __('UGC ok') }}</span>@endif
                                    </p>
                                @endif
                                @if ($asset->note) <p class="break-words text-gray-600">{{ $asset->note }}</p> @endif
                                <div>
                                    @forelse ($asset->posts as $post)
                                        @if ($post->trashed())
                                            <a href="{{ route('clients.bin', $client) }}" class="block truncate text-gray-600 hover:text-brand-600" title="{{ $post->name }}">{{ __('In the recycle bin:') }} {{ $post->name }}</a>
                                        @else
                                            <a href="{{ route('posts.edit', $post) }}" class="block truncate font-medium text-gray-900 hover:text-brand-600" title="{{ $post->name }}">{{ __('In') }} {{ $post->name }}</a>
                                        @endif
                                    @empty
                                        <span class="text-gray-500">{{ __('Not used in a post yet') }}</span>
                                    @endforelse
                                </div>
                                <div class="mt-auto flex flex-wrap items-center gap-x-2.5 gap-y-1 pt-2 sm:gap-x-3">
                                    <button type="button" x-on:click="preview = @js($open)" class="font-medium text-gray-900 hover:text-brand-600">{{ __('Open') }}</button>
                                    <a href="{{ route('assets.download', $asset) }}" class="font-medium text-gray-900 hover:text-brand-600">{{ __('Download') }}</a>
                                    <form method="POST" action="{{ route('assets.destroy', $asset) }}" data-confirm="{{ $confirm }}" onsubmit="return confirm(this.dataset.confirm)">
                                        @csrf
                                        @method('DELETE')
                                        @foreach ($keep as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                                        <button type="submit" class="text-red-600 hover:underline">{{ __('Delete') }}</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @empty
                        @if ($filtered)
                            <x-empty-state class="col-span-full" :action="__('Show everything')" :href="route('clients.assets', $client)">{{ __('Nothing matches these filters.') }}</x-empty-state>
                        @else
                            <x-empty-state class="col-span-full" :action="__('Upload link')" href="#inbox-url">{{ __('Nothing uploaded yet. Add files above, or send the client the upload link.') }}</x-empty-state>
                        @endif
                    @endforelse
                </div>
            </x-section>
        </div>

        {{-- The preview: the photo or the video itself, with Download and a new tab --}}
        <div x-show="preview" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-3 sm:p-6" x-on:click.self="preview = null" role="dialog" aria-modal="true" data-preview>
            <template x-if="preview">
                <div class="flex max-h-full w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white">
                    <div class="flex items-start gap-3 border-b border-gray-300 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-mono text-sm text-gray-900" x-text="preview.name"></p>
                            <p class="truncate text-xs text-gray-600" x-text="preview.meta"></p>
                        </div>
                        <button type="button" x-on:click="preview = null" class="{{ $btn }} flex-none px-2.5 py-1">{{ __('Close') }}</button>
                    </div>
                    <div class="flex min-h-0 flex-1 items-center justify-center bg-gray-900">
                        <template x-if="preview.kind === 'image' && ! preview.broken"><img x-bind:src="preview.url" alt="" class="max-h-[70vh] max-w-full object-contain" x-on:error="preview.broken = true"></template>
                        <template x-if="preview.kind === 'video'"><video x-bind:src="preview.url" controls playsinline class="max-h-[70vh] w-full"></video></template>
                        <p x-show="preview.broken" class="px-6 py-16 text-center text-sm text-white">{{ __('This browser cannot show this file. Download it to see it.') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <a x-bind:href="preview.download" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-3.5 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ __('Download') }}</a>
                        <a x-bind:href="preview.url" target="_blank" rel="noopener" class="text-sm font-medium text-gray-900 hover:text-brand-600">{{ __('Open in a new tab') }}</a>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <script>
        // The staff upload: files dropped anywhere on the page or picked wait in a list; Upload sends them three at a time, one file per
        // request, so each shows its own progress and a refused file never stops the others. When all went in, the page reloads with them.
        window.assetUploader = (config) => ({
            items: [], over: false, busy: false, next: 0,
            hasFiles(e) { return [...(e.dataTransfer?.types || [])].includes('Files'); },
            dragover(e) { if (this.hasFiles(e)) { e.preventDefault(); this.over = true; } },
            drop(e) { if (this.hasFiles(e)) { e.preventDefault(); this.over = false; this.add(e.dataTransfer.files); } },
            add(files) {
                for (const file of files) {
                    const item = { id: ++this.next, file, state: 'waiting', progress: 0, message: '' };
                    if (file.size > config.maxBytes) Object.assign(item, { state: 'failed', message: config.text.tooBig });
                    else if (file.type && !/^(image|video)\//.test(file.type)) Object.assign(item, { state: 'failed', message: config.text.type }); // the server checks the exact types
                    this.items.push(item);
                }
            },
            remove(item) { this.items = this.items.filter(i => i.id !== item.id); },
            waiting() { return this.items.filter(i => i.state === 'waiting'); },
            done() { return this.items.filter(i => i.state === 'done').length; },
            mb(bytes) { return bytes < 1048576 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (bytes / 1048576).toFixed(1) + ' MB'; },
            buttonLabel() { const n = this.waiting().length; return n > 1 ? config.text.buttonMany.replace(':n', n) : config.text.button; },
            status(item) {
                return { waiting: config.text.waiting, uploading: config.text.uploading.replace(':percent', item.progress) }[item.state] ?? item.message;
            },
            summary() {
                const failed = this.items.filter(i => i.state === 'failed').length;
                return this.busy || !(this.done() + failed) || this.waiting().length ? '' : config.text.summary.replace(':done', this.done()).replace(':failed', failed);
            },
            async start() {
                const queue = this.waiting();
                if (!queue.length) return document.getElementById('upload-files').click();
                this.busy = true;
                const worker = async () => { for (let item; (item = queue.shift());) await this.send(item); };
                await Promise.all([worker(), worker(), worker()]);
                this.busy = false;
                if (this.items.every(i => i.state === 'done')) location.reload();
            },
            send(item) {
                return new Promise(resolve => {
                    const data = new FormData(this.$root);
                    data.delete('files[]');
                    data.append('files[]', item.file);
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', this.$root.action);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    item.state = 'uploading';
                    xhr.upload.onprogress = e => { if (e.lengthComputable) item.progress = Math.min(99, Math.round(e.loaded / e.total * 100)); };
                    xhr.onload = () => {
                        let json = {};
                        try { json = JSON.parse(xhr.responseText); } catch (e) {}
                        if (xhr.status === 201 && json.uploaded?.length) {
                            Object.assign(item, { state: 'done', progress: 100, message: config.text.added.replace(':name', json.uploaded[0].name) });
                        } else {
                            const reason = json.failed?.[0]?.error || Object.values(json.errors || {})[0]?.[0] || { 413: config.text.tooBig, 419: config.text.expired }[xhr.status] || json.message || config.text.error;
                            Object.assign(item, { state: 'failed', message: reason });
                        }
                        resolve();
                    };
                    xhr.onerror = () => { Object.assign(item, { state: 'failed', message: config.text.offline }); resolve(); };
                    xhr.send(data);
                });
            },
        });
        // "Upload link" in the empty list points into the folded other ways: open them first
        document.addEventListener('click', e => { if (e.target.closest('a[href="#inbox-url"]')) document.getElementById('other-ways').open = true; });
    </script>
</x-app-layout>
