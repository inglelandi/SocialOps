<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Post;
use App\Planning\Holidays;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public const LINK_DAYS = 30;

    /** What a client is told about a post's state on their view-only calendar. */
    public const CLIENT_STATES = ['idea' => 'Planned', 'draft' => 'Planned', 'in_review' => 'Waiting for your approval', 'changes_requested' => 'Being reworked', 'approved' => 'Scheduled', 'scheduled' => 'Scheduled', 'published' => 'Published', 'failed' => 'Delayed', 'manual_needed' => 'Delayed', 'withdrawn' => 'Withdrawn'];

    public function index(Request $request): View
    {
        [$view, $date, $start, $end, $range] = $this->range($request);
        $clientSlug = $request->input('client');
        $clients = $request->user()->clients()->get();
        $client = is_string($clientSlug) ? $clients->firstWhere('slug', $clientSlug) : null;
        abort_if(filled($clientSlug) && ! $client, 404); // an old or mistyped address, or a client this login does not see
        $filters = $request->only(['state', 'platform', 'pillar']);

        $posts = $request->user()->restrict(Post::with('client', 'assets'))
            ->when($clientSlug, fn ($q) => $q->whereRelation('client', 'slug', $clientSlug))
            ->filter($filters)
            ->whereBetween('scheduled_at', [$start, $end])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (Post $p) => $p->scheduled_at->toDateString());

        $unscheduled = $request->user()->restrict(Post::with('client', 'assets'))
            ->when($clientSlug, fn ($q) => $q->whereRelation('client', 'slug', $clientSlug))
            ->filter($filters)
            ->whereNull('scheduled_at')
            ->latest()
            ->get();

        $marks = array_map(fn (string $label) => explode(' · ', $label), Holidays::between($start, $end, Holidays::countries($client?->toProfileArray() ?? [])));
        foreach ($client ? [$client] : $clients as $owner) {
            foreach ($owner->profile['key_dates'] ?? [] as $keyDate) {
                $day = Carbon::parse($keyDate['date'])->toDateString();
                if ($day >= $start->toDateString() && $day <= $end->toDateString()) {
                    $marks[$day][] = ($client ? '' : $owner->code.': ').$keyDate['label'];
                }
            }
        }

        return view('calendar.index', [
            'filters' => $filters,
            'pillars' => ($client ? collect([$client]) : $clients)->flatMap(fn ($c) => array_keys($c->profile['pillars'] ?? []))->unique()->sort()->values(),
            'marks' => $marks,
            'view' => $view,
            'date' => $date,
            'range' => $range,
            'days' => collect(Carbon::parse($start)->toPeriod($end, '1 day')),
            'grids' => $this->grids($range, $start, $end),
            'posts' => $posts,
            'unscheduled' => $unscheduled,
            'clients' => $clients,
            'clientSlug' => $clientSlug,
            'client' => $client,
            'clientUrl' => $client ? URL::temporarySignedRoute('calendar.client', now()->addDays(self::LINK_DAYS), ['client' => $client->slug]) : null,
        ]);
    }

    /** The client's own posts behind a signed link, this month and the next, read only. */
    public function client(Client $client): View
    {
        $posts = $client->posts()->with('variants')->whereBetween('scheduled_at', [now()->startOfMonth(), now()->addMonthNoOverflow()->endOfMonth()])->orderBy('scheduled_at')->get();

        return view('calendar.client', ['client' => $client, 'days' => $posts->groupBy(fn (Post $p) => $p->scheduled_at->toDateString()), 'lang' => $client->profile['languages'][0]]);
    }

    /** The posts the calendar shows for one client, one row per post and platform, as a UTF-8 CSV for Canva Bulk Create or a spreadsheet. */
    public function csv(Request $request, Client $client): Response
    {
        [, $date, $start, $end] = $this->range($request);
        $langs = array_merge($client->profile['languages'], $client->profile['extra_languages'] ?? []);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_merge(['date', 'time', 'post', 'state', 'platform', 'format', 'pillar'], array_map(fn ($l) => "caption_$l", $langs), ['hashtags', 'alt_text'], array_map(fn ($l) => "design_$l", $langs), ['first_comment', 'media']));
        $posts = $client->posts()->with('variants', 'assets')->whereBetween('scheduled_at', [$start, $end])->orderBy('scheduled_at')->get();
        foreach ($posts as $post) {
            foreach ($post->platforms as $platform) {
                $variants = $post->variants->where('platform', $platform)->keyBy('lang');
                fputcsv($out, array_merge(
                    [$post->scheduled_at->toDateString(), $post->scheduled_at->format('H:i'), $post->name, $post->state->label(), $platform, $post->format, $post->pillar],
                    // placeholders filled and links tagged as in Post::pasteText(), the text the Post today page copies
                    array_map(fn ($l) => $client->tagLinks($client->substitute((string) $variants->get($l)?->caption), $platform, $post->name), $langs),
                    [$variants->flatMap(fn ($v) => $v->hashtags ?? [])->map(fn ($t) => '#'.ltrim($t, '#'))->unique()->join(' '), (string) $post->altTextFor($platform)],
                    array_map(fn ($l) => (string) ($post->settings['design'][$l] ?? ''), $langs),
                    [$post->firstCommentFor($platform), $post->assets->pluck('name')->join(', ')],
                ));
            }
        }
        rewind($out);

        // The BOM makes Excel read the Greek as UTF-8.
        return response("\xEF\xBB\xBF".stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$client->code.'_'.$date->format('Y-m').'_posts.csv"',
        ]);
    }

    /**
     * The grids the page draws: one per month of a range, each titled and padded to whole weeks, so a long range reads as months and not as one tall table; else the single month or week.
     *
     * @return list<array{title: ?string, month: ?int, days: Collection}>
     */
    private function grids(?array $range, Carbon $start, Carbon $end): array
    {
        if (! $range) {
            return [['title' => null, 'month' => null, 'days' => collect($start->toPeriod($end, '1 day'))]];
        }
        $grids = [];
        for ($month = $range[0]->copy()->startOfMonth(); $month <= $range[1]; $month = $month->copy()->addMonth()) {
            // max() and min() hand back one of the two instances, so copy before moving to the week's edges
            $first = $month->max($range[0])->copy()->startOfWeek();
            $last = $month->copy()->endOfMonth()->min($range[1])->copy()->endOfWeek();
            $grids[] = ['title' => $month->translatedFormat('F Y'), 'month' => $month->month, 'days' => collect($first->toPeriod($last, '1 day'))];
        }

        return $grids;
    }

    /** A date from the address (YYYY-MM-DD, or anything Carbon reads), null when it is missing or not a date. */
    public static function day(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /** @return array{string, Carbon, Carbon, Carbon, ?array{Carbon, Carbon}} view, the date shown, first and last day shown, the from–to range when one was asked for */
    private function range(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $view = in_array($request->input('view'), ['week', 'list']) ? $request->input('view') : 'month';
        if ($request->filled(['from', 'to'])) {
            $from = $request->date('from')->startOfDay();
            $to = $request->date('to')->startOfDay()->min($from->copy()->addYear()); // shortcut: a longer range is cut to one year, enough for a season and already a long page

            // the grid shows whole weeks around the range, the list exactly its days
            return $view === 'list' ? [$view, $from, $from, $to->copy()->endOfDay(), [$from, $to]] : [$view, $from, $from->copy()->startOfWeek(), $to->copy()->endOfWeek(), [$from, $to]];
        }
        $date = self::day($request->input('date')) ?? today(); // a date the address mangled is ignored, not an error page

        return match ($view) {
            'week' => [$view, $date, $date->copy()->startOfWeek(), $date->copy()->endOfWeek(), null],
            'list' => [$view, $date, $date->copy()->startOfMonth(), $date->copy()->endOfMonth(), null],
            default => [$view, $date, $date->copy()->startOfMonth()->startOfWeek(), $date->copy()->endOfMonth()->endOfWeek(), null],
        };
    }
}
