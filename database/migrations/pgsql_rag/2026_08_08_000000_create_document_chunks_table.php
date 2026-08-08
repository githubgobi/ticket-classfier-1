<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        Schema::connection($this->connection)->create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->vector('embedding', 768);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('document_chunks');
    }
};
