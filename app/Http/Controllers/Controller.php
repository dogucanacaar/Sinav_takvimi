<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Policy kontrollerinin ($this->authorize) denetleyicilerde
    // kullanılabilmesi için gerekli.
    use AuthorizesRequests;
}
