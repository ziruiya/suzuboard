<?php

namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\Log0Controller;
use App\Http\Controllers\V2\Diagnostics\Log0IngestController;
use Illuminate\Contracts\Routing\Registrar;

class Log0Route
{
    public function map(Registrar $router): void
    {
        $router->post('diagnostics/log0/ingest', Log0IngestController::class);

        $router->group([
            'prefix' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/log0',
            // The general audit middleware captures request bodies and client IPs.
            'middleware' => ['admin'],
        ], function ($router) {
            $router->post('entries', [Log0Controller::class, 'createEntry']);
            $router->patch('entries/{entry}', [Log0Controller::class, 'updateEntry']);
            $router->post('entries/{entry}/mappings', [Log0Controller::class, 'appendMapping']);
            $router->get('mappings/resolve', [Log0Controller::class, 'resolveNode']);
            $router->patch('events/{incident}', [Log0Controller::class, 'updateEvent']);
        });
    }
}
