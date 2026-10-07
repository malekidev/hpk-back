<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'settings_revision' => 'integer', 'reported_revision' => 'integer',
            'last_command_sequence' => 'integer', 'combined_alert_mode' => 'integer',
            'acc_alert_mode' => 'integer', 'shock_level' => 'integer',
            'service_enabled' => 'boolean', 'power' => 'boolean', 'locked' => 'boolean',
            'combined_alert_recipient' => 'integer', 'acc_alert_recipient' => 'integer',
            'latitude' => 'float', 'longitude' => 'float',
            'last_seen_at' => 'datetime', 'last_fix_at' => 'datetime',
        ];
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(TrackerReport::class);
    }
}
