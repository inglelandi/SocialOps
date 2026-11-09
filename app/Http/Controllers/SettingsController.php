<?php

namespace App\Http\Controllers;

use Dotenv\Dotenv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/** The server configuration (.env) edited in the browser by an admin, so nobody has to open system files. */
class SettingsController extends Controller
{
    /** A new key makes every encrypted token and signed link unreadable, so it stays out of reach. */
    private const LOCKED = ['APP_KEY'];

    private const NAME = '/^[A-Z][A-Z0-9_]*$/';

    public function edit(): View
    {
        $path = app()->environmentFilePath();

        return view('settings.index', ['values' => is_file($path) ? Dotenv::parse(file_get_contents($path)) : []]);
    }

    /** One short mail to the admin with the current mail settings: the server's own answer on the page when it fails. */
    public function testMail(Request $request): RedirectResponse
    {
        try {
            Mail::raw(__('This is a test e-mail from Social Ops. If you can read it, the mail settings work.'), fn ($message) => $message->to($request->user()->email)->subject(__('Social Ops test e-mail')));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['mail' => __('The test e-mail could not be sent: :error', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', 'test-mail-sent');
    }

    /**
     * Only the values that changed are written: in place (on every line of a name that appears twice), new names at the
     * end; untouched lines keep their quotes, their ${REFERENCES} and the comments around them. The new file must load
     * back with every value as typed before it replaces .env, because a .env that does not load takes the whole site down.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['values' => ['array'], 'values.*' => ['nullable', 'string', 'max:4000'], 'new_name' => ['nullable', 'regex:'.self::NAME], 'new_value' => ['nullable', 'string', 'max:4000']]);
        $values = $data['values'] ?? [];
        if ($data['new_name'] ?? '') {
            $values[$data['new_name']] = $data['new_value'] ?? '';
        }
        // a value of several lines comes back from its textarea with CRLF line ends
        $values = array_map(fn ($value) => str_replace("\r\n", "\n", (string) $value), $values);
        $values = array_filter($values, fn ($value, $name) => preg_match(self::NAME, $name) && ! in_array($name, self::LOCKED), ARRAY_FILTER_USE_BOTH);

        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_writable($path) || ! is_writable(dirname($path))) {
            return back()->withErrors(['settings' => __('The .env file is not writable by the web server.')]);
        }
        $old = (string) file_get_contents($path);
        try {
            $current = Dotenv::parse($old);
        } catch (Throwable $e) {
            return back()->withErrors(['settings' => __('Nothing was saved: the .env file on the server does not load (:error). Fix it by hand, .env.backup holds the file before the last save.', ['error' => $e->getMessage()])]);
        }
        $changed = array_filter($values, fn ($value, $name) => ! array_key_exists($name, $current) || (string) $current[$name] !== $value, ARRAY_FILTER_USE_BOTH);

        $lines = [];
        $raw = []; // each untouched entry as written, to tell a value made from ${ANOTHER} below
        $pending = $changed;
        foreach (self::entries($old) as [$name, $text]) {
            if ($name !== null && array_key_exists($name, $changed)) {
                $lines[] = $name.'='.self::quote($changed[$name]);
                unset($pending[$name]);

                continue;
            }
            $lines[] = $text;
            if ($name !== null) {
                $raw[$name] = $text;
            }
        }
        foreach ($pending as $name => $value) {
            $lines[] = $name.'='.self::quote($value);
        }
        $new = implode("\n", $lines)."\n";

        if ($problem = self::problem($new, $current, $changed, $raw)) {
            return back()->withInput()->withErrors(['settings' => __('Nothing was saved, the .env file is as it was: :problem', ['problem' => $problem])]);
        }

        copy($path, $path.'.backup'); // the previous file, for a rollback by hand when a value locks the app out
        file_put_contents($path.'.tmp', $new);
        @chmod($path.'.tmp', fileperms($path) & 0777); // the passwords in it stay as private as they were
        rename($path.'.tmp', $path);
        Artisan::call('config:cache'); // the next request and the next queue minute run on the new values

        return redirect()->route('settings.edit')->with('status', 'settings-saved');
    }

    /**
     * The file cut into entries the way phpdotenv cuts it (Dotenv\Parser\Lines): a double-quoted value may run over
     * several lines. Each entry is [its name, or null for a comment or a blank line; its text].
     *
     * @return list<array{0: ?string, 1: string}>
     */
    private static function entries(string $content): array
    {
        $entries = [];
        $open = null; // the lines of a quoted value that has not closed yet
        foreach (preg_split('/\r\n|\n|\r/', rtrim($content, "\r\n")) as $line) {
            if ($open !== null) {
                $open[] = $line;
                if (self::quotes($line) >= 1) {
                    $entries[] = implode("\n", $open);
                    $open = null;
                }

                continue;
            }
            $quote = strpos($line, '="');
            $hash = strpos($line, '#');
            if ($quote !== false && ($hash === false || $hash > $quote) && self::quotes($line) <= 1) {
                $open = [$line];

                continue;
            }
            $entries[] = $line;
        }
        if ($open !== null) {
            $entries[] = implode("\n", $open);
        }

        return array_map(fn (string $text) => [preg_match('/^\s*(?:export\s+)?([A-Za-z0-9_.]+)\s*=/', $text, $m) ? $m[1] : null, $text], $entries);
    }

    /** The double quotes on a line that are not escaped, counted as phpdotenv counts them. */
    private static function quotes(string $line): int
    {
        return (int) preg_match_all('/(?<!\\\\)"/', str_replace('\\\\', '', $line));
    }

    /** Why the new file may not replace .env, or null when every value loads back as it should. */
    private static function problem(string $new, array $current, array $changed, array $raw): ?string
    {
        try {
            $parsed = Dotenv::parse($new);
        } catch (Throwable $e) {
            return __('the new file would not load (:error).', ['error' => $e->getMessage()]);
        }
        foreach ($changed as $name => $value) {
            if (($parsed[$name] ?? null) !== $value) {
                return __('the value of :name would not read back as typed.', ['name' => $name]);
            }
        }
        foreach ($current as $name => $value) {
            // a value made from ${ANOTHER} follows that one when it changes; every other value stays exactly as it was
            $follows = str_contains($raw[$name] ?? '', '${');
            if (! array_key_exists($name, $changed) && (! array_key_exists($name, $parsed) || ($parsed[$name] !== $value && ! $follows))) {
                return __('the value of :name would change.', ['name' => $name]);
            }
        }

        return null;
    }

    /** A plain value as it is; anything else in double quotes, with backslashes, quotes, dollar signs and line breaks escaped. */
    private static function quote(string $value): string
    {
        return preg_match('~^[\w./:@,+-]*$~', $value) ? $value : '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$', "\n" => '\\n', "\r" => '\\r']).'"';
    }
}
