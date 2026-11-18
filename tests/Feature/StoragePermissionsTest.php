<?php

use App\Console\Commands\OpenStorageFolders;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

// Panos, 2026-11-18: "the clients folder does not have any permissions… issues on the server when it tries to take backup".
// Laravel made the local disk's folders 0700; they are 0755 now, and storage:open-folders (run by /refresh) opens older ones.

function modeOf(string $path): int
{
    clearstatcache();

    return fileperms($path) & 0777;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/socialops-perms-'.uniqid();
    File::ensureDirectoryExists($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

test('the media disk makes its folders readable for the backup: 0755, the files 0644', function () {
    $disk = Storage::build(array_merge(config('filesystems.disks.local'), ['root' => $this->dir]));
    $disk->put('clients/almyra/inbox/ALMYRA_20261103_ANY_IMG_FOOD_EL-EN_v1.jpg', 'x');

    expect(modeOf("{$this->dir}/clients"))->toBe(0755)
        ->and(modeOf("{$this->dir}/clients/almyra/inbox"))->toBe(0755)
        ->and(modeOf("{$this->dir}/clients/almyra/inbox/ALMYRA_20261103_ANY_IMG_FOOD_EL-EN_v1.jpg"))->toBe(0644);
});

test('storage:open-folders opens the folders and files Laravel made private, and takes nothing away', function () {
    File::ensureDirectoryExists("{$this->dir}/clients/almyra/inbox");
    file_put_contents("{$this->dir}/clients/almyra/inbox/a.jpg", 'x');
    file_put_contents("{$this->dir}/clients/almyra/notes.txt", 'x');
    chmod("{$this->dir}/clients/almyra/inbox/a.jpg", 0600);
    chmod("{$this->dir}/clients/almyra/notes.txt", 0664);
    chmod("{$this->dir}/clients/almyra/inbox", 0700);
    chmod("{$this->dir}/clients/almyra", 0700);
    chmod("{$this->dir}/clients", 0700);

    $this->artisan('storage:open-folders', ['--path' => [$this->dir]])->expectsOutput('Opened 4 folders and files.')->assertSuccessful();

    expect(modeOf("{$this->dir}/clients"))->toBe(0755)
        ->and(modeOf("{$this->dir}/clients/almyra/inbox"))->toBe(0755)
        ->and(modeOf("{$this->dir}/clients/almyra/inbox/a.jpg"))->toBe(0644)
        ->and(modeOf("{$this->dir}/clients/almyra/notes.txt"))->toBe(0664); // already readable: left as it was

    expect(OpenStorageFolders::open([$this->dir]))->toBe(['opened' => 0, 'closed' => []]) // nothing left to open
        ->and(OpenStorageFolders::open(["{$this->dir}/missing"]))->toBe(['opened' => 0, 'closed' => []]);
});

test('Refresh after update opens the stored folders', function () {
    $folder = storage_path('app/private/clients/zz-permissions-test/inbox');
    File::ensureDirectoryExists($folder);
    chmod($folder, 0700);
    chmod(dirname($folder), 0700);

    try {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post('/refresh')
            ->assertRedirect('/dashboard')->assertSessionHas('status', 'app-refreshed')->assertSessionHas('storage_closed', 0);
        expect(modeOf($folder))->toBe(0755)->and(modeOf(dirname($folder)))->toBe(0755);
    } finally {
        File::deleteDirectory(dirname($folder));
    }
});

test('the dashboard says when stored folders belong to another user, so the backup cannot read them', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertOk()->assertDontSee('belong to another user on the server');
    $this->withSession(['storage_closed' => 3])->get('/dashboard')->assertOk()
        ->assertSee('3 stored folders or files belong to another user on the server, so the backup cannot read them.');
});
