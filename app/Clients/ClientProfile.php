<?php

namespace App\Clients;

use App\Models\Client;
use App\Webhooks\Webhooks;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Parses and validates a brand profile written in the config/clients/example.yaml format. */
final class ClientProfile
{
    public const SECTIONS = ['accounts', 'languages', 'extra_languages', 'about', 'voice', 'notes', 'pillars', 'hashtags', 'research', 'approval', 'slots', 'saved_replies', 'signatures', 'fields', 'utm', 'webhooks', 'key_dates'];

    public const PLATFORMS = ['instagram', 'facebook', 'tiktok', 'linkedin'];

    /** What Claude plans and writes from: the free-text fields of the About section, each a `{{about.<key>}}` in the prompts. */
    public const ABOUT = ['description', 'audience', 'offerings', 'differentiators', 'goals', 'current'];

    /** The longest pillar key: posts.pillar and assets.pillar are 40-character columns. */
    public const PILLAR_MAX = 40;

    /**
     * The nested sections (everything but slug, code, name, type, location), validated.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function parse(string $yaml): array
    {
        $data = Arr::only(self::parseYaml($yaml), self::SECTIONS) + ['extra_languages' => [], 'about' => [], 'notes' => '', 'slots' => [], 'saved_replies' => [], 'signatures' => [], 'fields' => [], 'utm' => false, 'webhooks' => [], 'key_dates' => [], 'research' => ['sources' => [], 'excluded_domains' => [], 'excluded_topics' => []]];

        $data['accounts'] = self::handles((array) ($data['accounts'] ?? []));

        $validator = Validator::make($data, self::rules());

        $validator->after(function ($v) use ($data) {
            if (is_array($data['pillars'] ?? null) && $data['pillars'] !== [] && array_sum($data['pillars']) !== 100) { // no pillars is fine: Claude then picks the topics
                $v->errors()->add('pillars', __('Pillar shares must add up to 100.'));
            }
            foreach (array_keys(is_array($data['pillars'] ?? null) ? $data['pillars'] : []) as $key) {
                if (mb_strlen((string) $key) > self::PILLAR_MAX) { // posts and assets keep the pillar in a 40-character column
                    $v->errors()->add('pillars', __('Pillar names are at most :max characters: ":name" is longer.', ['max' => self::PILLAR_MAX, 'name' => str_replace('_', ' ', (string) $key)]));
                }
            }
            foreach (array_keys(is_array($data['fields'] ?? null) ? $data['fields'] : []) as $key) {
                if (! preg_match('/^\p{Ll}[\p{L}\p{N}_]*$/u', (string) $key)) { // Greek or Latin, as long as it is lowercase
                    $v->errors()->add('fields', __("Field names are lowercase letters (Greek or Latin), digits and underscores: ':key' is not.", ['key' => $key]));
                }
            }
            if (($data['approval']['auto_approve_on_timeout'] ?? false) && ($data['approval']['policy'] ?? null) !== 'optional') {
                $v->errors()->add('approval.auto_approve_on_timeout', __('Auto-approve on timeout is only allowed when the policy is optional.'));
            }
        });

        // keep the sections in the template's order, whatever the validator returns
        return array_replace(array_fill_keys(self::SECTIONS, null), $validator->validate());
    }

    /**
     * The profile sections built from the client form (the `p[...]` inputs): lists are comma, space or line separated text, a row without its key is dropped.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function fromForm(array $form): array
    {
        $text = fn ($s) => trim((string) $s);
        $list = fn ($s) => array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $s))));
        $lines = fn ($s) => array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $s) ?: []), 'strlen')); // without /u, \R splits inside a Greek υ (its byte 0x85)
        $tags = fn ($s) => array_map(fn ($h) => '#'.ltrim($h, '#'), $list($s));
        $rows = fn ($rows, $keep) => array_values(array_filter(array_map(fn ($r) => array_map(fn ($v) => is_array($v) ? $v : trim((string) $v), (array) $r), (array) $rows), $keep));

        return [
            'accounts' => self::handles((array) ($form['accounts'] ?? [])),
            'languages' => $list($form['languages'] ?? ''),
            'extra_languages' => $list($form['extra_languages'] ?? ''),
            'about' => array_map($text, array_replace(array_fill_keys(self::ABOUT, ''), Arr::only((array) ($form['about'] ?? []), self::ABOUT))),
            'voice' => [
                'tone' => $text($form['voice']['tone'] ?? ''),
                'person' => $text($form['voice']['person'] ?? ''),
                'emoji' => $text($form['voice']['emoji'] ?? ''),
                'cta_style' => $text($form['voice']['cta_style'] ?? ''),
                'banned_phrases' => $lines($form['voice']['banned_phrases'] ?? ''),
                'words' => $lines($form['voice']['words'] ?? ''),
                'facts' => $lines($form['voice']['facts'] ?? ''),
            ],
            'notes' => $text($form['notes'] ?? ''),
            'pillars' => collect($rows($form['pillars'] ?? [], fn ($r) => ($r['name'] ?? '') !== ''))->mapWithKeys(fn ($r) => [self::key($r['name']) => (int) ($r['share'] ?? 0)])->all(),
            'hashtags' => [
                'always' => $tags($form['hashtags']['always'] ?? ''),
                'pool' => $tags($form['hashtags']['pool'] ?? ''),
                'max_per_post' => (int) ($form['hashtags']['max_per_post'] ?? 0),
            ],
            'research' => [
                'sources' => $lines($form['research']['sources'] ?? ''),
                'excluded_domains' => $lines($form['research']['excluded_domains'] ?? ''),
                'excluded_topics' => $lines($form['research']['excluded_topics'] ?? ''),
                'hashtags_to_watch' => array_map(fn ($h) => ltrim($h, '#'), $list($form['research']['hashtags_to_watch'] ?? '')),
                'competitors' => $list($form['research']['competitors'] ?? ''),
            ],
            'approval' => [
                'policy' => $form['approval']['policy'] ?? null,
                'sla_hours' => (int) ($form['approval']['sla_hours'] ?? 0),
                'auto_approve_on_timeout' => (bool) ($form['approval']['auto_approve_on_timeout'] ?? false),
                'approvers' => $rows($form['approval']['approvers'] ?? [], fn ($r) => ($r['name'] ?? '') !== '' || ($r['email'] ?? '') !== ''),
                'email_monthly_report' => (bool) ($form['approval']['email_monthly_report'] ?? false),
            ],
            'slots' => array_map(fn ($r) => $r['day'].' '.$r['time'], $rows($form['slots'] ?? [], fn ($r) => ($r['day'] ?? '') !== '' && ($r['time'] ?? '') !== '')),
            'saved_replies' => array_map(fn ($r) => ['label' => $r['label'], 'text' => $r['text'], 'keywords' => $list($r['keywords'] ?? '')], $rows($form['saved_replies'] ?? [], fn ($r) => ($r['label'] ?? '') !== '' && ($r['text'] ?? '') !== '')),
            'signatures' => array_filter(array_map('trim', (array) ($form['signatures'] ?? []))),
            'fields' => collect($rows($form['fields'] ?? [], fn ($r) => ($r['key'] ?? '') !== ''))->mapWithKeys(fn ($r) => [$r['key'] => $r['value'] ?? ''])->all(),
            'utm' => (bool) ($form['utm'] ?? false),
            'webhooks' => array_map(fn ($r) => ['url' => $r['url'], 'secret' => $r['secret'] ?? '', 'events' => array_values((array) ($r['events'] ?? []))], $rows($form['webhooks'] ?? [], fn ($r) => ($r['url'] ?? '') !== '')),
            'key_dates' => $rows($form['key_dates'] ?? [], fn ($r) => ($r['date'] ?? '') !== '' || ($r['label'] ?? '') !== ''),
        ];
    }

    /**
     * The account handles per platform as lists: a client can have two Instagrams; a profile from before holds one string per platform.
     *
     * @return array<string, list<string>>
     */
    public static function handles(array $accounts): array
    {
        return array_map(fn ($v) => array_values(array_filter(array_map(fn ($h) => trim((string) $h), (array) $v), 'strlen')), array_replace(array_fill_keys(self::PLATFORMS, []), Arr::only($accounts, self::PLATFORMS)));
    }

    /** A pillar name as its key: lowercase, words joined with underscores, Greek letters kept (the file names transliterate them). */
    public static function key(string $name): string
    {
        return trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '_', $name)), '_');
    }

    /**
     * The sections a new client's form starts from: the template's defaults, with the fictional example's own values blanked.
     *
     * @return array<string, mixed>
     */
    public static function template(): array
    {
        $t = self::parse(file_get_contents(base_path('config/clients/example.yaml')));

        return array_replace($t, [
            'accounts' => [],
            'about' => [],
            'voice' => ['tone' => '', 'cta_style' => '', 'facts' => []] + $t['voice'],
            'hashtags' => ['always' => [], 'pool' => []] + $t['hashtags'],
            'approval' => ['approvers' => []] + $t['approval'],
            'key_dates' => [],
        ]);
    }

    /** The template sections from config/clients/example.yaml, as YAML for a new client form. */
    public static function templateYaml(): string
    {
        $sections = Arr::only(self::parseYaml(file_get_contents(base_path('config/clients/example.yaml'))), self::SECTIONS);

        return Yaml::dump($sections, 4);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function parseYaml(string $yaml): array
    {
        try {
            // unquoted dates like 2027-05-01 would otherwise become Unix timestamps
            $data = Yaml::parse($yaml, Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            throw ValidationException::withMessages(['profile' => 'Not valid YAML: '.$e->getMessage()]);
        }

        if (! is_array($data)) {
            throw ValidationException::withMessages(['profile' => 'The profile must be a YAML mapping.']);
        }

        foreach ($data['key_dates'] ?? [] as $i => $entry) {
            if (($entry['date'] ?? null) instanceof \DateTimeInterface) {
                $data['key_dates'][$i]['date'] = $entry['date']->format('Y-m-d');
            }
        }

        return $data;
    }

    /** @return array<string, array<int, mixed>> */
    public static function scalarRules(?int $ignoreId = null): array
    {
        return [
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('clients', 'slug')->ignore($ignoreId)],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}$/', Rule::unique('clients', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'max:60'], // free text; Client::TYPES are only the suggestions
            'location' => ['required', 'string', 'max:120'],
            // the agency's board fields (Agency tab of the form), all optional
            'status' => ['sometimes', 'required', Rule::in(array_keys(Client::STATUSES))],
            'owner' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', Rule::in(array_keys(Client::PRIORITIES))],
            'plan_due' => ['nullable', 'date'],
            'posts_per_week' => ['nullable', 'integer', 'min:0', 'max:31'],
            'stories_per_week' => ['nullable', 'integer', 'min:0', 'max:31'],
            'tiktoks_per_month' => ['nullable', 'integer', 'min:0', 'max:31'],
            'pending' => ['nullable', 'string', 'max:2000'],
            'links' => ['nullable', 'array', 'max:10'],
            'links.*.label' => ['nullable', 'string', 'max:40'],
            'links.*.url' => ['required', 'url:https'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'accounts' => ['required', 'array'],
            'accounts.*' => ['present', 'array', 'max:10'],
            'accounts.*.*' => ['string', 'max:120'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => ['string', 'size:2'],
            'extra_languages' => ['present', 'array'],
            'extra_languages.*' => ['string', 'size:2'],
            'about' => ['present', 'array'],
            'about.*' => ['nullable', 'string', 'max:3000'],
            'voice' => ['required', 'array'],
            'voice.tone' => ['required', 'string'],
            'voice.person' => ['required', 'string'],
            'voice.emoji' => ['required', 'string'],
            'voice.cta_style' => ['required', 'string'],
            'voice.banned_phrases' => ['present', 'array'],
            'voice.banned_phrases.*' => ['string'],
            'voice.words' => ['nullable', 'array'],
            'voice.words.*' => ['string'],
            'voice.facts' => ['present', 'array'],
            'voice.facts.*' => ['string'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'pillars' => ['present', 'array'],
            'pillars.*' => ['integer', 'min:0', 'max:100'],
            'hashtags' => ['required', 'array'],
            'hashtags.always' => ['present', 'array'],
            'hashtags.always.*' => ['string', 'starts_with:#'],
            'hashtags.pool' => ['present', 'array'],
            'hashtags.pool.*' => ['string', 'starts_with:#'],
            'hashtags.max_per_post' => ['required', 'integer', 'min:0', 'max:30'],
            'research' => ['present', 'array'],
            'research.sources' => ['present', 'array'],
            'research.sources.*' => ['string'],
            'research.excluded_domains' => ['present', 'array'],
            'research.excluded_domains.*' => ['string'],
            'research.excluded_topics' => ['present', 'array'],
            'research.excluded_topics.*' => ['string'],
            'research.hashtags_to_watch' => ['nullable', 'array', 'max:30'],
            'research.hashtags_to_watch.*' => ['string'],
            'research.competitors' => ['nullable', 'array', 'max:20'],
            'research.competitors.*' => ['string'],
            'approval' => ['required', 'array'],
            'approval.policy' => ['required', Rule::in(['none', 'optional', 'required', 'multi_level'])],
            'approval.sla_hours' => ['required', 'integer', 'min:1'],
            'approval.auto_approve_on_timeout' => ['required', 'boolean'],
            'approval.approvers' => ['present', 'array'],
            'approval.approvers.*.name' => ['required', 'string'],
            'approval.approvers.*.email' => ['required', 'email'],
            'approval.approvers.*.phone' => ['nullable', 'string'],
            'approval.email_monthly_report' => ['nullable', 'boolean'],
            'slots' => ['present', 'array', 'max:21'],
            'slots.*' => ['string', 'regex:/^(mon|tue|wed|thu|fri|sat|sun) ([01]\d|2[0-3]):[0-5]\d$/i'],
            'saved_replies' => ['present', 'array', 'max:30'],
            'saved_replies.*.label' => ['required', 'string', 'max:40'],
            'saved_replies.*.text' => ['required', 'string', 'max:2200'],
            'saved_replies.*.keywords' => ['nullable', 'array', 'max:20'],
            'saved_replies.*.keywords.*' => ['string', 'max:40'],
            'signatures' => ['present', 'array:'.implode(',', self::PLATFORMS)],
            'signatures.*' => ['nullable', 'string', 'max:300'],
            'fields' => ['present', 'array', 'max:50'],
            'fields.*' => ['nullable', 'string', 'max:300'],
            'utm' => ['present', 'boolean'],
            'webhooks' => ['present', 'array', 'max:10'],
            'webhooks.*.url' => ['required', 'url', 'starts_with:https://'],
            'webhooks.*.secret' => ['nullable', 'string', 'max:200'],
            'webhooks.*.events' => ['nullable', 'array'],
            'webhooks.*.events.*' => [Rule::in(Webhooks::EVENTS)],
            'key_dates' => ['present', 'array'],
            'key_dates.*.date' => ['required', 'date'],
            'key_dates.*.label' => ['required', 'string'],
        ];
    }
}
