<?php

/*
|--------------------------------------------------------------------------
| Register Namespaces And Routes
|--------------------------------------------------------------------------
|
| Loaded automatically when the module starts (declared in module.json under
| "files"). The module has no vendor/ of its own — its classes autoload
| through the core's "Modules\\" PSR-4 mapping.
|
*/

if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}
