<?php

namespace App\Media;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Cuts venue clips into one vertical Reel with ffmpeg: each clip trimmed, scaled and cropped to 9:16, concatenated, an optional headline over the first seconds, no sound (music is added in the app). */
final class ReelAssembler
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public const MAX_CLIPS = 10;

    public const HEADLINE_SECONDS = 3;

    private const FONT = 'resources/fonts/InterTight-Bold.ttf';

    /**
     * The ffmpeg binary, or null when the server has none: FFMPEG_PATH (a full path, or a name looked up on the PATH), else a
     * static ffmpeg the team uploaded to storage/bin/ffmpeg. Never throws: a path outside the folders the site may open (Plesk's
     * open_basedir) used to raise a warning that Laravel turned into a 500 on the Files page (Panos, 2026-11-19).
     */
    public static function binary(): ?string
    {
        $setting = (string) config('services.ffmpeg.path', 'ffmpeg');
        $found = str_contains($setting, '/') ? (self::runnable($setting) ? $setting : null) : self::find($setting);

        return $found ?? (self::runnable(self::bundled()) ? self::bundled() : null);
    }

    /** Where a static ffmpeg can go without root: inside the app, so the site may run it whatever its open_basedir. */
    public static function bundled(): string
    {
        return storage_path('bin/ffmpeg');
    }

    /** Why there is no ffmpeg, in words for the Reel box; null when there is one. */
    public static function problem(): ?string
    {
        if (self::binary() !== null) {
            return null;
        }
        $setting = (string) config('services.ffmpeg.path', 'ffmpeg');
        $instead = ['bundled' => self::bundled()];
        if (str_contains($setting, '/')) {
            return match (true) {
                ! self::allowed($setting) => __('FFMPEG_PATH is :path, outside the folders this site may open, so it cannot run it. Upload ffmpeg to :bundled instead and set its permissions to 755.', ['path' => $setting] + $instead),
                ! @is_file($setting) => __('There is no file at :path (FFMPEG_PATH). Upload ffmpeg to :bundled instead and set its permissions to 755.', ['path' => $setting] + $instead),
                default => __('The file :path cannot be run: set its permissions to 755.', ['path' => $setting]),
            };
        }
        if (@is_file(self::bundled())) {
            return __('The file :path cannot be run: set its permissions to 755.', ['path' => self::bundled()]);
        }

        return __('Install ffmpeg on the server, or upload the static ffmpeg to :bundled and set its permissions to 755, to make Reels from clips (docs/pilot-setup.md).', $instead);
    }

    /** A file the site may run: no warning when it lies outside the folders the site may open. */
    public static function runnable(string $path): bool
    {
        return self::allowed($path) && @is_file($path) && @is_executable($path);
    }

    /** Inside open_basedir (or no open_basedir at all). */
    private static function allowed(string $path): bool
    {
        $base = (string) ini_get('open_basedir');
        if ($base === '') {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $base) as $dir) {
            if ($dir !== '' && str_starts_with($path, $dir)) {
                return true;
            }
        }

        return false;
    }

    private static function find(string $name): ?string
    {
        try {
            return @(new ExecutableFinder)->find($name);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $clips  paths of the clips, in playing order
     * @param  int  $seconds  what each clip is cut to (a shorter clip plays whole)
     */
    public function assemble(array $clips, int $seconds, string $headline, string $out): void
    {
        $binary = self::binary() ?? throw new RuntimeException('ffmpeg is not installed on the server (docs/pilot-setup.md).');
        $textFile = null;
        if (trim($headline) !== '') {
            // the lines are wrapped with GD like the photo overlay
            $lines = TextOverlay::wrap($headline, base_path(self::FONT), self::fontSize(), (int) (self::WIDTH * 0.84));
            file_put_contents($textFile = tempnam(sys_get_temp_dir(), 'headline'), implode("\n", $lines));
        }
        $process = (new Process([$binary, ...$this->arguments($clips, $seconds, $textFile, $out)]))->setTimeout(840);
        $process->run();
        if ($textFile) {
            @unlink($textFile);
        }
        throw_unless($process->isSuccessful(), new RuntimeException('ffmpeg: '.trim($process->getErrorOutput() ?: $process->getOutput())));
    }

    /**
     * The ffmpeg arguments after the binary, apart from running it so a test can read them.
     *
     * @param  list<string>  $clips  paths of the clips, in playing order
     * @param  ?string  $textFile  the headline's lines, or null for no headline
     * @return list<string>
     */
    public function arguments(array $clips, int $seconds, ?string $textFile, string $out): array
    {
        $arguments = ['-y', '-hide_banner', '-loglevel', 'error'];
        $filters = [];
        foreach ($clips as $i => $clip) {
            // files on this disk only: a clip that is really a playlist cannot make ffmpeg fetch anything
            array_push($arguments, '-protocol_whitelist', 'file', '-t', (string) $seconds, '-i', $clip);
            $filters[] = sprintf('[%d:v]scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,setsar=1,fps=30,setpts=PTS-STARTPTS[v%d]', $i, self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT, $i);
        }
        $filters[] = implode('', array_map(fn (int $i) => "[v{$i}]", array_keys($clips))).'concat=n='.count($clips).':v=1:a=0[reel]';
        if ($textFile !== null) {
            // the text comes from a file, so quotes and colons need no escaping, and expansion=none prints it as written:
            // with the default, a "%" (50% off) starts an expansion and ffmpeg drops the whole headline, and a backslash vanishes
            $size = self::fontSize();
            $filters[] = sprintf("[reel]drawtext=fontfile=%s:textfile=%s:expansion=none:fontsize=%d:fontcolor=white:line_spacing=%d:x=(w-text_w)/2:y=h*0.72:box=1:boxcolor=black@0.55:boxborderw=%d:enable='lt(t,%d)'[reel]", self::filterValue(base_path(self::FONT)), self::filterValue($textFile), $size, (int) ($size / 3), (int) ($size / 2), self::HEADLINE_SECONDS);
        }
        array_push($arguments, '-filter_complex', implode(';', $filters), '-map', '[reel]', '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-f', 'mp4', $out);

        return $arguments;
    }

    private static function fontSize(): int
    {
        return (int) (self::WIDTH * 0.06);
    }

    /** A path as an option value inside the filtergraph: escaped for the filter's options, then for the graph itself. */
    private static function filterValue(string $value): string
    {
        return addcslashes(addcslashes($value, "\\':"), "\\'[],;");
    }
}
