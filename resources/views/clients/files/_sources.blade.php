{{-- The other ways in: the client's upload link and the watched folders, checked every hour, and Canva --}}
<x-section icon="cloud-arrow-down" x-show="tab === 'sources'" x-cloak :title="__('Other ways to add files')" :description="__('The client’s upload link, Google Drive, Dropbox, Canva').($connected ? ' · '.__('Connected: :list', ['list' => implode(', ', $connected)]) : '')">
            <x-field :label="__('Upload link for the client')" for="inbox-url" :help="__('No login needed, valid :days days. Anyone with the link can upload.', ['days' => \App\Http\Controllers\AssetController::LINK_DAYS])">
                <div class="flex gap-2" x-data="{ copied: false }">
                    <x-text-input id="inbox-url" type="text" class="block w-full min-w-0 font-mono text-xs" :value="$inboxUrl" readonly onclick="this.select()" />
                    <button type="button" class="{{ $btn }} flex-none" x-on:click="navigator.clipboard.writeText(document.getElementById('inbox-url').value).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => document.getElementById('inbox-url').select())"><x-icon name="clipboard-document" class="h-4 w-4" /><span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span></button>
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
                    {{-- the same folder often holds the client's documents too: those are read under Google Drive folder, for Claude --}}
                    <p class="mt-2 text-sm text-gray-600"><a href="#folder" class="font-medium text-gray-900 hover:text-brand-600">{{ __('Its documents (previous posts, menus, briefs) can be read for Claude too, under Google Drive folder.') }}</a></p>
                @elseif ($driveEmail)
                    <p class="text-sm text-gray-600">{{ __('Share a Drive folder with :email (viewer is enough), then paste its link here. Photos and videos the client drops in it reach this inbox within the hour.', ['email' => $driveEmail]) }}</p>
                    <form method="POST" action="{{ route('drive.watch', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">@csrf
                        <x-text-input name="folder" type="text" class="block w-full min-w-0 text-sm" :value="$errors->has('drive') ? old('folder') : ''" placeholder="https://drive.google.com/drive/folders/…" required />
                        <x-primary-button class="flex-none"><x-icon name="eye" class="h-4 w-4" />{{ __('Watch folder') }}</x-primary-button>
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
                        <form method="POST" action="{{ route('accounts.destroy', $dropbox) }}" onsubmit="return confirm('{{ __('Disconnect Dropbox from this client?') }}')">@csrf @method('DELETE')<button class="inline-flex items-center gap-1 text-red-600 hover:underline"><x-icon name="link-slash" class="h-4 w-4" />{{ __('Disconnect') }}</button></form>
                    </div>
                    <form method="POST" action="{{ route('dropbox.watch', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">@csrf
                        <x-text-input name="folder" type="text" class="block w-full min-w-0 text-sm" :value="$errors->has('dropbox') ? old('folder') : ''" placeholder="/Clients/Almyra" required />
                        <x-primary-button class="flex-none"><x-icon name="eye" class="h-4 w-4" />{{ __('Watch folder') }}</x-primary-button>
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
                        <form method="POST" action="{{ route('accounts.destroy', $canva) }}" onsubmit="return confirm('{{ __('Disconnect Canva from this client?') }}')">@csrf @method('DELETE')<button class="inline-flex items-center gap-1 text-red-600 hover:underline"><x-icon name="link-slash" class="h-4 w-4" />{{ __('Disconnect') }}</button></form>
                    </div>
                    <form method="GET" action="{{ route('clients.files', $client) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <x-text-input name="canva" type="text" class="block w-full min-w-0 text-sm" :value="request('canva')" placeholder="{{ __('Search designs, or leave empty for the newest') }}" />
                        <x-primary-button class="flex-none"><x-icon name="magnifying-glass" class="h-4 w-4" />{{ __('Find') }}</x-primary-button>
                    </form>
                    @if ($canvaError) <p class="mt-2 text-sm text-red-600">{{ $canvaError }}</p> @endif
                    @if ($canvaDesigns !== null)
                        <form method="POST" action="{{ route('canva.import', $client) }}" class="mt-4 space-y-3">
                            @csrf
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                @forelse ($canvaDesigns as $design)
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
                            @if ($canvaDesigns)
                                <div class="flex flex-wrap items-end gap-3 text-sm">
                                    <div>
                                        <x-input-label for="canva-type" :value="__('Import as')" />
                                        <select id="canva-type" name="type" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm"><option value="png">{{ __('PNG, one file per page') }}</option><option value="jpg">JPG</option><option value="mp4">{{ __('MP4 video') }}</option></select>
                                    </div>
                                    <div>
                                        <x-input-label for="canva-pillar" :value="__('Pillar')" />
                                        <select id="canva-pillar" name="pillar" class="mt-1 block rounded-md border-gray-300 text-sm shadow-sm"><option value="">—</option>@foreach ($pillars as $pillar)<option value="{{ $pillar }}">{{ $pillarName($pillar) }}</option>@endforeach</select>
                                    </div>
                                    <x-primary-button><x-icon name="arrow-down-tray" class="h-4 w-4" />{{ __('Import ticked designs') }}</x-primary-button>
                                    <span class="text-gray-600">{{ __('They appear under Photos and videos within a minute or two; a failed import shows on the Jobs page and in Slack.') }}</span>
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
</x-section>
