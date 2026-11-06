<?php

namespace App\Planning;

use App\Clients\ClientProfile;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PlanRequest
{
    /** Each post costs a caption call, so a batch stays small enough to review in one sitting. */
    public const MAX_POSTS = 30;

    /**
     * @param  array  $profile  the client profile, shaped like config/clients/example.yaml (Client::toProfileArray())
     * @param  string  $instruction  what the team wants the posts to be about; it decides the topic instead of the pillar quota
     */
    public function __construct(
        public readonly array $profile,
        public readonly int $count,
        public readonly array $platforms,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $instruction = '',
    ) {
        if ($count < 1 || $count > self::MAX_POSTS) {
            throw new InvalidArgumentException('Posts per batch must be between 1 and '.self::MAX_POSTS);
        }
        if ($platforms === [] || array_diff($platforms, self::plannable($profile)) !== []) {
            throw new InvalidArgumentException('Platforms must be a non-empty subset of the client accounts: '.implode(', ', self::plannable($profile)));
        }
        if ($to->lt($from)) {
            throw new InvalidArgumentException('The publishing window ends before it starts');
        }
    }

    /** Platforms with an account in the profile, in profile order. */
    public static function accounts(array $profile): array
    {
        return array_keys(array_filter($profile['accounts'] ?? []));
    }

    /** What a plan may be for: the platforms with an account in the profile, else every platform, so a client whose handles are not in yet can still be planned. */
    public static function plannable(array $profile): array
    {
        return self::accounts($profile) ?: ClientProfile::PLATFORMS;
    }

    public function promptVars(): array
    {
        return Prompt::profileVars($this->profile) + [
            'today' => CarbonImmutable::today()->toDateString(),
            'window' => ['start' => $this->from->toDateString(), 'end' => $this->to->toDateString()],
            'platforms' => $this->platforms,
            'count' => $this->count,
            'instruction' => $this->instruction,
            'plan_language' => ($this->profile['languages'][0] ?? null) === 'en' ? 'English' : 'Greek', // what the team and the client read the plan in; captions keep their own languages
            'holidays' => Holidays::lines($this->from, $this->to, Holidays::countries($this->profile)),
        ];
    }
}
