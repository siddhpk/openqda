<?php

use App\Services\Collab\Triggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laptop collaboration: the replication state of collaborative projects.
 *
 * Only used by the laptop edition (SQLite); on a server the tables stay
 * empty. The capture triggers are not created here but after every
 * migration run (App\Listeners\InstallCollabTriggers), because they follow
 * the tables that exist (the Analysis Toolkit tables are optional).
 */
return new class extends Migration
{
    public function up(): void
    {
        // a local project that takes part in a collaboration
        Schema::create('collab_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->unique()->constrained('projects')->nullOnDelete();
            $table->uuid('gid')->unique();
            $table->string('founder', 32);
            $table->text('founder_pub');
            $table->string('status', 16)->default('active'); // active | left
            $table->timestamps();
        });

        // local id <-> global id (gid) of every replicated row
        Schema::create('collab_ids', function (Blueprint $table) {
            $table->id();
            $table->string('tbl', 64);
            $table->string('local_id', 191);
            $table->string('gid', 191);
            $table->boolean('canonical')->default(true);
            $table->unique(['tbl', 'gid']);
            $table->index(['tbl', 'local_id']);
        });

        // the merged state of every replicated row (a cache of its operations)
        Schema::create('collab_rows', function (Blueprint $table) {
            $table->id();
            $table->string('tbl', 64);
            $table->string('gid', 191);
            $table->uuid('project_gid')->index();
            $table->string('creator', 32)->nullable();
            $table->longText('data')->nullable();
            $table->longText('heads')->nullable();
            $table->longText('self_pending')->nullable();
            $table->boolean('deleted')->default(false);
            $table->string('state', 16)->default('ok'); // ok | waiting | orphan
            $table->string('waiting_on', 191)->nullable()->index();
            $table->timestamps();
            $table->unique(['tbl', 'gid']);
        });

        // the signed operation log
        Schema::create('collab_ops', function (Blueprint $table) {
            $table->id();
            $table->string('op_id', 64)->unique();
            $table->uuid('project_gid');
            $table->string('origin', 32);
            $table->unsignedBigInteger('seq');
            $table->string('hlc', 64);
            $table->string('tbl', 64);
            $table->string('row_gid', 191);
            $table->string('kind', 16);
            $table->longText('payload');
            $table->string('status', 16)->default('applied');
            $table->timestamp('created_at')->nullable();
            $table->index(['project_gid', 'origin', 'seq']);
            $table->index(['tbl', 'row_gid']);
            $table->index(['project_gid', 'status']);
            $table->index(['project_gid', 'hlc']);
        });

        // version vectors: the highest contiguous sequence held per origin
        Schema::create('collab_vv', function (Blueprint $table) {
            $table->uuid('project_gid');
            $table->string('origin', 32);
            $table->unsignedBigInteger('seq')->default(0);
            $table->primary(['project_gid', 'origin']);
        });

        // rows changed locally since the last flush (filled by triggers)
        Schema::create('collab_dirty', function (Blueprint $table) {
            $table->string('tbl', 64);
            $table->string('pk', 191);
            $table->primary(['tbl', 'pk']);
        });

        // applying=1 while remote changes are written (triggers stay quiet)
        Schema::create('collab_ctx', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedTinyInteger('applying')->default(0);
        });
        DB::table('collab_ctx')->insert(['id' => 1, 'applying' => 0]);

        Schema::create('collab_invites', function (Blueprint $table) {
            $table->id();
            $table->uuid('project_gid')->index();
            $table->char('token_hash', 64)->unique();
            $table->string('role', 16);
            $table->string('key_mode', 16)->default('none');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_by', 32)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // invitations this computer accepted and still has to present
        Schema::create('collab_joins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('project_gid');
            $table->string('project_name');
            $table->text('invite');
            $table->string('status', 16)->default('pending'); // pending | joined | failed
            $table->string('message')->nullable();
            $table->timestamps();
        });

        // hashes of source files, recomputed only when size or time change
        Schema::create('collab_files', function (Blueprint $table) {
            $table->id();
            $table->string('source_id', 64);
            $table->string('kind', 8); // file | html
            $table->char('sha', 64)->nullable()->index();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedBigInteger('mtime')->nullable();
            $table->unique(['source_id', 'kind']);
        });

        Schema::create('collab_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        // the triggers refer to collab_ctx and collab_dirty
        if (DB::getDriverName() === 'sqlite') {
            Triggers::uninstall();
        }
        foreach (['collab_settings', 'collab_files', 'collab_joins', 'collab_invites', 'collab_ctx', 'collab_dirty',
            'collab_vv', 'collab_ops', 'collab_rows', 'collab_ids', 'collab_projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
