{{-- the client's folder on the office NAS: named in the profile, opened by Claude itself through the Inglelandi NAS connector; nothing from it is copied here (D29) --}}
<x-section icon="server-stack" x-show="tab === 'nas'" x-cloak :title="__('Office NAS folder')" :description="__('The client\'s folder on the office NAS. Claude opens it through the Inglelandi NAS connector when it plans or writes posts for this client: past posts, designs and documents. Only the photos and videos Claude puts on posts are copied here, to Photos and videos, marked Office NAS.')">
    <div class="py-5 text-sm">
        @if ($nasFolder = \App\Planning\NasFolder::path($client))
            <p class="font-mono text-gray-900">Clients/{{ $nasFolder }}</p>
            <p class="mt-2 text-gray-600">{{ __('For the hourly Claude worker, the Inglelandi NAS connector has to be ticked on its scheduled task.') }}</p>
            {{-- D30: the posts Claude plans or writes get their photos from the folder, when the connector's download key is set --}}
            @if (\App\Media\NasConnector::configured())
                <p class="mt-2 text-gray-600">{{ __('Claude puts photos and videos from this folder on the posts it plans or writes that have none yet (:n copied so far); the team changes them on the post.', ['n' => $client->assets()->where('uploaded_via', 'nas')->count()]) }}</p>
            @else
                <p class="mt-2 text-gray-600">{{ __('To let Claude put photos and videos from this folder on the posts it plans, set NAS_CONNECTOR_URL and NAS_DOWNLOAD_KEY (the connector\'s download key) in Settings.') }}</p>
            @endif
        @else
            <p class="text-gray-600">{{ __('No folder named yet.') }}</p>
        @endif
        <a href="{{ route('clients.edit', $client) }}#research" class="mt-3 inline-flex items-center gap-1 font-medium text-gray-900 hover:text-brand-600"><x-icon name="pencil-square" class="h-4 w-4" />{{ __('Change it in the profile (Research)') }}</a>
    </div>
</x-section>
