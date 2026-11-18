<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // per-project opt-in; nothing of a project is sent to a provider without it
        Schema::create('project_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained('projects')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            // fingerprint of the provider configuration the owner agreed to;
            // a changed configuration requires a new decision
            $table->string('consent_fingerprint', 64)->nullable();
            $table->timestamp('cloud_acknowledged_at')->nullable();
            // project-specific terms to pseudonymise (encrypted at rest)
            $table->text('redaction_terms')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // embedding cache, scoped per project, encrypted at rest, holds no plain text
        Schema::create('ai_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->char('hash', 64)->unique();
            $table->string('provider', 32);
            $table->string('model');
            $table->unsignedInteger('dimensions');
            $table->longText('vector');
            $table->timestamp('created_at')->nullable()->index();
        });

        // what was sent where (counts only, never content)
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('provider', 32);
            $table->string('model');
            $table->boolean('cloud')->default(false);
            $table->unsignedInteger('items')->default(0);
            $table->unsignedBigInteger('characters')->default(0);
            $table->unsignedInteger('redactions')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['project_id', 'created_at']);
        });

        // audit trail of autocoding runs: parameters and metrics only, no text
        Schema::create('autocode_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->foreignId('creating_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('params')->nullable();
            $table->json('metrics')->nullable();
            $table->unsignedInteger('accepted')->default(0);
            $table->timestamps();
        });

        // what researchers did with each suggestion; segments by position and keyed hash, no text
        Schema::create('ai_suggestion_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('autocode_run_id')->constrained('autocode_runs')->cascadeOnDelete();
            $table->foreignUuid('source_id')->constrained('sources')->cascadeOnDelete();
            $table->unsignedInteger('segment_start');
            $table->unsignedInteger('segment_end');
            $table->char('segment_hash', 64);
            $table->string('decision', 16); // accepted | modified | rejected | deferred
            $table->json('suggested');
            $table->json('chosen');
            $table->json('scores')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['autocode_run_id', 'decision']);
            $table->index(['project_id', 'segment_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestion_decisions');
        Schema::dropIfExists('autocode_runs');
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_embeddings');
        Schema::dropIfExists('project_ai_settings');
    }
};
