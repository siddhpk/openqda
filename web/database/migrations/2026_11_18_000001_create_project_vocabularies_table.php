<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The analytic vocabulary of a project (code types, relationship types,
 * memo types, statuses, custom code fields). Projects without a row use
 * the neutral defaults of App\Services\Qda\Vocabulary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_vocabularies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_vocabularies');
    }
};
