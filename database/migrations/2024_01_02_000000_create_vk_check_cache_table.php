<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVkCheckCacheTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vk_check_cache', function (Blueprint $table) {
            $table->id();
            $table->string('group_name', 255);
            $table->integer('group_id');
            $table->text('post_text')->nullable();
            $table->integer('likes')->default(0);
            $table->integer('reposts')->default(0);
            $table->dateTime('cached_at')->nullable();
            $table->timestamps();
            
            // Индексы для быстрого поиска
            $table->index('group_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vk_check_cache');
    }
}

