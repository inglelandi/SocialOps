{{-- Make a Reel: 2 to 10 of the client's clips joined in the queue (AssembleReel), filed like an upload --}}
<x-section icon="film" x-show="tab === 'reel'" x-cloak :title="__('Make a Reel')" :description="__('Joins 2 to 10 clips in file-name order, each cut to the chosen seconds, vertical 1080×1920 without sound. Add music in the Instagram app.')">
    <div class="py-5">
        @if (! $reelReady)
            <p class="text-sm text-gray-600">{{ __('Install ffmpeg on the server to assemble Reels from clips (docs/pilot-setup.md).') }}</p>
        @elseif ($videos->count() < 2)
            <x-empty-state icon="film" :action="__('Add files')" action-icon="cloud-arrow-up" href="#media">{{ __('Upload at least two videos to make a Reel.') }}</x-empty-state>
        @else
                        @if ($errors->reel->any())
                            <ul class="mt-2 list-disc pl-5 text-sm text-red-600">@foreach ($errors->reel->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        @endif
                        <form id="reel-form" method="POST" action="{{ route('assets.reel', $client) }}" class="space-y-4 text-sm">
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
                                <x-primary-button><x-icon name="film" class="h-4 w-4" />{{ __('Make the Reel') }}</x-primary-button>
                            </div>
                        </form>
        @endif
    </div>
</x-section>
