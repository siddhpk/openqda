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
        Schema::create('sources', function (Blueprint $table) {
            $id = $table->uuid('id');
            // MySQL/MariaDB generate a default; SQLite (laptop edition) gets ids from the application
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $id->default(DB::raw('(UUID())'));
            }
            $table->primary('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('creating_user_id')->constrained('users');
            $table->foreignId('modifying_user_id')->nullable()->constrained('users');
            $table->foreignId('project_id')->constrained('projects');
            $type = $table->enum('type', ['text', 'picture', 'pdf', 'audio', 'video']);
            if (DB::connection()->getDriverName() === 'sqlite') {
                // MySQL implicitly defaults a NOT NULL enum to its first value; SQLite needs it spelled out
                $type->default('text');
            }
            $table->string('base_path')->default('');
            $table->text('plain_text_content')->nullable();
            $table->string('plain_text_path')->nullable();
            $table->string('rich_text_path')->nullable();
            $table->string('path')->nullable();
            $table->string('current_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
