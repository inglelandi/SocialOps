<?php

namespace App\Http\Controllers;

use App\Alerts\Slack;
use App\Drafting\CaptionDrafter;
use App\Drafting\CaptionEditor;
use App\Drafting\CaptionShortener;
use App\Http\Requests\PostRequest;
use App\Media\TextOverlay;
use App\Models\Client;
use App\Models\Post;
use App\Posts\CsvImport;
use App\Posts\InvalidTransition;
use App\Posts\PostNamer;
use App\Posts\PostState;
use App\Publishing\ManualNeeded;
use App\Publishing\Publishers;
use App\Publishing\PublishFailed;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class PostController extends Controller
{
    public function create(Client $client): View
    {
        // the calendar's "+" passes the day; a date the address mangled is left out, not an error page
        $post = new Post(['platforms' => [], 'format' => 'feed', 'scheduled_at' => CalendarController::day(request('date'))?->setTime(10, 0)]);

        return view('posts.create', ['client' => $client, 'post' => $post, 'variants' => collect()]);
    }

    public function store(PostRequest $request, Client $client): RedirectResponse
    {
        $post = $this->createPost($request, $client);

        return redirect()->route('posts.edit', $post)->with('status', 'post-created');
    }

    /** A new Draft from a validated post request, with its variants and assets; the form and the JSON API share it. */
    public function createPost(PostRequest $request, Client $client): Post
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $client, $data) {
            $date = ($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : now(); // absent when the form is posted without the field
            $post = $client->posts()->create([
                'name' => PostNamer::name($client, $data['platforms'], $data['format'], $data['pillar'] ?? null, $date),
                'state' => PostState::Draft,
                'platforms' => $data['platforms'],
                'format' => $data['format'],
                'pillar' => $data['pillar'] ?? null,
                'brief' => $data['brief'] ?? null,
                'settings' => $data['settings'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            $post->variants()->createMany($request->variantRows());
            $post->assets()->sync($this->assetPositions($data['assets'] ?? []));

            return $post;
        });
    }

    /** The CSV template for a bulk import: the client's own columns and one example row. */
    public function importTemplate(Client $client): Response
    {
        return response(CsvImport::template($client), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$client->code.'_posts_template.csv"']);
    }

    /** Bulk import: every row becomes a Draft on the calendar, or nothing does and the row errors come back. */
    public function import(Request $request, Client $client): RedirectResponse
    {
        $request->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $result = CsvImport::import($client, $request->file('csv')->get(), $request->user());
        if ($result['errors']) {
            return redirect()->route('clients.edit', $client)->withErrors(['csv' => $result['errors']]);
        }

        return redirect()->route('calendar', ['client' => $client->slug])->with('status', __(':n drafts imported', ['n' => $result['imported']]));
    }

    public function edit(Post $post): View|RedirectResponse
    {
        if ($post->trashed()) { // an old link (Slack, a mail, the Back button): the post is in the recycle bin, where it can be restored
            return redirect()->route('clients.bin', $post->client_id);
        }

        return view('posts.edit', [
            'client' => $post->client,
            'post' => $post,
            'variants' => $post->variants->keyBy(fn ($v) => "{$v->platform}.{$v->lang}"),
        ]);
    }

    /** Claude drafts EL and EN captions into the form; a human edits them before the post goes for review. */
    public function draft(CaptionDrafter $drafter, Post $post): RedirectResponse
    {
        abort_if($post->state === PostState::Published, 422, 'Published posts cannot be redrafted.');
        $vars = CaptionDrafter::postVars($post);

        try {
            $captions = $drafter->draft($post->client->toProfileArray(), $vars['post'], $vars['examples'], $post->assets->firstWhere('kind', 'image')?->preview());
        } catch (\Exception $e) { // a malformed reply or an API error is shown on the form, not as a 500
            return back()->withErrors(['draft' => $e->getMessage()]);
        }

        // shortcut: only el and en are drafted (the prompt's schema), extra_languages stay hand-written until a client needs more
        foreach ($post->platforms as $platform) {
            foreach (['el', 'en'] as $lang) {
                $post->variants()->updateOrCreate(['platform' => $platform, 'lang' => $lang], [
                    'caption' => $captions[$lang][$platform],
                    'alt_text' => $platform === 'tiktok' ? null : $captions['alt_text'][$lang],
                    'hashtags' => $captions['hashtags'],
                ]);
            }
        }
        $post->update(['settings' => ['design' => $captions['design'] ?? null] + ($post->settings ?? [])]);
        $post->reopenForReview();

        return redirect()->route('posts.edit', $post)->with(['status' => 'captions-drafted', 'notes' => $captions['notes_for_reviewer']]);
    }

    /** Shorten the captions typed for one platform so the published text fits; the JSON goes back into the form, nothing is saved. */
    public function shorten(Request $request, CaptionShortener $shortener, Post $post): JsonResponse
    {
        $data = $request->validate(['platform' => ['required', Rule::in(Post::PLATFORMS)], 'captions' => ['required', 'array'], 'captions.*' => ['nullable', 'string'], 'hashtags' => ['nullable', 'string']]);
        $captions = array_filter(array_map('trim', $data['captions']));
        if ($captions === []) {
            return response()->json(['error' => 'Nothing to shorten.'], 422);
        }
        try {
            return response()->json($shortener->shorten($post->client->toProfileArray(), $data['platform'], $captions, preg_split('/\s+/', trim((string) ($data['hashtags'] ?? '')), -1, PREG_SPLIT_NO_EMPTY)));
        } catch (\Exception $e) { // a malformed reply or an API error is shown next to the button, not as a 500
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** The "Change with Claude" box: the captions as typed for every ticked platform come back rewritten as the instruction says, for the form to show unsaved. */
    public function rewrite(Request $request, CaptionEditor $editor, Post $post): JsonResponse
    {
        $languages = array_merge($post->client->profile['languages'], $post->client->profile['extra_languages'] ?? []);
        $data = $request->validate([
            'instruction' => ['required', 'string', 'max:1000'],
            'brief' => ['nullable', 'string'],
            'captions' => ['required', 'array:'.implode(',', Post::PLATFORMS)],
            'captions.*' => ['required', 'array:'.implode(',', $languages)],
            'captions.*.*' => ['nullable', 'string'],
        ]);
        $captions = array_map(fn (array $langs) => array_map(fn ($text) => trim((string) $text), $langs), $data['captions']);
        try {
            return response()->json($editor->rewrite($post->client->toProfileArray(), trim($data['instruction']), $captions, trim((string) ($data['brief'] ?? ''))));
        } catch (\Exception $e) { // a malformed reply or an API error is shown next to the box, not as a 500
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function update(PostRequest $request, Post $post): RedirectResponse
    {
        $this->updatePost($request, $post);

        return redirect()->route('posts.edit', $post)->with('status', 'post-updated');
    }

    /** Save the form over the post; a change to the captions sends an approved post back to review. Shared with the MCP update-post tool. */
    public function updatePost(PostRequest $request, Post $post): Post
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $post, $data) {
            $rows = $request->variantRows();
            // captions saved earlier from a browser may carry CRLF: compared in one form, so an untouched caption is no change
            $before = $post->variants->map(fn ($v) => ['caption' => PostRequest::lines($v->caption), 'alt_text' => PostRequest::lines($v->alt_text)] + $v->only(['platform', 'lang', 'hashtags']))->values()->all();

            $post->fill([
                'platforms' => $data['platforms'],
                'format' => $data['format'],
                'pillar' => $data['pillar'] ?? null,
                'brief' => $data['brief'] ?? null,
                'settings' => Post::mergeSettings($post->settings, $data['settings'] ?? []),
                'scheduled_at' => $data['scheduled_at'] ?? null,
            ])->save();

            $assetsBefore = $post->assets->pluck('id')->all(); // in carousel order
            $assets = $this->assetPositions($data['assets'] ?? []);

            $post->variants()->delete();
            $post->variants()->createMany($rows);
            $post->assets()->sync($assets);

            // the client approved these words and these pictures in this order: a change to either goes back to them
            if ($before != $rows || $assetsBefore !== array_keys($assets)) {
                $post->reopenForReview();
            }
        });

        return $post;
    }

    public function transition(Request $request, Post $post): RedirectResponse
    {
        $to = PostState::from($request->validate(['state' => ['required', Rule::enum(PostState::class)]])['state']);
        abort_if($to->needsPublishing() && ! config('features.publishing'), 404);
        $back = $to === $post->state->back(); // "Not published after all"
        $repeated = $post->settings['repeated_as'] ?? null;

        try {
            if ($to === PostState::Published && $post->state === PostState::Approved && $post->scheduled_at) {
                $post->transitionTo(PostState::Scheduled); // posted by hand (the Post today page) before anyone pressed Schedule: the approval stands
            }
            $to === PostState::Withdrawn ? $this->withdraw($post) : $post->transitionTo($to);
        } catch (InvalidTransition|PublishFailed $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }
        $flash = [
            'status' => $back ? 'marked-not-published' : 'post-'.str_replace('_', '-', $post->state->value),
            // a repeating post marked published drafted its next occurrence (Post::spawnRepeat()): the page names it
            'repeat' => ($post->settings['repeated_as'] ?? null) !== $repeated ? Post::find($post->settings['repeated_as'])?->only(['id', 'name']) : null,
        ];
        $return = (string) $request->input('return');

        // the Post today page sends its own address along, to stay on the list; nothing else is followed
        return $return === route('today') || str_starts_with($return, route('today').'?')
            ? redirect()->to($return)->with($flash + ['done' => $post->id])
            : redirect()->route('posts.edit', $post)->with($flash);
    }

    /**
     * Take the post down on every platform that has an API for it (Facebook); the ids of the rest stay on
     * the post with a note, and Slack is told to delete those by hand. A platform refusal stops here, the removed ids are gone, so a retry picks up the rest.
     */
    private function withdraw(Post $post): void
    {
        throw_unless($post->state->canTransitionTo(PostState::Withdrawn), new InvalidTransition("Only a published post can be withdrawn, this one is {$post->state->label()}."));
        $ids = $post->external_ids ?? [];
        $manual = [];
        foreach ($ids as $platform => $id) {
            try {
                $account = $post->client->accounts()->where('platform', $platform)->first() ?? throw new ManualNeeded("No {$platform} account is connected any more. Delete it in the app.");
                Publishers::for($platform)->withdraw($account, $id);
                unset($ids[$platform]);
                $post->forceFill(['external_ids' => $ids ?: null])->save();
            } catch (ManualNeeded $e) {
                $manual[] = ucfirst(str_replace('_', ' ', $platform)).': '.$e->getMessage();
            }
        }
        $post->forceFill(['publish_error' => $manual ? implode(' ', $manual) : null])->save();
        $post->transitionTo(PostState::Withdrawn);
        if ($manual) {
            Slack::send("{$post->client->name}: delete by hand, {$post->name}\n".implode(' ', $manual)."\n".route('posts.edit', $post));
        }
    }

    /** Drag-and-drop on the calendar: move to another day, keeping the time. */
    /** An internal note on the post; "@FirstName" pings that colleague on Slack with a link. */
    public function note(Request $request, Post $post): RedirectResponse
    {
        $note = $post->notes()->create($request->validate(['body' => ['required', 'string', 'max:2000']]) + ['user_id' => $request->user()->id]);
        if ($names = array_map(fn ($u) => strtok($u->name, ' '), $note->mentioned())) {
            Slack::send("{$request->user()->name} to ".implode(', ', $names)." about {$post->name} ({$post->client->name}): {$note->body}\n".route('posts.edit', $post).'#notes');
        }

        return redirect()->route('posts.edit', $post)->with('status', 'note-added');
    }

    public function reschedule(Request $request, Post $post): RedirectResponse
    {
        abort_if($post->state === PostState::Published, 422, 'Published posts cannot be moved.');
        $this->move($post, $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date']);

        return back();
    }

    /** Same time of day on the new date; an approved post becomes scheduled. */
    private function move(Post $post, string $date): void
    {
        $time = $post->scheduled_at?->format('H:i') ?? '10:00';
        $post->forceFill(['scheduled_at' => "$date $time"])->save();

        if ($post->state === PostState::Approved) {
            $post->transitionTo(PostState::Scheduled);
        }
    }

    /** The list view's tick boxes: send for approval (one review per client), move to a date, or delete. Published posts are left alone and counted. */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['review', 'move', 'delete'])],
            'date' => ['required_if:action,move', 'nullable', 'date_format:Y-m-d'],
            'posts' => ['required', 'array', 'min:1'],
            'posts.*' => [Rule::exists('posts', 'id')->whereIn('client_id', $request->user()->clients()->pluck('id'))->withoutTrashed()],
        ]);
        $posts = Post::with('client')->whereIn('id', $data['posts'])->get();

        if ($data['action'] === 'review') {
            [$sendable, $skipped] = $posts->partition(fn (Post $p) => in_array($p->state, ReviewController::SENDABLE, true));
            $reviews = $sendable->groupBy('client_id')->map(fn ($group) => ReviewController::send($group->first()->client, $group->pluck('id')->all(), $request->user()->id));
            $done = __(':n sent for approval', ['n' => $sendable->count()]);
            session()->flash('warning', ReviewController::mailWarning($reviews));
        } else {
            [$skipped, $todo] = $posts->partition(fn (Post $p) => $p->state === PostState::Published);
            $todo->each(fn (Post $p) => $data['action'] === 'move' ? $this->move($p, $data['date']) : $p->moveToBin($request->user()->id));
            $done = __($data['action'] === 'move' ? ':n moved' : ':n moved to the recycle bin', ['n' => $todo->count()]);
        }

        return back()->with('status', $done.($skipped->isNotEmpty() ? ', '.__(':n left as they are (published or already in review)', ['n' => $skipped->count()]) : ''));
    }

    /** A Draft copy for this or another of the login's clients, optionally on a date; the media follows only within the same client. */
    public function duplicate(Request $request, Post $post): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['required', Rule::in($request->user()->clients()->pluck('id'))], 'date' => ['nullable', 'date']]);
        $client = Client::findOrFail($data['client_id']);
        $copy = $post->duplicate($client, ($data['date'] ?? null) ? Carbon::parse($data['date'])->setTimeFrom($post->scheduled_at ?? now()->setTime(10, 0)) : null);

        return redirect()->route('posts.edit', $copy)->with('status', $client->is($post->client) ? 'duplicated-as-a-draft' : 'duplicated-as-a-draft-without-the-media-which-stays-with-its-client');
    }

    /** Delete = into the client's recycle bin (RecycleBinController restores it or deletes it for good). */
    public function destroy(Request $request, Post $post): RedirectResponse
    {
        abort_if($post->state === PostState::Published, 422, 'Published posts cannot be deleted.');
        $post->moveToBin($request->user()->id);

        return redirect()->route('calendar', ['client' => $post->client->slug])->with('status', 'post-moved-to-the-recycle-bin');
    }

    /** ZIP with the media files and a captions.txt, for posting by hand or designing in Canva while no account is connected. */
    public function export(Post $post): BinaryFileResponse
    {
        $zip = new ZipArchive;
        $zip->open($tmp = tempnam(sys_get_temp_dir(), 'post'), ZipArchive::OVERWRITE);
        $missing = [];
        foreach ($post->assets as $asset) {
            $disk = Storage::disk($asset->disk);
            if (! $disk->exists($asset->path)) { // a file gone from the disk is named in captions.txt; the rest still exports
                $missing[] = $asset->name;

                continue;
            }
            // a local file goes in from the disk (videos are big), any other disk (S3) through its contents
            is_file($local = $disk->path($asset->path)) ? $zip->addFile($local, $asset->name) : $zip->addFromString($asset->name, (string) $disk->get($asset->path));
        }
        foreach (array_keys(array_filter($post->settings['design'] ?? [])) as $lang) {
            try {
                $zip->addFromString("{$post->name}_".strtoupper($lang).'.jpg', $this->overlay($post, $lang));
            } catch (\RuntimeException) {
                // no image asset, or one GD cannot read: the captions still export
            }
        }
        $text = "{$post->name}\nScheduled: ".($post->scheduled_at?->format('d-m-Y H:i') ?? '-')."\nState: {$post->state->label()}\nBrief: {$post->brief}\nMedia: {$post->assets->pluck('name')->join(', ')}\n";
        if ($missing) {
            $text .= 'Missing, not in this ZIP (the file is gone from the server, upload it again): '.implode(', ', $missing)."\n";
        }
        foreach (array_filter($post->settings['design'] ?? []) as $lang => $design) {
            $text .= "\n== Design text ".strtoupper($lang)." ==\n{$design}\n";
        }
        // per platform the text to paste as the Post today page copies it: every language, the signature, the hashtags, links tagged
        foreach ($post->platforms as $platform) {
            $text .= "\n== ".ucfirst(str_replace('_', ' ', $platform))." ==\n{$post->pasteText($platform)}\n";
            if ($alt = $post->altTextFor($platform)) {
                $text .= "\nAlt text: {$alt}\n";
            }
            if (in_array($platform, ['instagram', 'facebook'], true) && ($first = $post->firstCommentFor($platform)) !== '') {
                $text .= "\nFirst comment:\n{$first}\n";
            }
        }
        $zip->addFromString('captions.txt', $text);
        $zip->close();

        return response()->download($tmp, "{$post->name}.zip")->deleteFileAfterSend();
    }

    /** The first image with the design text of one language burned in, to post by hand. */
    public function image(Post $post, string $lang): Response
    {
        try {
            $jpeg = $this->overlay($post, $lang);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Content-Disposition' => 'attachment; filename="'.$post->name.'_'.strtoupper($lang).'.jpg"']);
    }

    /** @throws \RuntimeException when the post has no design text in that language or no readable image */
    private function overlay(Post $post, string $lang): string
    {
        $text = $post->settings['design'][$lang] ?? throw new \RuntimeException("No design text in {$lang}");
        $image = $post->assets->firstWhere('kind', 'image') ?? throw new \RuntimeException('No image on this post');
        $bytes = Storage::disk($image->disk)->get($image->path) ?? throw new \RuntimeException('The image file is missing');

        return TextOverlay::render($bytes, $text);
    }

    /** @return array<int, array{position: int}> */
    private function assetPositions(array $assetIds): array
    {
        $sync = [];
        foreach (array_values($assetIds) as $i => $id) {
            $sync[(int) $id] = ['position' => $i];
        }

        return $sync;
    }
}
