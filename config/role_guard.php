<?php

function require_role(string $role): void
{
    if (
        empty($_SESSION['uid']) ||
        empty($_SESSION['role'])
    ) {
        header("Location: " . _base_url() . "login.php");
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | ALLOWED ROLE
    |--------------------------------------------------------------------------
    */

    if ($_SESSION['role'] === $role) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | WRONG ROLE
    |--------------------------------------------------------------------------
    | Redirect user to their own dashboard.
    */

    $dashboards = [

        'admin'      => 'admin/dashboard.php',

        'owner'      => 'owner/dashboard.php',

        'technician' => 'maintenance/dashboard.php',

        'inspector'  => 'inspector/dashboard.php',

    ];

    $target =
        $dashboards[$_SESSION['role']]
        ?? 'login.php';

    header(
        "Location: " .
        _base_url() .
        $target
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| BASE URL
|--------------------------------------------------------------------------
|
| Returns relative path prefix back to project root
| based on current PHP script depth.
|
*/

function _base_url(): string
{
    $depth =
        substr_count(
            $_SERVER['PHP_SELF'],
            '/'
        ) - 1;

    return
        $depth > 0
            ? str_repeat('../', $depth)
            : '';
}