<?php

use Illuminate\Support\Facades\Route;

// Home page
Route::get('/', function () {
    return view('welcome');
});
