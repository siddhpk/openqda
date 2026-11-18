<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('codes', function (Blueprint $table) {
            $id = $table->uuid('id');
            // MySQL/MariaDB generate a default; SQLite (laptop edition) gets ids from the application
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $id->default(DB::raw('(UUID())'));
            }
            $table->primary('id');
            $table->string('name');

            $table->string('color');
            $table->unsignedBigInteger('codebook_id');
            $table->text('description')->nullable();

            $table->foreign('codebook_id')
                ->references('id')
                ->on('codebooks')
                ->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('codes');
    }
};
