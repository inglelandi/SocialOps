<?php

namespace App\Planning;

use Anthropic\Client;
use App\Drafting\Reply;
use App\Models\ClaudeCall;
use App\Models\Post;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Turns the brand profile, the trend brief and the pillar quota into one brief per post (prompts/content-plan.md). */
class ContentPlanner
{
    public const FORMATS = Post::FORMATS;

    public function __construct(private Client $claude) {}

    /**
     * @param  list<string>  $recent  titles or captions of recent posts, so angles are not repeated
     * @return array{posts: list<array>, warnings: list<string>}
     */
    public function plan(PlanRequest $request, string $trendBrief, array $recent = []): array
    {
        // an instruction from the team decides the topic, so the pillar quota does not apply
        $quota = $request->instruction === '' && $request->profile['pillars'] ? PillarQuota::split($request->profile['pillars'], $request->count) : [];

        $message = $this->claude->messages->create(
            model: config('services.anthropic.model'),
            maxTokens: 16000,
            messages: [['role' => 'user', 'content' => Prompt::render('content-plan', $request->promptVars() + [
                'quota' => PillarQuota::line($quota, $request->instruction),
                'trend_brief' => $trendBrief,
                'recent' => $recent,
            ])]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::schema($request->platforms, $quota ? array_keys($quota) : array_keys($request->profile['pillars']))]],
        );

        ClaudeCall::record($message, 'plan', $request->profile['slug']);
        $posts = Reply::json($message)['posts'];

        return ['posts' => $posts, 'warnings' => self::check($posts, $request, $quota)];
    }

    /**
     * The schema pins platforms, formats and pillars; count, dates and the quota are checked here.
     * Wrong count or a post without a platform is a hard failure; drift in dates or quota is a warning for the reviewer.
     *
     * @return list<string> warnings
     */
    public static function check(array $posts, PlanRequest $request, array $quota): array
    {
        if (count($posts) !== $request->count) {
            throw new RuntimeException('Claude planned '.count($posts)." posts, asked for {$request->count}");
        }

        $warnings = [];
        foreach ($posts as $i => $post) {
            $n = $i + 1;
            if ($post['platforms'] === []) {
                throw new RuntimeException("Post {$n} has no platform");
            }
            $date = CarbonImmutable::parse($post['date']);
            if ($date->lt($request->from) || $date->gt($request->to)) {
                $warnings[] = "Post {$n} is dated {$post['date']}, outside the window";
            }
        }

        $actual = array_count_values(array_filter(array_column($posts, 'pillar'), 'is_string')); // a post without a pillar is null
        foreach ($quota as $pillar => $n) {
            if (($actual[$pillar] ?? 0) !== $n) {
                $warnings[] = "Pillar {$pillar}: planned ".($actual[$pillar] ?? 0).", quota {$n}";
            }
        }

        return $warnings;
    }

    private static function schema(array $platforms, array $pillars): array
    {
        $string = ['type' => 'string'];
        $keys = ['date', 'platforms', 'format', 'pillar', 'title', 'angle', 'trend_signal', 'why_now', 'asset_brief', 'caption_brief'];

        return [
            'type' => 'object',
            'properties' => ['posts' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'date' => $string,
                    'platforms' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $platforms]],
                    'format' => ['type' => 'string', 'enum' => self::FORMATS],
                    'pillar' => $pillars ? ['type' => 'string', 'enum' => $pillars] : ['type' => 'null'], // no pillars in the profile: an empty enum no post could meet
                    'title' => $string,
                    'angle' => $string,
                    'trend_signal' => ['type' => ['string', 'null']],
                    'why_now' => $string,
                    'asset_brief' => $string,
                    'caption_brief' => $string,
                ],
                'required' => $keys,
                'additionalProperties' => false,
            ]]],
            'required' => ['posts'],
            'additionalProperties' => false,
        ];
    }
}
