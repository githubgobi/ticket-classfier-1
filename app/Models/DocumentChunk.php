<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class DocumentChunk extends Model
{
    use HasNeighbors;

    protected $connection = 'pgsql_rag';

    protected $fillable = [
        'source',
        'chunk_index',
        'content',
        'embedding',
    ];

    protected $casts = [
        'embedding' => Vector::class,
    ];
}
