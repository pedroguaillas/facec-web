<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherType extends Model
{
    protected $fillable = [
        'slug', 'module', 'code_sri', 'name', 'abbreviation',
        'requires_electronic', 'requires_sequence', 'active',
    ];

    protected $casts = [
        'requires_electronic' => 'boolean',
        'requires_sequence' => 'boolean',
        'active' => 'boolean',
    ];
}
