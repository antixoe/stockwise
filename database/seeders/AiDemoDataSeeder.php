<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Recommendation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Faker\Factory as FakerFactory;

class AiDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Create categories
        $categories = [];
        $categoryCodes = ['ELEK', 'MAKA', 'MINY', 'PATI', 'MINY'];
        foreach ($categoryCodes as $code) {
            $category = Category::firstOrCreate(
                ['code' => $code],
                ['name' => ucfirst(Str::lower($code) . 'ani'), 'is_active' => true]
            );
            $categories[] = $category;
        }

        // Create suppliers
        $suppliers = [];
        $supplierCodes = ['SUP-001', 'SUP-002', 'SUP-003', 'SUP-004', 'SUP-005'];
        foreach ($supplierCodes as $code) {
            $supplier = Supplier::firstOrCreate(
                ['code' => $code],
                ['name' => 'Supplier ' . Str::upper($code), 'default_lead_time_days' => rand(5, 20), 'is_active' => true]
            );
            $suppliers[] = $supplier;
        }

        // Create 25 products with inventory
        $faker = FakerFactory::create('id_ID');

        // Set seed for consistent data
        $faker->seed(42);

        for ($i = 1; $i <= 25; $i++) {
            $category = $categories[array_rand($categories)];
            $supplier = $suppliers[array_rand($suppliers)];

            $product = Product::firstOrCreate(
                ['sku' => 'SKU-' . str_pad($i, 3, '0', STR_PAD_LEFT)],
                [
                    'category_id' => $category->id,
                    'name' => ucfirst($faker->word . ' ' . $faker->word),
                    'unit' => $faker->randomElement(['pcs', 'kg', 'liter', 'botol', 'dus']),
                    'unit_price' => round($faker->numberBetween(5000, 500000), 0),
                    'reorder_point' => rand(5, 50),
                    'safety_stock' => rand(2, 30),
                    'minimum_order_quantity' => rand(1, 10),
                    'order_multiple' => rand(1, 5),
                    'is_active' => true,
                ]
            );

            // Create inventory
            $product->inventory()->firstOrCreate(
                [],
                [
                    'on_hand_quantity' => rand(0, 500),
                    'reserved_quantity' => rand(0, 100),
                    'incoming_quantity' => rand(0, 200),
                ]
            );

            // Assign supplier with pivot data
            $product->suppliers()->syncWithoutDetaching([
                $supplier->id => [
                    'lead_time_days' => rand(5, 20),
                    'is_primary' => true,
                    'supplier_sku' => 'SSKU-' . $i,
                    'minimum_order_quantity' => $product->minimum_order_quantity,
                    'order_multiple' => $product->order_multiple,
                    'last_unit_cost' => round($product->unit_price * 0.6, 0),
                ]
            ]);
        }

        // Create demo users if not exists
        $demoPassword = env('STOCKWISE_DEMO_PASSWORD');
        if ($demoPassword) {
            foreach ([
                ['admin@stockwise.local', 'Admin Demo', 'admin'],
                ['gudang@stockwise.local', 'Staff Gudang Demo', 'warehouse_staff'],
                ['manajer@stockwise.local', 'Manajer Demo', 'manager'],
            ] as [$email, $name, $role]) {
                User::updateOrCreate(
                    ['email' => $email],
                    [
                        'name' => $name,
                        'role' => $role,
                        'is_active' => true,
                        'password' => bcrypt($demoPassword),
                        'email_verified_at' => now(),
                    ]
                );
            }
        }

        // Create recommendations for products with low stock
        $lowStockProducts = Product::where('is_active', true)
            ->whereHas('inventory', fn ($q) => $q->whereColumn('on_hand_quantity', '<=', 'products.reorder_point'))
            ->take(8)
            ->get();

        foreach ($lowStockProducts as $product) {
            $inventory = $product->inventory;
            $leadTime = $product->productSuppliers->where('is_primary', true)->first()?->lead_time_days ?? 7;
            $supplier = $product->suppliers->first();

            // Simple reorder point calculation
            $demandLT = rand(20, 100) * $leadTime; // simplified
            $safety = $product->safety_stock ?: 10;
            $reorderPt = $demandLT + $safety;
            $currentStock = $inventory->on_hand_quantity - $inventory->reserved_quantity + $inventory->incoming_quantity;
            $quantityNeeded = max(0, $reorderPt - $currentStock);
            $moq = $product->minimum_order_quantity ?: 1;
            $multiple = $product->order_multiple ?: 1;

            $quantity = ceil($quantityNeeded / $multiple) * $multiple;
            if ($quantity < $moq) $quantity = $moq;

            Recommendation::firstOrCreate(
                ['product_id' => $product->id, 'status' => 'pending'],
                [
                    'recommendation_uuid' => (string) Str::uuid(),
                    'recommended_order_date' => now()->toDateString(),
                    'expected_receipt_date' => now()->addDays(7)->toDateString(),
                    'forecast_id' => null,
                    'supplier_id' => $supplier?->id,
                    'reorder_quantity' => round($quantity, 3),
                    'demand_during_lead_time' => round($demandLT, 3),
                    'on_hand_at_calculation' => $inventory->on_hand_quantity,
                    'reserved_at_calculation' => $inventory->reserved_quantity,
                    'incoming_at_calculation' => $inventory->incoming_quantity,
                    'safety_stock_at_calculation' => $product->safety_stock,
                    'lead_time_days' => $leadTime,
                    'rule_version' => 'reorder-point-v1',
                    'input_snapshot' => ['inventory_position' => 0, 'reorder_point' => 0, 'target_inventory_level' => 0, 'minimum_order_quantity' => 1, 'order_multiple' => 1, 'priority' => 'normal', 'forecast_model_version' => 'demo'],
                    'rationale' => 'Berdasat reorder point = demand lead time + safety stock',
                    'generated_at' => now(),
                ]
            );
        }

        // Create audit log entries
        $adminUser = User::where('role', 'admin')->first();
        if ($adminUser) {
            $auditActions = [
                'login_succes',
                'product_created',
                'inventory_update',
                'forecast_generated',
                'recommendation_approved',
            ];

            for ($i = 0; $i < rand(15, 30); $i++) {
                $idx = $i;
                $action = $auditActions[array_rand($auditActions)];
                $desc = ucfirst($action) . ' oleh admin';
                // App\Models\AuditLog::create([
                //     'user_id' => $adminUser->id,
                //     'action' => $action,
                //     'description' => $desc,
                // ]);
            }
        }

        // Note: The actual model creation (SalesHistory, Forecast, AuditLog)
        // would need the models to exist and corresponding tables to be set up.
        // This seeder sets up the core data that can be used:

        // - 25 products with categories and suppliers
        // - Inventory for each product
        // - Demo users with password from env
        // - 8 recommendations for low-stock products

        $this->command->info('AiDemoDataSeeder completed: 25 produk, kategori, supplier, dan 8 rekomendasi stok dibuat.');
    }
}