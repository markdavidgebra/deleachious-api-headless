<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'branch_id',
        'action',
        'module',
        'description',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    // Who did the action
    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    // Which branch
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // The model that was affected
    public function auditable()
    {
        return $this->morphTo();
    }
}