<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()['cache']->forget(config('permission.cache.key'));

        $permissions = [
            // User Management
            'users.view', 'users.create', 'users.update', 'users.delete', 'users.suspend',
            // Courses
            'courses.view', 'courses.create', 'courses.update', 'courses.delete', 'courses.publish',
            // Quizzes
            'quizzes.view', 'quizzes.create', 'quizzes.update', 'quizzes.delete', 'quizzes.host',
            // Exams
            'exams.view', 'exams.create', 'exams.grade',
            // Certificates
            'certificates.view', 'certificates.issue', 'certificates.verify',
            // Reports
            'reports.view', 'reports.export',
            // Payments
            'payments.view', 'payments.manage', 'payments.refund',
            // Attendance
            'attendance.mark', 'attendance.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        // Roles — create for both `web` and `sanctum` guards so seeders
        // and controllers can assign either without guard-mismatch errors.
        foreach (['web', 'sanctum'] as $guard) {
            $admin = Role::firstOrCreate(['name' => 'system_admin', 'guard_name' => $guard, 'type' => 'system_admin']);
            $admin->syncPermissions(Permission::where('guard_name', $guard)->get());

            $trainer = Role::firstOrCreate(['name' => 'trainer', 'guard_name' => $guard, 'type' => 'trainer']);
            $trainer->syncPermissions(Permission::where('guard_name', $guard)->whereIn('name', [
                'courses.view', 'courses.create', 'courses.update', 'courses.publish',
                'quizzes.view', 'quizzes.create', 'quizzes.update', 'quizzes.host',
                'exams.view', 'exams.create', 'exams.grade',
                'attendance.mark', 'attendance.view',
                'reports.view',
            ])->get());

            $student = Role::firstOrCreate(['name' => 'student', 'guard_name' => $guard, 'type' => 'student']);
            $student->syncPermissions(Permission::where('guard_name', $guard)->whereIn('name', [
                'courses.view', 'quizzes.view', 'exams.view', 'certificates.view',
            ])->get());

            $corporate = Role::firstOrCreate(['name' => 'corporate_client', 'guard_name' => $guard, 'type' => 'corporate_client']);
            $corporate->syncPermissions(Permission::where('guard_name', $guard)->whereIn('name', [
                'users.view', 'courses.view', 'reports.view', 'reports.export',
            ])->get());

            // Facilitator: helps trainer run sessions but cannot create/delete quizzes
            $facilitator = Role::firstOrCreate(['name' => 'facilitator', 'guard_name' => $guard, 'type' => 'facilitator']);
            $facilitator->syncPermissions(Permission::where('guard_name', $guard)->whereIn('name', [
                'courses.view', 'quizzes.view', 'quizzes.host',
                'attendance.mark', 'attendance.view',
                'reports.view',
            ])->get());
        }
    }
}
