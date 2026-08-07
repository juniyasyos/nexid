<?php

namespace Database\Seeders;

use App\Domain\Iam\Models\Application;
use App\Domain\Iam\Models\ApplicationRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class Template1Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get admin user untuk created_by (mengikuti pola ApplicationsSeeder)
        $admin = User::where('nip', '0000.00000')->first();

        // 1. App Client (first create or update)
        $app = Application::updateOrCreate(
            ['app_key' => 'template-1'],
            [
                'name' => 'Template 1',
                'description' => 'Aplikasi Template 1',
                'enabled' => true,
                'redirect_uris' => ['http://127.0.0.1:8400'],
                'callback_url' => 'http://127.0.0.1:8400/sso/callback',
                'backchannel_url' => 'http://127.0.0.1:8400',
                'secret' => 'template1_secret_key',
                'logo_url' => null,
                'token_expiry' => 3600,
                'created_by' => $admin?->id,
            ]
        );

        $this->command->info("App Client 'template-1' seeded/updated successfully!");

        // 2. Roles (first create)
        $roles = [
            [
                'slug' => 'super-admin',
                'name' => 'Super Admin',
                'description' => 'Hak akses penuh sebagai Super Admin untuk Template 1',
                'is_system' => true,
            ],
            [
                'slug' => 'kepala-departemen',
                'name' => 'Kepala Departemen',
                'description' => 'Role sebagai Kepala Departemen',
                'is_system' => false,
            ],
            [
                'slug' => 'perawat',
                'name' => 'Perawat',
                'description' => 'Role sebagai Perawat',
                'is_system' => false,
            ],
        ];

        foreach ($roles as $roleData) {
            ApplicationRole::firstOrCreate(
                [
                    'application_id' => $app->id,
                    'slug' => $roleData['slug'],
                ],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                    'is_system' => $roleData['is_system'] ?? false,
                ]
            );
        }

        $this->command->info("Roles for 'template-1' (super-admin, kepala-ruangan, perawat) seeded successfully!");
    }
}
