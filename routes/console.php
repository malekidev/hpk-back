<?php

use App\Models\Device;
use App\Models\TrackerReport;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

Artisan::command('tracker:user {email} {--name=}', function () {
    $email = strtolower($this->argument('email'));
    $password = $this->secret('Password (at least 12 characters)');
    $data = Validator::make([
        'email' => $email,
        'name' => $this->option('name') ?: $email,
        'password' => $password,
    ], [
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'name' => ['required', 'string', 'max:255'],
        'password' => ['required', 'string', 'min:12', 'max:255'],
    ])->validate();
    User::create($data);
    $this->info('User created.');
})->purpose('Create an owner account without public registration');

Artisan::command('tracker:device {imei} {email} {--name=} {--last-command=0}', function () {
    $owner = User::where('email', strtolower($this->argument('email')))->firstOrFail();
    $data = Validator::make([
        'imei' => $this->argument('imei'),
        'name' => $this->option('name') ?: 'Tracker',
        'last_command_sequence' => $this->option('last-command'),
    ], [
        'imei' => ['required', 'string', 'regex:/^[0-9]{15}$/D', 'unique:devices,imei'],
        'name' => ['required', 'string', 'max:255'],
        'last_command_sequence' => ['required', 'integer', 'between:0,65535'],
    ])->validate();
    $owner->devices()->create($data);
    $this->info('Device provisioned.');
})->purpose('Assign a new tracker to an owner; seed its previous command ID when migrating');

Artisan::command('tracker:prune {--days=30}', function () {
    $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($days === false) {
        $this->error('Days must be a positive integer.');
        return 1;
    }
    $count = TrackerReport::where('received_at', '<', now()->subDays($days))->delete();
    $this->info("Deleted {$count} old telemetry reports. Command history was retained.");
    return 0;
})->purpose('Delete telemetry older than the chosen retention period');
