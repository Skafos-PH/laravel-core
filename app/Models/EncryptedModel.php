<?php

namespace Skafos\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Skafos\Traits\EncryptsAttributes;

class EncryptedModel extends BaseModel
{
    use EncryptsAttributes;
}
