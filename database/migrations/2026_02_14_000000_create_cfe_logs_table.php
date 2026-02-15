<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cfe_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('invoice_id')->index();
            $table->string('external_invoice_id')->nullable()->index();

            $table->string('direction')->default('sent');
            $table->string('provider_status')->nullable();
            $table->integer('provider_code')->nullable();
            $table->string('provider_description')->nullable();

            $table->integer('cfe_tipo')->nullable();
            $table->string('cfe_serie')->nullable();
            $table->integer('cfe_numero')->nullable();

            $table->string('cae_id')->nullable();
            $table->integer('cae_dnro')->nullable();
            $table->integer('cae_hnro')->nullable();
            $table->date('cae_vto')->nullable();

            $table->string('hash')->nullable();
            $table->text('link_qr')->nullable();
            $table->longText('imagen_qr_base64')->nullable();
            $table->longText('xml_firmado')->nullable();

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('trace_id')->nullable();

            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfe_logs');
    }
};
