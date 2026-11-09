<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The documents of a client's reference folder on Google Drive (the Files tab): what Claude reads when it plans, as text, per file.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete(); // the drive_docs row: unlinking the folder drops its documents
            $table->string('source_id'); // the Drive file id
            $table->string('name');
            $table->string('path')->default(''); // the subfolder, for display; '' at the root of the folder
            $table->string('mime', 120);
            $table->string('kind', 20); // previous_posts | reference | ignored (ClientDocument::KINDS)
            $table->boolean('kind_set_by_user')->default(false); // a kind the team chose is never overwritten by a sync
            $table->dateTime('modified_at')->nullable(); // as Drive reports it: the text is fetched again when it changes
            $table->unsignedInteger('size')->nullable();
            $table->longText('text')->nullable(); // the file as text, at most ClientDocument::MAX_CHARS
            $table->unsignedInteger('chars')->default(0);
            $table->dateTime('fetched_at')->nullable();
            $table->text('error')->nullable(); // why the text could not be read (a PDF, an old .doc, a download that failed)
            $table->timestamps();
            $table->unique(['client_id', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_documents');
    }
};
