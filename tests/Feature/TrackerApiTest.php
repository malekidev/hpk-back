<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Device $device;
    private string $bridgeToken = 'test-only-bridge-token-not-a-real-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tracker.bridge_token' => $this->bridgeToken]);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test-password-only']);
        $this->device = $this->owner->devices()->create(['name' => 'Car', 'imei' => '123456789012345']);
    }

    private function poll(array $overrides = [])
    {
        return $this->withToken($this->bridgeToken)->postJson('/api/tracker/poll', array_replace([
            'i' => $this->device->imei, 'r' => '0', 'c' => '', 'a' => '',
            'p' => '1', 'lat' => '', 'lon' => '',
        ], $overrides));
    }

    private function queue(string $action, string $key): int
    {
        Sanctum::actingAs($this->owner);
        $response = $this->postJson("/api/devices/{$this->device->id}/commands", ['action' => $action, 'request_key' => $key]);
        $response->assertCreated();

        return $response->json('sequence');
    }

    public function test_poll_requires_bridge_authentication(): void
    {
        $this->postJson('/api/tracker/poll', [])->assertUnauthorized();
        config(['tracker.bridge_token' => '']);
        $this->poll()->assertStatus(503);
    }

    public function test_unknown_imei_cannot_register_itself(): void
    {
        $this->poll(['i' => '999999999999999'])->assertNotFound();
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_idle_response_and_telemetry_are_saved(): void
    {
        $this->poll(['lat' => '35.6892', 'lon' => '51.3890'])->assertOk()->assertContent('1,0,,,,,,,,,,');
        $this->assertDatabaseCount('tracker_reports', 1);
        $this->assertSame(35.6892, $this->device->fresh()->latitude);
        $this->assertNotNull($this->device->fresh()->last_seen_at);
        $this->poll();
        $this->assertSame(35.6892, $this->device->fresh()->latitude);
    }

    public function test_ack_is_separate_from_next_command_and_repeated_reports_are_safe(): void
    {
        $first = $this->queue('U', 'unlock-request-01');
        $second = $this->queue('L', 'lock-request-002');
        $this->poll()->assertContent("1,0,,,,,off,,,,{$first},U");
        $this->poll()->assertContent("1,0,,,,,off,,,,{$first},U");
        $this->poll(['c' => (string) $first, 'a' => '1'])->assertContent('1,0,,,,,,,,,,');
        $this->poll(['c' => (string) $first, 'a' => '1'])->assertContent('1,0,,,,,,,,,,');
        $this->assertDatabaseHas('device_commands', ['sequence' => $first, 'status' => 'succeeded', 'result' => 1]);
        $this->assertDatabaseHas('device_commands', ['sequence' => $second, 'status' => 'queued']);
        $this->poll()->assertContent("1,0,,,,,off,,,,{$second},L");
    }

    public function test_zero_result_is_a_failure_and_is_acknowledged(): void
    {
        $id = $this->queue('L', 'failed-request-01');
        $this->poll();
        $this->poll(['c' => (string) $id, 'a' => '0'])->assertContent('1,0,,,,,,,,,,');
        $this->assertDatabaseHas('device_commands', ['sequence' => $id, 'status' => 'failed', 'result' => 0]);
        $this->poll(['c' => (string) $id, 'a' => '1']);
        $this->assertDatabaseHas('device_commands', ['sequence' => $id, 'status' => 'failed']);
    }

    public function test_unknown_result_is_terminal_but_not_reported_as_failure(): void
    {
        $id = $this->queue('U', 'uncertain-request-01');
        $this->poll();
        $this->poll(['c' => (string) $id, 'a' => '2'])->assertContent('1,0,,,,,,,,,,');
        $this->assertDatabaseHas('device_commands', ['sequence' => $id, 'status' => 'unknown', 'result' => null]);
        $this->poll()->assertContent('1,0,,,,,,,,,,');
        $this->poll(['c' => (string) $id, 'a' => '1']);
        $this->assertDatabaseHas('device_commands', ['sequence' => $id, 'status' => 'unknown']);
    }

    public function test_historic_report_is_acknowledged_and_future_ids_do_not_collide(): void
    {
        $this->poll(['c' => '22', 'a' => '1'])->assertContent('1,0,,,,,,,,,,');
        $this->assertSame(23, $this->queue('L', 'new-request-0001'));
    }

    public function test_settings_are_confirmed_before_command_delivery(): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/devices/{$this->device->id}/settings", [
            'combined_alert_mode' => 2, 'acc_alert_mode' => 1, 'shock_level' => 8, 'service_enabled' => false,
        ])->assertOk()->assertJsonPath('settings_revision', 1);
        $id = $this->queue('L', 'settings-command');
        $this->poll(['lat' => '91', 'lon' => '51'])->assertContent('1,1,2,1,8,0,,off,,,,');
        $this->poll(['r' => '1', 'lat' => '', 'lon' => '51'])->assertContent("1,1,2,1,8,0,,off,,,{$id},L");
        $this->poll(['r' => '1', 'c' => (string) $id, 'a' => '0'])->assertContent('1,1,,,,,,,,,,');
    }

    public function test_power_loss_does_not_dispatch_a_queued_command(): void
    {
        $id = $this->queue('L', 'power-test-00001');
        $this->poll(['p' => '0'])->assertContent('1,0,,,,,,,,,,');
        $this->assertDatabaseHas('device_commands', ['sequence' => $id, 'status' => 'queued']);
    }

    public function test_duplicate_request_key_does_not_queue_twice(): void
    {
        $id = $this->queue('L', 'duplicate-key-01');
        $this->postJson("/api/devices/{$this->device->id}/commands", ['action' => 'L', 'request_key' => 'duplicate-key-01'])
            ->assertOk()->assertJsonPath('sequence', $id);
        $this->postJson("/api/devices/{$this->device->id}/commands", ['action' => 'U', 'request_key' => 'duplicate-key-01'])->assertConflict();
        $this->assertDatabaseCount('device_commands', 1);
    }

    public function test_command_counter_never_wraps(): void
    {
        $this->device->update(['last_command_sequence' => 65535]);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/devices/{$this->device->id}/commands", ['action' => 'L', 'request_key' => 'exhausted-key-01'])->assertConflict();
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_other_owners_cannot_access_or_control_a_device(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'another-test-password']);
        Sanctum::actingAs($other);
        $base = "/api/devices/{$this->device->id}";
        $this->getJson('/api/devices')->assertJsonCount(0, 'data');
        $this->getJson($base)->assertNotFound();
        $this->getJson($base.'/commands')->assertNotFound();
        $this->getJson($base.'/reports')->assertNotFound();
        $this->patchJson($base.'/settings', ['shock_level' => 3])->assertNotFound();
        $this->postJson($base.'/commands', ['action' => 'L', 'request_key' => 'other-owner-key'])->assertNotFound();
    }

    public function test_invalid_polls_do_not_create_reports(): void
    {
        $this->poll(['c' => '22', 'a' => ''])->assertUnprocessable();
        $this->poll(['c' => '', 'a' => '0'])->assertUnprocessable();
        $this->poll(['r' => '65536'])->assertUnprocessable();
        $this->poll(['i' => ['123']])->assertUnprocessable();
        $this->assertDatabaseCount('tracker_reports', 0);
    }

    public function test_missing_or_invalid_gps_does_not_block_settings_or_commands(): void
    {
        $id = $this->queue('L', 'gps-not-required-01');
        $this->poll(['lat' => '35', 'lon' => '51'])->assertContent("1,0,,,,,off,,,,{$id},L");
        $this->assertSame(35.0, $this->device->fresh()->latitude);
        $lastFix = $this->device->fresh()->last_fix_at;

        $this->poll(['c' => (string) $id, 'a' => '1', 'lat' => '91', 'lon' => '51'])->assertContent('1,0,,,,,,,,,,');
        $this->assertDatabaseHas('device_commands', ['sequence' => $id, 'status' => 'succeeded']);
        $this->assertSame(35.0, $this->device->fresh()->latitude);
        $this->assertEquals($lastFix, $this->device->fresh()->last_fix_at);

        $next = $this->queue('U', 'gps-not-required-02');
        $this->poll(['lat' => '35', 'lon' => ''])->assertContent("1,0,,,,,off,,,,{$next},U");
        $this->assertSame(35.0, $this->device->fresh()->latitude);
        $this->assertEquals($lastFix, $this->device->fresh()->last_fix_at);
    }

    public function test_zero_coordinates_remain_a_valid_fix(): void
    {
        $this->poll(['lat' => '0', 'lon' => '0'])->assertContent('1,0,,,,,,,,,,');
        $device = $this->device->fresh();
        $this->assertSame(0.0, $device->latitude);
        $this->assertSame(0.0, $device->longitude);
        $this->assertNotNull($device->last_fix_at);
    }

    public function test_gps_arrays_and_oversized_values_are_ignored(): void
    {
        $this->poll(['lat' => ['35'], 'lon' => '51'])->assertContent('1,0,,,,,,,,,,');
        $this->poll(['lat' => str_repeat('1', 64), 'lon' => '51'])->assertContent('1,0,,,,,,,,,,');
        $this->assertNull($this->device->fresh()->last_fix_at);
    }

    public function test_invalid_settings_are_rejected_and_unchanged_settings_keep_revision(): void
    {
        Sanctum::actingAs($this->owner);
        $path = "/api/devices/{$this->device->id}/settings";
        $this->patchJson($path, ['shock_level' => 0])->assertUnprocessable();
        $this->patchJson($path, ['shock_level' => null])->assertUnprocessable();
        $this->patchJson($path, [])->assertUnprocessable();
        $this->patchJson($path, ['shock_level' => 4])->assertJsonPath('settings_revision', 1);
        $this->patchJson($path, ['shock_level' => 4])->assertJsonPath('settings_revision', 1);
        $this->device->refresh()->update(['settings_revision' => 65535]);
        $this->patchJson($path, ['shock_level' => 5])->assertConflict();
    }

    public function test_login_token_and_logout(): void
    {
        $this->getJson('/api/devices')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'wrong'])->assertUnprocessable();
        $response = $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'test-password-only']);
        $response->assertOk()->assertJsonMissingPath('user.password');
        $token = $response->json('token');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
