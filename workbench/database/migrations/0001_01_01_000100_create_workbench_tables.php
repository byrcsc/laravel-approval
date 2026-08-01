<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The host application's own schema: an org chart, two approvable models, and
 * the notifications table.
 *
 * `users`, `cache`, and `jobs` come from Testbench's default migrations, which
 * are timestamped `0001_01_01_00000{0,1,2}`. This file is `000100` so it lands
 * after them - `teams.lead_id` points at a table one of them creates.
 *
 * Nothing here is the approval schema. That is published from the package by
 * `composer build` and lands beside this file, gitignored.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The org chart. The package never expands a container model into its
        // members, so these two tables exist for the demo's approver resolvers
        // to read - they are what turns "the finance team" into people.
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['team_id', 'user_id']);
        });

        // Approved as an edit: submissions carry a draft of `vendor`, `amount`,
        // and `currency`, which is held on the request until the last approval.
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('vendor');
            // Minor units, so the demo never compares floats.
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->default('EUR');
            // The application's own lifecycle column. `approvableAttributes()`
            // on the model leaves it out, so no draft can move it.
            $table->string('status')->default('draft');
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // Approved as a record: creating one submits it, and approving changes
        // the request rather than the article. Publishing stays with the app,
        // which listens for `ApprovalApproved`.
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('body')->nullable();
            $table->boolean('published')->default(false);
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // What `php artisan make:notifications-table` writes. The demo enables
        // the `database` channel alongside `mail` so a notification is visible
        // without reading the log.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
