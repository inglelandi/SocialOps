<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class InboxUploadRequest extends FormRequest
{
    public const MIMES = 'image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime';

    /** 200 MB a file, in the kilobytes of the `max` rule. */
    public const MAX_KB = 204800;

    public const MAX_FILES = 20;

    /** The rules one file must pass, here and in the staff upload on the assets page. */
    public static function fileRules(): array
    {
        return ['file', 'mimetypes:'.self::MIMES, 'max:'.self::MAX_KB];
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => self::fileRules(),
            'pillar' => ['nullable', Rule::in(array_keys($this->route('client')->profile['pillars']))],
            'season' => ['nullable', 'string', 'max:30'],
            'people_consent' => ['sometimes', 'boolean'],
            'ugc_permission' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** The client reads these in their own language: no field names such as "files.0" in them. */
    public function messages(): array
    {
        return [
            'files.required' => __('Please choose at least one photo or video.'),
            'files.max' => __('Up to :max files at a time, please send the rest separately.'),
            'files.*.uploaded' => self::uploadMessage(),
            'files.*.mimetypes' => self::typeMessage(),
            'files.*.max' => self::sizeMessage(),
        ];
    }

    public function attributes(): array
    {
        return ['pillar' => __('topic'), 'season' => __('season or event field'), 'note' => __('note')];
    }

    /** A file the server refused before it arrived whole (bigger than PHP's upload_max_filesize, or cut off). */
    public static function uploadMessage(): string
    {
        return __('A file did not arrive whole. It may be bigger than the server takes (:mb MB a file): try it on its own, or send a smaller version.', ['mb' => (int) floor(UploadedFile::getMaxFilesize() / 1048576)]);
    }

    public static function typeMessage(): string
    {
        return __('Only JPG, PNG, WEBP, HEIC photos and MP4 or MOV videos are accepted.');
    }

    public static function sizeMessage(): string
    {
        return __('Too big: :mb MB at most.', ['mb' => self::MAX_KB / 1024]);
    }
}
