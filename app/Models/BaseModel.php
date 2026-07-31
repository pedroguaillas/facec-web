<?php

namespace App\Models;

use App\BranchScope;
use Illuminate\Database\Eloquent\Model;

class BaseModel extends Model
{
    use BranchScope;
}
