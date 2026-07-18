<?php
// app/Models/Notification.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'notifiable_type', 'notifiable_id',
        'title', 'body', 'type', 'data', 'read', 'read_at',
    ];

    protected $casts = [
        'data'    => 'array',
        'read'    => 'boolean',
        'read_at' => 'datetime',
    ];
}