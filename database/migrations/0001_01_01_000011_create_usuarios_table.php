<?php

use App\Enums\UserType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            // Nullable: só usuários SUPERADMIN não pertencem a nenhuma empresa cliente.
            $table->foreignId('empresa_id')->nullable()->constrained('empresas');
            $table->string('nome');
            $table->string('email')->unique();
            $table->string('senha_hash');
            $table->enum('papel', array_column(UserType::cases(), 'value'));
            $table->boolean('ativo')->default(true);
            $table->text('avatar_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
