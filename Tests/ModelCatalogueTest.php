<?php

namespace Modules\AiChatPanel\Tests;

use Modules\AiChatPanel\Http\Controllers\ChatController;
use Modules\AiChatPanel\Services\Llm\CurlLlmClient;

/**
 * What /v1/models says, and what the picker makes of it.
 *
 * The panel used to render the bare id in endpoint order. That is workable
 * against a llama.cpp serving one model and useless against OpenRouter, which
 * lists several hundred in no order at all while carrying a display name and a
 * tool-support flag nobody was reading.
 */
class ModelCatalogueTest extends AiChatPanelTestCase
{
    public function testOpenRouterNamesAreSplitIntoVendorAndModel()
    {
        $entry = CurlLlmClient::describeModel('anthropic/claude-sonnet-4.5', [
            'name' => 'Anthropic: Claude Sonnet 4.5',
        ]);

        $this->assertEquals('anthropic/claude-sonnet-4.5', $entry['id']);
        $this->assertEquals('Anthropic', $entry['group']);
        $this->assertEquals('Claude Sonnet 4.5', $entry['label']);
    }

    public function testTheVendorFallsBackToTheIdWhenThereIsNoName()
    {
        $entry = CurlLlmClient::describeModel('mistralai/mistral-large');

        $this->assertEquals('Mistralai', $entry['group']);
        $this->assertEquals('mistralai/mistral-large', $entry['label']);
    }

    public function testAPlainIdIsLeftUngrouped()
    {
        // What a single-model llama.cpp answers. Inventing a vendor heading for
        // it would be worse than none.
        $entry = CurlLlmClient::describeModel('qwen3-coder');

        $this->assertEquals('', $entry['group']);
        $this->assertEquals('qwen3-coder', $entry['label']);
    }

    public function testToolSupportIsReadFromSupportedParameters()
    {
        $with = CurlLlmClient::describeModel('a/b', [
            'supported_parameters' => ['temperature', 'tools', 'tool_choice'],
        ]);

        $without = CurlLlmClient::describeModel('a/c', [
            'supported_parameters' => ['temperature', 'top_p'],
        ]);

        $this->assertTrue($with['tools']);
        $this->assertFalse($without['tools']);
    }

    public function testAnEndpointThatSaysNothingAboutToolsIsNotAssumedToLackThem()
    {
        // llama.cpp and vLLM do not report supported_parameters. Reading their
        // silence as "no tools" would disable tools for the endpoints this
        // module was built against.
        $entry = CurlLlmClient::describeModel('qwen3-coder', []);

        $this->assertNull($entry['tools']);
    }

    public function testModelsAreSortedByVendorThenName()
    {
        $sorted = ChatController::sortModels([
            ['id' => 'z', 'label' => 'local-model', 'group' => '',          'tools' => null],
            ['id' => 'c', 'label' => 'GPT-5.2',     'group' => 'OpenAI',    'tools' => true],
            ['id' => 'a', 'label' => 'Claude Opus', 'group' => 'Anthropic', 'tools' => true],
            ['id' => 'b', 'label' => 'Claude Haiku', 'group' => 'Anthropic', 'tools' => true],
        ]);

        // Anthropic before OpenAI, ungrouped last, and Haiku before Opus.
        $this->assertEquals(['b', 'a', 'c', 'z'], array_column($sorted, 'id'));
    }

    public function testUngroupedModelsSortLast()
    {
        $sorted = ChatController::sortModels([
            ['id' => 'plain', 'label' => 'aaa', 'group' => '',      'tools' => null],
            ['id' => 'zed',   'label' => 'zzz', 'group' => 'Zephyr', 'tools' => null],
        ]);

        $this->assertEquals(['zed', 'plain'], array_column($sorted, 'id'));
    }

    public function testSortingIsNaturalAndCaseInsensitive()
    {
        $sorted = ChatController::sortModels([
            ['id' => 'l4',  'label' => 'Llama 31', 'group' => 'Meta', 'tools' => null],
            ['id' => 'l1',  'label' => 'llama 4',  'group' => 'Meta', 'tools' => null],
        ]);

        // "Llama 4" before "Llama 31" — a plain string sort gets this backwards.
        $this->assertEquals(['l1', 'l4'], array_column($sorted, 'id'));
    }
}
