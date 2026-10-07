<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceCommand extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'result' => 'boolean', 'sent_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
