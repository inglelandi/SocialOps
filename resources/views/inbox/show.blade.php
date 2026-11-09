<x-guest-layout>
    <h1 class="text-lg font-semibold text-gray-900">{{ $client->name }}</h1>
    <p class="mt-1 text-sm text-gray-600">{{ __('Send us photos and videos for your social media. They go straight into your folder at the agency.') }}</p>

    @if (session('uploaded'))
        <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800">
            <p class="font-medium">{{ __('Received, thank you!') }}</p>
            <ul class="mt-1 list-disc pl-5 font-mono text-xs">
                @foreach (session('uploaded') as $name)
                    <li>{{ $name }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($errors->any())
        <ul class="mt-4 list-disc pl-5 text-sm text-red-600">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ request()->fullUrl() }}" enctype="multipart/form-data" class="mt-4 space-y-4">
        @csrf
        <div>
            <x-input-label for="files" :value="__('Photos and videos (JPG, PNG, HEIC, MP4, MOV)')" />
            <input id="files" name="files[]" type="file" multiple accept="image/*,video/mp4,video/quicktime" required class="mt-1 block w-full text-sm text-gray-700" />
        </div>
        <div>
            <x-input-label for="pillar" :value="__('What is it about?')" />
            <select id="pillar" name="pillar" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                <option value="">{{ __('Not sure') }}</option>
                @foreach (array_keys($client->profile['pillars']) as $pillar)
                    <option value="{{ $pillar }}" @selected(old('pillar') === $pillar)>{{ ucfirst(str_replace('_', ' ', $pillar)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="season" :value="__('Season or event (optional)')" />
            <x-text-input id="season" name="season" type="text" class="mt-1 block w-full" :value="old('season')" placeholder="{{ __('e.g. summer 2027, opening party') }}" />
        </div>
        <label class="flex items-start gap-2 text-sm text-gray-700">
            <input type="checkbox" name="people_consent" value="1" class="mt-1 rounded border-gray-300" @checked(old('people_consent')) />
            <span>{{ __('The people shown have agreed to appear on social media.') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-gray-700">
            <input type="checkbox" name="ugc_permission" value="1" class="mt-1 rounded border-gray-300" @checked(old('ugc_permission')) />
            <span>{{ __('This was made by a guest and they allow us to repost it.') }}</span>
        </label>
        <div>
            <x-input-label for="note" :value="__('Anything we should know? (optional)')" />
            <textarea id="note" name="note" rows="2" maxlength="500" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">{{ old('note') }}</textarea>
        </div>
        <x-primary-button class="w-full justify-center"><x-icon name="paper-airplane" class="h-4 w-4" />{{ __('Send') }}</x-primary-button>
    </form>
</x-guest-layout>
