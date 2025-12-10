<?php


require "../../index.php";

use Illuminate\Support\Facades\Cache;

$XAU_USD_data = Cache::store('redis')->get('swap:XAU_USD_data');

print_r($XAU_USD_data);
var_dump($XAU_USD_data['Current']);
