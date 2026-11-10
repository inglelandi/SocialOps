<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The products of a client's shop, read from its product feed (the XML a shop makes for Skroutz, BestPrice, Google Shopping or
// Facebook), so Claude links real products in the posts it plans. Text only: the pictures stay on the shop.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete(); // the product_feed row: unlinking the feed drops its products
            $table->string('external_id', 191); // the feed's own id, or a hash of the link when it has none
            $table->string('name', 500);
            $table->text('url');
            $table->string('price', 40)->nullable(); // as the feed writes it: "12,90", "12.90 EUR"
            $table->string('sale_price', 40)->nullable();
            $table->string('category', 500)->nullable();
            $table->string('brand', 191)->nullable();
            $table->text('image')->nullable(); // the link only; the picture is never downloaded
            $table->boolean('in_stock')->nullable(); // null when the feed does not say
            $table->string('description', 500)->nullable();
            $table->text('search'); // name, category, brand and description in lowercase without accents (ClientProduct::fold)
            $table->timestamps();
            $table->unique(['client_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_products');
    }
};
