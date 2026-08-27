<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $table = 'contacts';

    protected $fillable = [
        'name',
        'email',
        'sub',
        'mes',
        'inquiry_type',
        'category',
        'company',
        'event_date',
        'quantity',
        'message',
    ];
}