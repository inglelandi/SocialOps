<?php

namespace App\Mcp;

use App\Mcp\Tools\CreateDraft;
use App\Mcp\Tools\FindProducts;
use App\Mcp\Tools\GetCaptionBrief;
use App\Mcp\Tools\GetDesign;
use App\Mcp\Tools\GetPlanningBrief;
use App\Mcp\Tools\GetPost;
use App\Mcp\Tools\ListClaudeJobs;
use App\Mcp\Tools\ListClients;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\ListPosts;
use App\Mcp\Tools\MonthNumbers;
use App\Mcp\Tools\ReplyToComment;
use App\Mcp\Tools\SaveDesignDescription;
use App\Mcp\Tools\SavePlan;
use App\Mcp\Tools\UpdatePost;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/** The MCP server (docs/api.md): the same reads and writes as the JSON API plus the planning tools, for Claude Code, Claude Desktop and the Claude.ai connector. Never publishes. */
#[Name('Social Ops')]
#[Version('1.0.0')]
#[Instructions(<<<'MD'
Social Ops organizes the social media work of an agency's clients (hospitality and local brands in Crete and Cyprus, Greek and English): the team plans, briefs, approves and tracks every post here and posts it in the apps by hand.
Start with list-clients, then use the client's id or slug in the other tools. Captions go per platform and language under variants.
To plan a month: get-planning-brief (the profile, the quota, the window, the recent posts), research the current trends yourself (the last30days skill or web search), plan the posts and write their captions (when the client has a shop, link real products found with find-products), then save-plan files them as Drafts with a review sheet.
To write, shorten or change the captions of one post: get-caption-brief (the voice, the limits, the current captions), write them, then update-post.
To describe a design from a client's Drive folder (a post it published before, as a graphic, photo, PDF or video): get-design (Drive's preview and where the file sits), look at it, then save-design-description with what is visible, never invented; each design is described once.
Work the team queued in the app (the scheduled worker's job): list-claude-jobs, then the three recipes above; each job says how to clear it.
A Draft still goes through human review and approval in the app; nothing here publishes. Greek captions are reviewed by a person.
MD)]
class SocialOpsServer extends Server
{
    protected array $tools = [
        ListClients::class,
        ListPosts::class,
        GetPost::class,
        CreateDraft::class,
        UpdatePost::class,
        GetCaptionBrief::class,
        GetPlanningBrief::class,
        FindProducts::class,
        ListClaudeJobs::class,
        GetDesign::class,
        SaveDesignDescription::class,
        SavePlan::class,
        ListComments::class,
        ReplyToComment::class,
        MonthNumbers::class,
    ];

    /** Comments and numbers come from the platform APIs: no such tools while publishing is off (config/features.php). */
    protected function boot(): void
    {
        if (! config('features.publishing')) {
            $this->tools = array_values(array_diff($this->tools, [ListComments::class, ReplyToComment::class, MonthNumbers::class]));
        }
    }
}
