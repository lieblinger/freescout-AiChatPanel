<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Services\Llm\LlmException;

/**
 * What the panel says when the endpoint refuses.
 *
 * "The AI endpoint returned an error (400)" is not something an agent can act
 * on and not something an administrator can debug from a screenshot. The
 * endpoint's own wording names the parameter it rejected, so it belongs in the
 * message rather than only on the exception.
 */
class EndpointErrorTest extends AiChatPanelTestCase
{
    public function testTheEndpointsOwnMessageIsShownAlongsideTheStatusCode()
    {
        $body = '{"error":{"message":"Invalid \'tools[0].function.name\': string does not match pattern.","code":400}}';

        $e = LlmException::fromHttp(400, $body);

        $this->assertEquals(LlmException::TYPE_HTTP, $e->getType());
        $this->assertStringContainsString('does not match pattern', $e->apiMessage());
        $this->assertStringContainsString('400', $e->userMessage());
        $this->assertStringContainsString('does not match pattern', $e->userMessage());
    }

    public function testABareMessageKeyIsUnderstoodToo()
    {
        $e = LlmException::fromHttp(400, '{"message":"context shift is disabled"}');

        $this->assertEquals('context shift is disabled', $e->apiMessage());
    }

    public function testANonJsonBodyStillProducesSomethingReadable()
    {
        $e = LlmException::fromHttp(400, "Bad Request\n  \n  upstream rejected the payload");

        $this->assertEquals('Bad Request upstream rejected the payload', $e->apiMessage());
    }

    public function testAnErrorPageIsNotPastedIntoThePanel()
    {
        // A proxy in front of the endpoint answers with a whole HTML document.
        // None of it is useful and all of it would be shown to an agent.
        $e = LlmException::fromHttp(502, '<html><body><h1>502 Bad Gateway</h1></body></html>');

        $this->assertEquals('', $e->apiMessage());
        $this->assertStringContainsString('502', $e->userMessage());
    }

    public function testALongMessageIsTruncated()
    {
        $e = LlmException::fromHttp(400, \Helper::jsonEncodeSafe([
            'error' => ['message' => str_repeat('x', 900)],
        ]));

        $this->assertLessThan(320, mb_strlen($e->apiMessage()));
        $this->assertStringEndsWith('…', $e->apiMessage());
    }

    public function testTheClassifiedTypesStillWin()
    {
        // A message worth reading must not downgrade a well-classified error to
        // the generic one: "rejected the API key" is more actionable than
        // whatever wording the endpoint chose.
        $auth = LlmException::fromHttp(401, '{"error":{"message":"No auth credentials found"}}');

        $this->assertEquals(LlmException::TYPE_AUTH, $auth->getType());
        $this->assertEquals('No auth credentials found', $auth->apiMessage());
        $this->assertStringNotContainsString('No auth credentials', $auth->userMessage());
    }

    public function testAnErrorWithoutAnyBodyKeepsTheOldWording()
    {
        $e = LlmException::fromHttp(500, '');

        $this->assertEquals('', $e->apiMessage());
        $this->assertStringContainsString('500', $e->userMessage());
    }
}
