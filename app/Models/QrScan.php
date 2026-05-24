<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QrScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'qr_code_id',
        'scanned_by',
        'branch_id',
        'action',
        'result',
        'points_affected',
        'notes',
    ];

    protected $casts = [
        'points_affected' => 'integer',
    ];

    public function qrCode()
    {
        return $this->belongsTo(QrCode::class);
    }

    public function scannedBy()
    {
        return $this->belongsTo(Admin::class, 'scanned_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}