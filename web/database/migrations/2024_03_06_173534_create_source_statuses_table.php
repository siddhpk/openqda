<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('source_statuses', function (Blueprint $table) {
            $id = $table->uuid('id');
            // MySQL/MariaDB generate a default; SQLite (laptop edition) gets ids from the application
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $id->default(DB::raw('(UUID())'));
            }
            $table->primary('id');
            $table->foreignUuid('source_id')->constrained('sources');
            $table->text('status');
            $table->string('path')->nullable(); // Allow optional paths
            $table->timestamps(); // Add standard created_at and updated_at fields
        });
    }

    public function down()
    {
        Schema::dropIfExists('source_statuses');
    }
};
