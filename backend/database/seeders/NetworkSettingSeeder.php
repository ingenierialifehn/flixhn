<?php

namespace Database\Seeders;

use App\Models\ServerSetting;
use Illuminate\Database\Seeder;

class NetworkSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultNetworkSettings = [
            'lan_networks' => [
                'value' => '10.0.0.0/8, 192.168.0.0/16, 172.16.0.0/16, 180.80.8.0/24',
                'type' => 'string',
            ],
            'local_ip_address' => [
                'value' => '',
                'type' => 'string',
            ],
            'local_http_port' => [
                'value' => 8789,
                'type' => 'integer',
            ],
            'local_https_port' => [
                'value' => 8920,
                'type' => 'integer',
            ],
            'allow_remote_connections' => [
                'value' => true,
                'type' => 'boolean',
            ],
            'remote_ip_filter' => [
                'value' => '',
                'type' => 'string',
            ],
            'remote_ip_filter_mode' => [
                'value' => 'Whitelist',
                'type' => 'string',
            ],
            'public_http_port' => [
                'value' => 8789,
                'type' => 'integer',
            ],
            'public_https_port' => [
                'value' => 8920,
                'type' => 'integer',
            ],
            'external_domain' => [
                'value' => '',
                'type' => 'string',
            ],
            'read_proxy_headers' => [
                'value' => 'Only when they contain remote network addresses',
                'type' => 'string',
            ],
            'custom_ssl_cert_path' => [
                'value' => '',
                'type' => 'string',
            ],
            'certificate_password' => [
                'value' => '',
                'type' => 'string',
            ],
            'secure_connection_mode' => [
                'value' => 'Disabled',
                'type' => 'string',
            ],
            'enable_upnp' => [
                'value' => false,
                'type' => 'boolean',
            ],
            'max_simultaneous_streams' => [
                'value' => 'Unlimited',
                'type' => 'string',
            ],
            'internet_streaming_bitrate_limit' => [
                'value' => '',
                'type' => 'string',
            ],
        ];

        foreach ($defaultNetworkSettings as $key => $config) {
            ServerSetting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => is_bool($config['value']) ? ($config['value'] ? '1' : '0') : (string) $config['value'],
                    'group' => 'network',
                    'type' => $config['type'],
                ]
            );
        }
    }
}
