<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A file from a watched folder is filed once per client, even when two pulls of the folder run at once. */
return new class extends Migration
{
    public function up(): void
    {
        // two pulls at once may already have filed a file twice: the later copies stay in the inbox, they only forget where they came from
        $twice = DB::table('assets')->whereNotNull('source_id')->groupBy('client_id', 'source_id')->havingRaw('count(*) > 1')->selectRaw('client_id, source_id, min(id) as first_id')->get();
        foreach ($twice as $group) {
            DB::table('assets')->where('client_id', $group->client_id)->where('source_id', $group->source_id)->where('id', '!=', $group->first_id)->update(['source_id' => null]);
        }

        Schema::table('assets', fn (Blueprint $table) => $table->unique(['client_id', 'source_id']));
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->dropUnique(['client_id', 'source_id']));
    }
};
