<?php

namespace Modules\Contact\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Contact\Database\Factories\ContactFactory;

class Contact extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'full_name',
        'subject',
        'email',
        'mobile',
        'body'
    ];
    protected $table = "contact";
}
