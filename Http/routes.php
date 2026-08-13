<?php

/*
|--------------------------------------------------------------------------
| AiChatPanel routes
|--------------------------------------------------------------------------
|
| The prefix is \Helper::getSubdirectory(), not a literal string: FreeScout
| must keep working when installed in a subdirectory.
|
| Admin-only routes carry 'roles' => ['admin'], which App\Http\Middleware\CheckRole
| reads off the route action.
|
| Authorisation for the chat routes is not expressed here — it depends on the
| conversation being addressed, so it is enforced in the controller against the
| conversation id on every single request.
|
*/

/*
 * The prefix.
 *
 * \Helper::getSubdirectory() (core/app/Misc/Helper.php:1359) only reads the
 * path out of APP_URL when the host is not localhost or example.com; otherwise
 * it falls back to $_SERVER['SCRIPT_NAME']. Under HTTP that is /index.php and
 * resolves to an empty prefix, which is correct — but under CLI it is the
 * artisan or phpunit binary path, and every route would end up registered
 * under something like "./vendor/bin/phpunit/aichatpanel/...", unreachable
 * from tests and from any URL generated in a queue worker.
 *
 * On the console we therefore take the path straight out of APP_URL, which is
 * the right answer for a subdirectory install and empty for a root one.
 */
$aichatpanel_prefix = app()->runningInConsole()
    ? (string) parse_url((string) config('app.url'), PHP_URL_PATH)
    : \Helper::getSubdirectory();

$aichatpanel_prefix = trim($aichatpanel_prefix, '/');

Route::group([
    'middleware' => ['web', 'auth', 'roles'],
    'prefix'     => $aichatpanel_prefix,
    'namespace'  => 'Modules\AiChatPanel\Http\Controllers',
], function () {

    // -- Admin ------------------------------------------------------------
    Route::post('/aichatpanel/settings/test-connection', [
        'uses'    => 'SettingsController@testConnection',
        'roles'   => ['admin'],
        'laroute' => true,
    ])->name('aichatpanel.settings.test_connection');

    Route::post('/aichatpanel/settings/models', [
        'uses'    => 'SettingsController@models',
        'roles'   => ['admin'],
        'laroute' => true,
    ])->name('aichatpanel.settings.models');

    // Per-mailbox settings. Authorised in the controller with the same
    // 'update' gate core uses for its own mailbox settings pages, so a mailbox
    // manager can reach it without being a global admin.
    Route::get('/aichatpanel/mailbox/{mailbox_id}', 'MailboxSettingsController@view')
        ->name('aichatpanel.mailbox.settings');

    Route::post('/aichatpanel/mailbox/{mailbox_id}', 'MailboxSettingsController@save')
        ->name('aichatpanel.mailbox.settings.save');

    // -- Panel ------------------------------------------------------------
    // No 'roles' here: access depends on the conversation, not on a role, and
    // is checked in the controller against the conversation id on every call.
    Route::post('/aichatpanel/chat/history', 'ChatController@history')
        ->name('aichatpanel.chat.history');

    Route::post('/aichatpanel/chat/send', 'ChatController@send')
        ->name('aichatpanel.chat.send');

    Route::post('/aichatpanel/chat/confirm', 'ChatController@confirm')
        ->name('aichatpanel.chat.confirm');

    // GET because EventSource cannot POST. The token is single-use, expires in
    // minutes and is bound to the user, so this is not a way to replay a turn.
    Route::get('/aichatpanel/chat/stream/{token}', 'ChatController@stream')
        ->name('aichatpanel.chat.stream');

    // Converts one stored answer's Markdown into the HTML the reply editor
    // wants. Server-side because the panel's own bubble HTML is rendered for a
    // browser, not for a thread body: it allows <code>, <hr> and <del>, which
    // core's purifier drops the moment the draft is displayed or sent.
    Route::post('/aichatpanel/chat/editor-html', 'ChatController@editorHtml')
        ->name('aichatpanel.chat.editor_html');

    Route::post('/aichatpanel/chat/reset', 'ChatController@reset')
        ->name('aichatpanel.chat.reset');

    Route::post('/aichatpanel/prefs', 'ChatController@prefs')
        ->name('aichatpanel.prefs');
});
