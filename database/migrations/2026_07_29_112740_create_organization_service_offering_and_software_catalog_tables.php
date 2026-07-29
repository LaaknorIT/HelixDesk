<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('vendor_name')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->text('partner_notes')->nullable();
            $table->string('external_reference')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('software_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('software_product_id')->constrained('software_products')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('environment')->nullable();
            $table->string('status')->default('active');
            $table->string('external_tenant_id')->nullable();
            $table->string('admin_url')->nullable();
            $table->text('notes')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id']);
            $table->index(['tenant_id', 'software_product_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('software_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('software_deployment_id')->constrained('software_deployments')->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('product_code')->nullable();
            $table->string('license_type')->nullable();
            $table->string('metric')->nullable();
            $table->unsignedInteger('seat_count')->nullable();
            $table->unsignedInteger('seats_used')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->decimal('cost_amount', 12, 2)->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->string('billing_period')->nullable();
            $table->string('vendor_contract_reference')->nullable();
            $table->string('purchase_order_reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'software_deployment_id']);
            $table->index(['tenant_id', 'expires_on']);
        });

        Schema::create('organization_service_offering', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('service_offering_id')->constrained('service_offerings')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'service_offering_id'], 'org_service_offering_uq');
            $table->index(['service_offering_id']);
        });

        Schema::create('service_offering_software_deployment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_offering_id')->constrained('service_offerings')->cascadeOnDelete();
            $table->foreignId('software_deployment_id')->constrained('software_deployments')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_offering_id', 'software_deployment_id'], 'so_software_deployment_uq');
            $table->index(['software_deployment_id']);
        });

        Schema::create('software_deployment_asset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('software_deployment_id')->constrained('software_deployments')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('role')->default('related');
            $table->timestamps();

            $table->unique(['software_deployment_id', 'asset_id', 'role'], 'sw_deploy_asset_role_uq');
            $table->index(['asset_id']);
        });

        if (Schema::hasColumn('service_offerings', 'organization_id')) {
            $rows = DB::table('service_offerings')
                ->select(['id', 'organization_id'])
                ->whereNotNull('organization_id')
                ->get();

            $now = now();

            foreach ($rows as $row) {
                DB::table('organization_service_offering')->insertOrIgnore([
                    'organization_id' => $row->organization_id,
                    'service_offering_id' => $row->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // SQLite cannot ALTER DROP a column that still has a FK; drop FK (rebuilds table) first.
            Schema::table('service_offerings', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'organization_id']);
            });

            Schema::table('service_offerings', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
            });

            Schema::table('service_offerings', function (Blueprint $table) {
                $table->dropColumn('organization_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_offerings', function (Blueprint $table) {
            if (! Schema::hasColumn('service_offerings', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            }
        });

        $pivots = DB::table('organization_service_offering')
            ->orderBy('id')
            ->get()
            ->groupBy('service_offering_id');

        foreach ($pivots as $serviceOfferingId => $links) {
            $first = $links->first();
            DB::table('service_offerings')
                ->where('id', $serviceOfferingId)
                ->update(['organization_id' => $first->organization_id]);
        }

        Schema::dropIfExists('software_deployment_asset');
        Schema::dropIfExists('service_offering_software_deployment');
        Schema::dropIfExists('organization_service_offering');
        Schema::dropIfExists('software_licenses');
        Schema::dropIfExists('software_deployments');
        Schema::dropIfExists('software_products');
    }
};
