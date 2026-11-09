<?php

namespace App\Console\Commands;

use App\Clients\ClientProfile;
use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ImportClients extends Command
{
    protected $signature = 'clients:import {dir=config/clients : Folder of <slug>.yaml profiles}';

    protected $description = 'Create or update clients from YAML profiles (example.yaml is skipped)';

    public function handle(): int
    {
        $dir = base_path($this->argument('dir'));
        $files = array_filter(glob($dir.'/*.yaml') ?: [], fn ($f) => basename($f) !== 'example.yaml');
        $failed = 0;

        foreach ($files as $file) {
            try {
                $yaml = file_get_contents($file);
                $scalars = Arr::only((array) Yaml::parse($yaml), ['slug', 'code', 'name', 'type', 'location']);
                $existing = Client::where('slug', $scalars['slug'] ?? '')->first();
                $scalars = Validator::make($scalars, ClientProfile::scalarRules($existing?->id))->validate();
                $client = Client::updateOrCreate(['slug' => $scalars['slug']], $scalars + ['profile' => ClientProfile::parse($yaml)]);
                $this->info(basename($file).': '.($client->wasRecentlyCreated ? 'created' : 'updated'));
            } catch (ValidationException $e) {
                $failed++;
                $this->error(basename($file).': '.implode(' ', Arr::flatten($e->errors())));
            } catch (ParseException $e) {
                $failed++; // a typo in one profile does not stop the others
                $this->error(basename($file).': not valid YAML: '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
