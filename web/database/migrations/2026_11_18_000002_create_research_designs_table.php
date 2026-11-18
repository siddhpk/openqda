<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The research design of a project in the researchers' own words: research
 * questions, aims, analytic approach, positioning and rationale, plus an
 * optional analysis plan. OpenQDA does not offer methodology profiles; the
 * approach is free text. Every change of the design is kept as a revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('questions')->nullable();
            $table->text('aims')->nullable();
            $table->text('approach')->nullable();
            $table->text('positioning')->nullable();
            $table->text('rationale')->nullable();
            $table->json('plan')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('research_design_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['project_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_design_revisions');
        Schema::dropIfExists('research_designs');
    }
};
