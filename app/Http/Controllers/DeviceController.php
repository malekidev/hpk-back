<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController
{
    public function index(Request $request)
    {
        return $request->user()->devices()->orderBy('id')->paginate(25);
    }

    public function show(Request $request, string $device)
    {
        return $this->owned($request, $device);
    }

    public function settings(Request $request, string $device)
    {
        $data = $request->validate([
            'combined_alert_mode' => ['sometimes', 'required', 'integer', 'between:0,2'],
            'acc_alert_mode' => ['sometimes', 'required', 'integer', 'between:0,2'],
            'shock_level' => ['sometimes', 'required', 'integer', 'between:1,8'],
                        'service_enabled' => ['sometimes', 'required', 'boolean'],
            'master_number' => ['sometimes', 'required', 'regex:/^\+98[0-9]{10}$/D'],
            'backup_number' => ['sometimes', 'nullable', 'regex:/^\+98[0-9]{10}$/D'],
            'combined_alert_recipient' => ['sometimes', 'nullable', 'integer', 'between:0,1'],
            'acc_alert_recipient' => ['sometimes', 'nullable', 'integer', 'between:0,1'],
        ]);
        abort_if($data === [], 422, 'Provide at least one setting.');

        return DB::transaction(function () use ($request, $device, $data) {
            $record = $request->user()->devices()->whereKey($device)->lockForUpdate()->firstOrFail();
            $record->fill($data);
            if ($record->isDirty()) {
                abort_if($record->settings_revision >= 65535, 409, 'Settings revision exhausted; device reprovisioning is required.');
                $record->settings_revision++;
                $record->save();
            }

            return $record;
        }, 5);
    }

    public function commands(Request $request, string $device)
    {
        return $this->owned($request, $device)->commands()->latest('id')->paginate(25);
    }

    public function storeCommand(Request $request, string $device)
    {
        $data = $request->validate([
            'action' => ['required', 'in:L,U'],
            'request_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/D'],
        ]);

        return DB::transaction(function () use ($request, $device, $data) {
            $record = $request->user()->devices()->whereKey($device)->lockForUpdate()->firstOrFail();
            $existing = $record->commands()->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_if($existing->action !== $data['action'], 409, 'Request key already belongs to another action.');

                return response()->json($existing);
            }
            abort_if($record->last_command_sequence >= 65535, 409, 'Command IDs exhausted; device reprovisioning is required.');
            $record->last_command_sequence++;
            $record->save();
            $command = $record->commands()->create([
                'sequence' => $record->last_command_sequence,
                'action' => $data['action'],
                'request_key' => $data['request_key'],
                'status' => 'queued',
            ]);

            return response()->json($command, 201);
        }, 5);
    }

    public function reports(Request $request, string $device)
    {
        return $this->owned($request, $device)->reports()->latest('id')->paginate(50);
    }

    private function owned(Request $request, string $id): Device
    {
        return $request->user()->devices()->whereKey($id)->firstOrFail();
    }
}
