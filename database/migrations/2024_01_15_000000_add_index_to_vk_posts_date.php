<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIndexToVkPostsDate extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vk_posts', function (Blueprint $table) {
            // Составной индекс для оптимизации запросов по owner_id и date
            // Используется в команде vk:analytics для быстрого поиска постов за период
            $table->index(['owner_id', 'date'], 'idx_owner_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vk_posts', function (Blueprint $table) {
            $table->dropIndex('idx_owner_date');
        });
    }
}

