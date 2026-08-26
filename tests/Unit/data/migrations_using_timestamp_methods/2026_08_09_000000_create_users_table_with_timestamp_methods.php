<?php

declare(strict_types=1);

namespace Tests\Unit\MigrationsUsingTimestampMethods;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTableWithTimestampMethods extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('teams', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->nullableTimestampsTz();
        });

        Schema::create('accounts', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->rememberToken();
            $table->timestampsTz();
        });

        Schema::table('accounts', static function (Blueprint $table) {
            $table->dropTimestamps();
            $table->dropRememberToken();
        });
    }
}
