<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestItem;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Departments ────────────────────────────────────────────────────
        $depts = [];
        foreach ([
            ['code' => 'IT',  'name' => 'Information Technology'],
            ['code' => 'OPS', 'name' => 'Operations'],
            ['code' => 'FIN', 'name' => 'Finance'],
            ['code' => 'HR',  'name' => 'Human Resources'],
            ['code' => 'MKT', 'name' => 'Marketing'],
        ] as $d) {
            $depts[$d['code']] = Department::create($d);
        }

        // ── 2. Users (satu per role) ──────────────────────────────────────────
        $admin = User::create([
            'name'          => 'Administrator',
            'department_id' => $depts['IT']->id,
            'employee_code' => 'EMP-0001',
            'email'         => 'admin@procurement.local',
            'password'      => Hash::make('password'),
            'role'          => User::ROLE_ADMIN,
        ]);

        $purchasing = User::create([
            'name'          => 'Staff Purchasing',
            'department_id' => $depts['IT']->id,
            'employee_code' => 'EMP-0002',
            'email'         => 'purchasing@procurement.local',
            'password'      => Hash::make('password'),
            'role'          => User::ROLE_PURCHASING,
        ]);

        $manager = User::create([
            'name'          => 'Manager',
            'department_id' => $depts['IT']->id,
            'employee_code' => 'EMP-0003',
            'email'         => 'manager@procurement.local',
            'password'      => Hash::make('password'),
            'role'          => User::ROLE_PURCHASING_MANAGER,
        ]);

        $warehouse = User::create([
            'name'          => 'Warehouse',
            'department_id' => $depts['OPS']->id,
            'employee_code' => 'EMP-0004',
            'email'         => 'warehouse@procurement.local',
            'password'      => Hash::make('password'),
            'role'          => User::ROLE_WAREHOUSE,
        ]);

        $employee = User::create([
            'name'          => 'Karyawan',
            'department_id' => $depts['FIN']->id,
            'employee_code' => 'EMP-0005',
            'email'         => 'employee@procurement.local',
            'password'      => Hash::make('password'),
            'role'          => User::ROLE_EMPLOYEE,
        ]);

        // Tambah beberapa employee di departemen berbeda
        foreach ([
            ['code' => 'EMP-0006', 'dept' => 'HR',  'email' => 'emp6@procurement.local'],
            ['code' => 'EMP-0007', 'dept' => 'MKT', 'email' => 'emp7@procurement.local'],
            ['code' => 'EMP-0008', 'dept' => 'OPS', 'email' => 'emp8@procurement.local'],
        ] as $e) {
            User::create([
                'name'          => $e['code'],
                'department_id' => $depts[$e['dept']]->id,
                'employee_code' => $e['code'],
                'email'         => $e['email'],
                'password'      => Hash::make('password'),
                'role'          => User::ROLE_EMPLOYEE,
            ]);
        }

        // Set manager_id pada beberapa departemen setelah users ada
        $depts['IT']->update(['manager_id'  => $admin->id]);
        $depts['OPS']->update(['manager_id' => $warehouse->id]);
        $depts['FIN']->update(['manager_id' => $manager->id]);

        // ── 3. Stock ──────────────────────────────────────────────────────────
        $stocks = [];
        foreach ([
            ['sku' => 'ATK-001', 'name' => 'Kertas HVS A4 80gr',     'category' => 'OFFICE_SUPPLY', 'unit' => 'Rim',   'quantity' => 50,  'min_stock' => 10],
            ['sku' => 'ATK-002', 'name' => 'Ballpoint Hitam Pilot',   'category' => 'OFFICE_SUPPLY', 'unit' => 'Lusin', 'quantity' => 20,  'min_stock' => 5],
            ['sku' => 'ATK-003', 'name' => 'Stapler Besar',           'category' => 'OFFICE_SUPPLY', 'unit' => 'Pcs',   'quantity' => 10,  'min_stock' => 3],
            ['sku' => 'ELK-001', 'name' => 'Mouse Wireless Logitech', 'category' => 'ELECTRONIC',    'unit' => 'Unit',  'quantity' => 5,   'min_stock' => 2],
            ['sku' => 'ELK-002', 'name' => 'Keyboard USB Mechanical', 'category' => 'ELECTRONIC',    'unit' => 'Unit',  'quantity' => 3,   'min_stock' => 2],
            ['sku' => 'ELK-003', 'name' => 'Headset USB',             'category' => 'ELECTRONIC',    'unit' => 'Unit',  'quantity' => 0,   'min_stock' => 2],
            ['sku' => 'FRN-001', 'name' => 'Kursi Kerja Ergonomis',   'category' => 'FURNITURE',     'unit' => 'Unit',  'quantity' => 0,   'min_stock' => 2],
            ['sku' => 'FRN-002', 'name' => 'Meja Kerja 120cm',        'category' => 'FURNITURE',     'unit' => 'Unit',  'quantity' => 2,   'min_stock' => 1],
        ] as $s) {
            $stocks[$s['sku']] = Stock::create($s);
        }

        // ── 4. Vendors ────────────────────────────────────────────────────────
        $vendors = [];
        foreach ([
            ['code' => 'VND-001', 'name' => 'PT. Maju Jaya Sejahtera',  'contact_email' => 'sales@majujaya.id',  'is_active' => true, 'rating' => 4.5],
            ['code' => 'VND-002', 'name' => 'CV. Berkah Office Supply',  'contact_email' => 'order@berkah.id',    'is_active' => true, 'rating' => 4.2],
            ['code' => 'VND-003', 'name' => 'PT. TechMitra Indonesia',   'contact_email' => 'info@techmitra.id',  'is_active' => true, 'rating' => 4.8],
        ] as $v) {
            $vendors[$v['code']] = Vendor::create($v);
        }

        // ── 5. Sample Request (DRAFT — siap untuk demo flow) ──────────────────
        $req = Request::create([
            'request_number' => 'REQ-' . now()->year . '-000001',
            'requester_id'   => $employee->id,
            'department_id'  => $employee->department_id,
            'title'          => 'Pembelian ATK Q1 ' . now()->year,
            'status'         => Request::STATUS_DRAFT,
            'priority'       => 2,
            'required_date'  => now()->addMonth()->toDateString(),
        ]);

        RequestItem::create([
            'request_id'      => $req->id,
            'stock_id'        => $stocks['ATK-001']->id,
            'item_name'       => 'Kertas HVS A4 80gr',
            'category'        => 'OFFICE_SUPPLY',
            'quantity'        => 10,
            'unit'            => 'Rim',
            'estimated_price' => 85000,
        ]);

        RequestItem::create([
            'request_id'      => $req->id,
            'stock_id'        => $stocks['ATK-002']->id,
            'item_name'       => 'Ballpoint Hitam Pilot',
            'category'        => 'OFFICE_SUPPLY',
            'quantity'        => 5,
            'unit'            => 'Lusin',
            'estimated_price' => 35000,
        ]);

        // ── Summary ───────────────────────────────────────────────────────────
        $this->command->newLine();
        $this->command->info('✅  Seeder selesai!');
        $this->command->newLine();
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                [User::ROLE_EMPLOYEE,           'employee@procurement.local',  'password'],
                [User::ROLE_PURCHASING,          'purchasing@procurement.local', 'password'],
                [User::ROLE_PURCHASING_MANAGER,  'manager@procurement.local',   'password'],
                [User::ROLE_WAREHOUSE,           'warehouse@procurement.local',  'password'],
                [User::ROLE_ADMIN,               'admin@procurement.local',      'password'],
            ]
        );
        $this->command->newLine();
        $this->command->line('Sample request: <fg=yellow>' . $req->request_number . '</> (DRAFT)');
    }
}
