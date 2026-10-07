<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

class TrackerPoll
{
    public function handle(array $data): string
    {
        return DB::transaction(function () use ($data) {
            $device = Device::where('imei', $data['i'])->lockForUpdate()->firstOrFail();
            $device->last_seen_at = now();
            $device->reported_revision = (int) $data['r'];
            $device->power = (bool) $data['p'];
            if (isset($data['lat'], $data['lon'])) {
                $device->latitude = $data['lat'];
                $device->longitude = $data['lon'];
                $device->last_fix_at = now();
            }

            if (isset($data['c'], $data['a'])) {
                $command = $device->commands()->where('sequence', $data['c'])->where('status', 'sent')->first();
                if ($command) {
                    $command->update([
                        'status' => match ((string) $data['a']) {
                            '1' => 'succeeded',
                            '0' => 'failed',
                            '2' => 'unknown',
                        },
                        'result' => (string) $data['a'] === '2' ? null : (string) $data['a'] === '1',
                        'completed_at' => now(),
                    ]);
                    if ((string) $data['a'] === '1') {
                        $device->locked = $command->action === 'L';
                    }
                }
                $device->last_command_sequence = max($device->last_command_sequence, (int) $data['c']);
                // The firmware clears its pending report only after an empty-command response.
                $response = $this->encode([(int) $data['r'], null, null, null, null, null, null, null, null, null, null]);
            } else {
                $fields = [
                    $device->settings_revision,
                    $device->combined_alert_mode,
                    $device->acc_alert_mode,
                    $device->shock_level,
                    $device->service_enabled === null ? null : (int) $device->service_enabled,
                    $device->master_number,
                    $device->backup_number === null ? 'off' : $device->backup_number,
                    $device->combined_alert_recipient,
                    $device->acc_alert_recipient,
                    null,
                    null,
                ];
                if ($device->reported_revision === $device->settings_revision && $device->power) {
                    $command = $device->commands()->where('status', 'sent')->orderBy('id')->first()
                        ?? $device->commands()->where('status', 'queued')->orderBy('id')->first();
                    if ($command) {
                        if ($command->status === 'queued') {
                            $command->update(['status' => 'sent', 'sent_at' => now()]);
                        }
                        $fields[9] = $command->sequence;
                        $fields[10] = $command->action;
                    }
                }
                $response = $this->encode($fields);
            }

            $device->save();
            $device->reports()->create(['payload' => $data, 'response' => $response, 'received_at' => now()]);

            return $response;
        }, 5);
    }

    private function encode(array $fields): string
    {
        return implode(',', array_merge(['1'], $fields));
    }
}
