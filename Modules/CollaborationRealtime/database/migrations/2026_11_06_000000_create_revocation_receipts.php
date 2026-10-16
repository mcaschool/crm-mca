<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collab_revocation_receipts', function (Blueprint $t): void {
            $t->string('event_id', 64)->charset('ascii')->collation('ascii_bin')->primary();
            $t->foreign('event_id')->references('id')->on('collab_events')->cascadeOnDelete();
            $t->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $t->timestamp('accepted_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collab_revocation_receipts');
    }
};
