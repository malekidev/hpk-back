<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BridgeTest extends TestCase
{
    private function request(array $data = [], string $method = 'GET', ?string $query = null): array
    {
        $data = array_replace([
            'i' => '123456789012345', 'r' => '0', 'c' => '', 'a' => '',
            'p' => '1', 'lat' => '', 'lon' => '',
        ], $data);
        $script = '$_GET = '.var_export($data, true).';'
            .'$_SERVER["REQUEST_METHOD"] = '.var_export($method, true).';'
            .'$_SERVER["QUERY_STRING"] = '.var_export($query ?? http_build_query($data), true).';'
            .'ob_start(); register_shutdown_function(function () { $body = ob_get_clean(); echo json_encode(["status" => http_response_code(), "body" => $body]); });'
            .'require '.var_export(dirname(__DIR__, 2).'/bridge/public/index.php', true).';';
        $process = new Process([PHP_BINARY, '-r', $script], null, [
            'TRACKER_API_URL' => false,
            'TRACKER_BRIDGE_TOKEN' => false,
        ]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_missing_configuration_fails_closed_without_ack(): void
    {
        $this->assertSame(['status' => 503, 'body' => 'BRIDGE_NOT_CONFIGURED'], $this->request());
    }

    public function test_only_get_is_accepted(): void
    {
        $this->assertSame(405, $this->request([], 'POST')['status']);
    }

    public function test_oversize_query_is_rejected(): void
    {
        $this->assertSame(414, $this->request([], 'GET', str_repeat('x', 2049))['status']);
    }

    public function test_arrays_invalid_imei_and_invalid_revision_are_rejected(): void
    {
        $this->assertSame(422, $this->request(['i' => ['123']])['status']);
        $this->assertSame(422, $this->request(['i' => 'IMEI'])['status']);
        $this->assertSame(422, $this->request(['r' => '65536'])['status']);
    }

    public function test_reports_require_a_valid_id_and_result_pair(): void
    {
        $this->assertSame(422, $this->request(['c' => '22'])['status']);
        $this->assertSame(422, $this->request(['a' => '0'])['status']);
        $this->assertSame(422, $this->request(['c' => '0', 'a' => '1'])['status']);
        $this->assertSame(503, $this->request(['c' => '22', 'a' => '0'])['status']);
    }

    public function test_gps_coordinates_do_not_block_command_reports(): void
    {
        $this->assertSame(503, $this->request(['lat' => '35'])['status']);
        $this->assertSame(503, $this->request(['lat' => '35', 'lon' => '181'])['status']);
        $this->assertSame(503, $this->request(['lat' => ['35'], 'lon' => '51', 'c' => '22', 'a' => '1'])['status']);
        $this->assertSame(503, $this->request(['lat' => str_repeat('1', 40), 'lon' => '51'])['status']);
        $this->assertSame(503, $this->request(['lat' => '0', 'lon' => '0'])['status']);
    }
}
