<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('files') }}</h2>
    </x-slot>

    @php
        $pillars = array_keys($client->profile['pillars']);
        $pillarName = fn (?string $pillar) => $pillar ? ucfirst(str_replace('_', ' ', $pillar)) : __('No pillar');
        $sources = \App\Models\Asset::sources();
        $keep = collect(request()->only(\App\Http\Controllers\AssetController::FILTERS))->filter(fn ($v) => is_string($v) && $v !== ''); // the grid's filters, carried through a delete
        $mb = fn (int $bytes) => $bytes < 1048576 ? max(1, round($bytes / 1024)).' KB' : number_format($bytes / 1048576, 1).' MB';
        $btn = 'inline-flex items-center justify-center gap-1.5 rounded-lg border border-gray-400 bg-white px-3.5 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50';
        $connected = array_keys(array_filter(['Google Drive' => $drive, 'Dropbox' => $dropbox?->meta['folder'] ?? null, 'Canva' => $canva]));
        // the side list: the photos and videos and the ways in, then what Claude reads when it plans this client
        $groups = [
            [null, [
                'media' => [__('Photos and videos'), 'photo', $assetTotal],
                'sources' => [__('Other ways to add files'), 'cloud-arrow-down', null],
                'reel' => [__('Make a Reel'), 'film', null],
            ]],
            [__('What Claude reads'), [
                'slideshow' => [__('Posts slideshow'), 'presentation-chart-bar', null],
                'folder' => [__('Google Drive folder'), 'folder', $documents->where('chars', '>', 0)->count()],
                'designs' => [__('Designs'), 'swatch', $designTotal],
                'feed' => [__('Product feed'), 'tag', $productTotal],
            ]],
        ];
        // the section to open: the one the last action or error was about, else the address's #section (the script), else the one opened last
        $open = session('tab') ?? match (true) {
            $errors->upload->any() => 'media',
            $errors->reel->any() => 'reel',
            $errors->hasAny(['canva', 'drive', 'dropbox']) || request()->has('canva') || in_array(session('status'), ['canva-connected', 'dropbox-connected'], true) => 'sources',
            $errors->has('slideshow') => 'slideshow',
            $errors->has('folder') => 'folder',
            $errors->has('feed') || request()->has('product') => 'feed',
            request()->boolean('all') => 'designs',
            request()->hasAny(\App\Http\Controllers\AssetController::FILTERS) => 'media',
            default => $section,
        };
        $status = session('status');
    @endphp

    <div class="py-6" x-data="filesPage(@js(['tabs' => collect($groups)->flatMap(fn ($group) => array_keys($group[1]))->all(), 'open' => $open]))" x-on:hashchange.window="fromHash()" x-on:keydown.escape.window="preview = null">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if ($status)
                {{-- a code (asset-deleted) is shown as words; a sentence as it is --}}
                <p class="mb-4 flex items-center gap-1.5 text-sm font-medium text-green-700"><x-icon name="check-circle" class="h-5 w-5" />{{ preg_match('/^[a-z]+(-[a-z]+)+$/', $status) ? __(ucfirst(str_replace('-', ' ', $status))) : $status }}.</p>
            @endif

            <div class="md:flex md:gap-8">
                <x-side-tabs :groups="$groups" :label="__('Files')" />
                {{-- one section shows at a time: no spacing between them, or the hidden ones would push the open one down --}}
                <div class="min-w-0 flex-1">
                    @include('clients.files._media')
                    @include('clients.files._sources')
                    @include('clients.files._reel')
                    @include('clients.files._slideshow')
                    @include('clients.files._folder')
                    @include('clients.files._designs')
                    @include('clients.files._feed')
                </div>
            </div>
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
                        <a x-bind:href="preview.download" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-500 px-3.5 py-2 text-sm font-medium text-white hover:bg-brand-600"><x-icon name="arrow-down-tray" class="h-4 w-4" />{{ __('Download') }}</a>
                        <a x-bind:href="preview.url" target="_blank" rel="noopener" class="text-sm font-medium text-gray-900 hover:text-brand-600">{{ __('Open in a new tab') }}</a>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <script>
        // The page's sections: the address's #section first (a link like #folder opens it too), else the one the server names, else the
        // one opened last in this browser; the open one goes into the address, so a reload stays on it.
        window.filesPage = ({ tabs, open }) => ({
            preview: null,
            tabs,
            tab: (() => {
                const hash = location.hash.slice(1);
                let last = null;
                try { last = localStorage.getItem('client-files-tab'); } catch (e) {}
                return tabs.includes(hash) ? hash : (open || (tabs.includes(last) ? last : 'media'));
            })(),
            init() {
                // on a phone the side list is a row that scrolls sideways: keep the open section's button in sight
                const reveal = () => this.$nextTick(() => {
                    const button = this.$root.querySelector(`[data-tab="${this.tab}"]`);
                    if (button) button.parentElement.scrollLeft = button.offsetLeft - button.parentElement.offsetLeft - 16;
                });
                reveal();
                this.$watch('tab', tab => {
                    try { localStorage.setItem('client-files-tab', tab); } catch (e) {}
                    history.replaceState(null, '', '#' + tab);
                    reveal();
                });
            },
            fromHash() { const hash = location.hash.slice(1); if (this.tabs.includes(hash)) this.tab = hash; },
        });
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
    </script>
</x-app-layout>
