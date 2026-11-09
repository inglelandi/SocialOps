<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Designs in the reference folder (kind design): Claude describes each one once through the connector; the text goes in `text`, the time here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->dateTime('described_at')->nullable()->after('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->dropColumn('described_at');
        });
    }
};
