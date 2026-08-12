<?php

namespace Modules\AiChatPanel\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\AiChatPanel\Services\ConnectionTester;
use Modules\AiChatPanel\Services\Llm\CurlLlmClient;
use Modules\AiChatPanel\Services\Llm\LlmException;
use Modules\AiChatPanel\Services\Settings;

/**
 * Admin-only endpoints behind the global settings page.
 *
 * The API key never travels to the browser and never comes back from it: both
 * actions read the stored key server-side. The settings form may post an
 * as-yet-unsaved base URL so "Test connection" works before saving, but the key
 * is always the stored one.
 */
class SettingsController extends Controller
{
    /**
     * Probe the endpoint and report what actually happened.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testConnection(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '',
        ];

        try {
            $client = $this->client($request);
        } catch (LlmException $e) {
            $response['msg'] = $e->userMessage();

            return \Response::json($response);
        }

        try {
            $tester = new ConnectionTester($client);
            $result = $tester->run((string) $request->input('model', ''));

            $response['status'] = 'success';
            $response['result'] = $result;
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] Connection test failed: ');
            $response['msg'] = __('The connection test failed unexpectedly. See the application log for details.');
        }

        return \Response::json($response);
    }

    /**
     * Model ids the endpoint offers, for the allowlist helper in the settings UI.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function models(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '',
        ];

        try {
            $models = $this->client($request)->models();

            $response['status'] = 'success';
            $response['models'] = $models;

            if (!$models) {
                $response['msg_success'] = __('The endpoint does not list models. Enter a model name manually.');
            }
        } catch (LlmException $e) {
            $response['msg'] = $e->userMessage();
            $response['detail'] = $e->getBodyExcerpt();
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] Listing models failed: ');
            $response['msg'] = __('Could not list models. See the application log for details.');
        }

        return \Response::json($response);
    }

    /**
     * A client for the URL currently in the form, so the admin can test before
     * saving. The key is always the stored one.
     *
     * @param Request $request
     *
     * @return CurlLlmClient
     *
     * @throws LlmException
     */
    protected function client(Request $request)
    {
        $base_url = trim((string) $request->input('base_url', ''));

        if (!$base_url) {
            return CurlLlmClient::fromSettings();
        }

        $base_url = rtrim($base_url, '/');
        $base_url = rtrim(preg_replace('#/v1$#', '', $base_url), '/');

        if (!preg_match('#^https?://#i', $base_url)) {
            throw new LlmException(
                LlmException::TYPE_NOT_CONFIGURED,
                'Base URL must start with http:// or https://'
            );
        }

        return new CurlLlmClient(
            $base_url,
            Settings::apiKey(),
            (int) Settings::get('request_timeout'),
            (int) Settings::get('connect_timeout')
        );
    }
}
