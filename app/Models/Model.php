<?php

namespace Skafos\Models;

use Illuminate\Support\Facades\Schema;
use Skafos\Traits\HasAssets;
use Skafos\Traits\HasColumns;
use Skafos\Traits\MasksAttributes;
use Skafos\Traits\SilencesModelEvents;

class Model extends EncryptedModel
{
    use HasAssets, HasColumns, MasksAttributes, SilencesModelEvents;
}
