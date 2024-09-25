<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonalNotepad extends Model
{
    use HasFactory;
    protected $table = 'personal_notepad';
    protected $guarded = [];
}
