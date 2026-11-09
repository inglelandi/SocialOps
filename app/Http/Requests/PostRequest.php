<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Post;
use App\Posts\PostState;
use App\Publishing\TikTokPublisher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PostRequest extends FormRequest
{
    /** Caption limits per platform, from prompts/caption-draft.md. */
    public const CAPTION_MAX = ['instagram' => 2200, 'facebook' => 63206, 'tiktok' => 2200, 'linkedin' => 3000];

    /** The other text limits of a variant and a post (alt_text is a 1000-character column). */
    public const ALT_TEXT_MAX = 1000;

    public const HASHTAG_MAX = 100;

    public const DESIGN_MAX = 5000;

    public function authorize(): bool
    {
        return $this->route('post')?->state !== PostState::Published;
    }

    public function client(): Client
    {
        return $this->route('client') ?? $this->route('post')->client;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('settings.ask_claude')) { // "1"/"0" from the form; the queue query needs a JSON boolean
            $this->merge(['settings' => ['ask_claude' => $this->boolean('settings.ask_claude')] + (array) $this->input('settings')]);
        }
    }

    public function rules(): array
    {
        $client = $this->client();

        return [
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => [Rule::in(Post::PLATFORMS)],
            'format' => ['required', Rule::in(Post::FORMATS)],
            // a pillar renamed or removed in the profile since: the post may keep the one it has, so a caption change still saves
            'pillar' => ['nullable', Rule::in(array_filter([...array_keys($client->profile['pillars']), $this->route('post')?->pillar]))],
            'brief' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date'],
            'settings' => ['sometimes', 'array'],
            'settings.tiktok.privacy_level' => ['nullable', Rule::in(TikTokPublisher::PRIVACY)],
            'settings.tiktok.allow_comment' => ['nullable', 'boolean'],
            'settings.tiktok.allow_duet' => ['nullable', 'boolean'],
            'settings.tiktok.allow_stitch' => ['nullable', 'boolean'],
            'settings.tiktok.commercial' => ['nullable', Rule::in(['none', 'your_brand', 'branded_content'])],
            'settings.tiktok.consent' => ['nullable', 'boolean'],
            'settings.design.*' => ['nullable', 'string', 'max:'.self::DESIGN_MAX],
            'settings.first_comment' => ['nullable', 'string', 'max:2200'],
            'settings.instagram.collaborators' => ['nullable', 'string', 'max:120'],
            'settings.repeat' => ['nullable', Rule::in(['weekly', 'monthly'])],
            'settings.ask_claude' => ['nullable', 'boolean'],
            'assets' => ['sometimes', 'array'],
            'assets.*' => [Rule::exists('assets', 'id')->where('client_id', $client->id)],
            'variants' => ['sometimes', 'array'],
            'variants.*.*.caption' => ['nullable', 'string'],
            'variants.*.*.alt_text' => ['nullable', 'string', 'max:'.self::ALT_TEXT_MAX],
            'variants.*.*.hashtags' => ['nullable'], // "#a #b" from the form, or a list from the API
            'variants.*.*.hashtags.*' => ['string', 'max:'.self::HASHTAG_MAX],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            // TikTok's terms: the creator agrees to the posting terms on every post our app makes
            if (config('features.publishing') && in_array('tiktok', (array) $this->input('platforms')) && ! $this->boolean('settings.tiktok.consent')) {
                $validator->errors()->add('settings.tiktok.consent', 'Confirm the TikTok posting terms for this post.');
            }
            $collaborators = Post::handles($this->input('settings.instagram.collaborators'));
            if (count($collaborators) > Post::MAX_COLLABORATORS) {
                $validator->errors()->add('settings.instagram.collaborators', 'Instagram allows '.Post::MAX_COLLABORATORS.' collaborators per post.');
            }
            if ($collaborators && $this->input('format') === 'story') {
                $validator->errors()->add('settings.instagram.collaborators', 'Instagram Stories cannot have collaborators.');
            }
            // only the ticked platforms are saved, so only they are checked: a long caption left under an unticked one must not block Save
            $ticked = (array) $this->input('platforms');
            foreach ((array) $this->input('variants') as $platform => $langs) {
                if (! in_array($platform, $ticked, true)) {
                    continue;
                }
                foreach ((array) $langs as $lang => $fields) {
                    $max = self::CAPTION_MAX[$platform] ?? 5000;
                    if (mb_strlen(self::lines((string) ($fields['caption'] ?? ''))) > $max) {
                        $validator->errors()->add("variants.$platform.$lang.caption", __(':platform captions are limited to :max characters.', ['platform' => ucfirst($platform), 'max' => $max]));
                    }
                }
            }
        }];
    }

    /**
     * Variant rows for the selected platforms and the client's languages.
     *
     * @return list<array{platform: string, lang: string, caption: ?string, alt_text: ?string, hashtags: list<string>}>
     */
    public function variantRows(): array
    {
        $rows = [];
        $languages = array_merge($this->client()->profile['languages'], $this->client()->profile['extra_languages'] ?? []);

        foreach ($this->validated('platforms') as $platform) {
            foreach ($languages as $lang) {
                $fields = $this->input("variants.$platform.$lang", []);
                $rows[] = [
                    'platform' => $platform,
                    'lang' => $lang,
                    'caption' => self::lines($fields['caption'] ?? null),
                    'alt_text' => self::lines($fields['alt_text'] ?? null),
                    'hashtags' => array_values(array_filter(is_array($fields['hashtags'] ?? null) ? $fields['hashtags'] : preg_split('/[\s,]+/', (string) ($fields['hashtags'] ?? '')))),
                ];
            }
        }

        return $rows;
    }

    /** Browsers send textarea text with CRLF line breaks, Claude and the API with LF: one form, so an unchanged caption compares equal. */
    public static function lines(?string $text): ?string
    {
        return $text === null ? null : str_replace("\r\n", "\n", $text);
    }
}
