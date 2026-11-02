<?php

namespace App\Console\Commands;

use App\Enums\StaffType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * `php artisan zon:create-superadmin` — the first account of the admin panel (the others are created inside it).
 * The password is asked for, never passed on the command line (it would stay in the shell history). On first
 * sign-in the panel requires an authenticator app before anything else.
 */
class CreateSuperadmin extends Command
{
    protected $signature = 'zon:create-superadmin {email} {--name=المدير العام}';

    protected $description = 'Creates the superadmin of the admin panel';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->secret('كلمة السر (12 حرفاً على الأقل)');
        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->create([
            'name' => (string) $this->option('name'),
            'email' => $email,
            'password' => $password,
            'type' => StaffType::Superadmin,
            'is_active' => true,
        ]);
        $this->info("أُنشئ المشرف العام {$email}. سيُطلب ربط تطبيق المصادقة عند أول دخول إلى /admin.");

        return self::SUCCESS;
    }
}
