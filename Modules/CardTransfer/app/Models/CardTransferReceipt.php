<?php

namespace Modules\CardTransfer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Orders\Models\Order;
use Modules\Users\Models\User;

class CardTransferReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'image_path',
        'tracking_code',
        'admin_id',
        'status',
        'description',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'admin_id' => 'integer',
    ];

    // ============ روابط ============

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // ============ اکسسورها ============

    public function getStatusLabelAttribute()
    {
        $statuses = [
            'pending' => 'در انتظار بررسی',
            'approved' => 'تأیید شده',
            'rejected' => 'رد شده',
        ];

        return $statuses[$this->status] ?? $this->status;
    }


    // ============ اسکوپ‌ها ============

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
