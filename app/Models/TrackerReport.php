<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackerReport extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime'];
    }
}
