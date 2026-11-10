{{-- the pictures come from Drive on each view (clients.files.preview) and are never kept; only Claude's description is --}}
<x-section icon="swatch" x-show="tab === 'designs'" x-cloak :title="__('Designs')" :description="__('Photos, graphics, PDFs and videos of the posts the client published: Claude looks at Drive\'s preview of each one and describes the post it shows, once. Only that text is kept here, never the picture.')" flush>
    @if (! $folder)
        <div class="p-6">
            <x-empty-state icon="swatch" :action="__('Google Drive folder')" action-icon="folder" href="#folder">{{ __('Link the client’s Google Drive folder first: the designs in it are listed here.') }}</x-empty-state>
        </div>
    @elseif ($designTotal === 0)
        <div class="p-6">
            <x-empty-state icon="swatch">{{ __('No designs in this folder yet. Photos, Illustrator, Photoshop, InDesign and EPS files, PDFs and videos are listed here.') }}</x-empty-state>
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
