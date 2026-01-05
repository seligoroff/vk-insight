<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vk_check_cache', function (Blueprint $table) {
            $table->integer('members_count')->nullable()->after('reposts');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vk_check_cache', function (Blueprint $table) {
            $table->dropColumn('members_count');
        });
    }
};
