<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $p = (string) config('mailroom.table_prefix', 'mailroom_');

        Schema::create($p.'mailboxes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->nullableUuidMorphs('owner');
            $table->string('address')->unique();
            $table->string('provider');
            $table->string('identity');
            $table->string('status')->default('active');
            $table->string('cursor')->nullable();
            $table->timestampTz('last_polled_at')->nullable();
            $table->timestampTz('disconnected_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create($p.'messages', function (Blueprint $table) use ($p): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('mailbox_id')->constrained($p.'mailboxes')->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->string('thread_id');
            $table->timestampTz('received_at');
            $table->text('from')->default('');
            $table->text('subject')->default('');
            $table->timestampsTz();
            $table->unique(['mailbox_id', 'provider_message_id']);
        });

        Schema::create($p.'typings', function (Blueprint $table) use ($p): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('message_id')->constrained($p.'messages')->cascadeOnDelete();
            $table->string('type_set');
            $table->integer('type_set_version');
            $table->string('type');
            $table->double('confidence')->nullable();
            $table->string('decided_by');
            $table->boolean('confident');
            $table->string('decision_id')->nullable();
            $table->uuid('superseded_by_id')->nullable();
            $table->timestampsTz();
            $table->index(['message_id', 'type_set']);
        });

        // Self-reference added once the primary key exists.
        Schema::table($p.'typings', function (Blueprint $table) use ($p): void {
            $table->foreign('superseded_by_id')->references('id')->on($p.'typings')->nullOnDelete();
        });

        // One current typing per message and set; superseded ones stay as history.
        DB::statement("create unique index {$p}typings_one_current on {$p}typings (message_id, type_set) where superseded_by_id is null");

        // Every label change Mailroom itself makes, so the watcher can tell its
        // own writes from a person's corrections when the history echoes them.
        Schema::create($p.'label_writes', function (Blueprint $table) use ($p): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('message_id')->constrained($p.'messages')->cascadeOnDelete();
            $table->string('label');
            $table->string('action');
            $table->timestampTz('echoed_at')->nullable();
            $table->timestampsTz();
            $table->index(['message_id', 'label', 'action']);
        });

        Schema::create($p.'webhook_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->nullableUuidMorphs('owner');
            $table->string('type_set');
            $table->text('url');
            $table->text('secret');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->index(['type_set', 'active']);
        });

        Schema::create($p.'webhook_deliveries', function (Blueprint $table) use ($p): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('subscription_id')->constrained($p.'webhook_subscriptions')->cascadeOnDelete();
            $table->jsonb('payload');
            $table->string('status')->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->integer('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        $p = (string) config('mailroom.table_prefix', 'mailroom_');

        foreach (['webhook_deliveries', 'webhook_subscriptions', 'label_writes', 'typings', 'messages', 'mailboxes'] as $name) {
            Schema::dropIfExists($p.$name);
        }
    }
};
