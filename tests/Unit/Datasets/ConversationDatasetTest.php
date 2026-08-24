<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Tests\Unit\Datasets;

use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Padosoft\EvalHarnessAiBridge\Datasets\ConversationDataset;
use PHPUnit\Framework\TestCase;

final class ConversationDatasetTest extends TestCase
{
    /**
     * The failure that costs the most in a real assistant is the model that
     * answers turn one perfectly and forgets it by turn three, and a
     * single-turn dataset cannot express it.
     */
    public function test_each_turn_becomes_its_own_row(): void
    {
        $samples = ConversationDataset::fromString($this->yaml());

        $this->assertCount(3, $samples);
        $this->assertSame('refund-then-address#1', $samples[0]->id);
        $this->assertSame('refund-then-address#3', $samples[2]->id);
        $this->assertSame('And can you send it to my work address?', $samples[2]->input['question']);
    }

    /**
     * A pass rate over turns says *where* an assistant loses the thread, which
     * is the number that leads to a fix.
     */
    public function test_a_turn_carries_every_turn_before_it(): void
    {
        $samples = ConversationDataset::fromString($this->yaml());

        $this->assertSame([], $samples[0]->input['history']);
        $this->assertCount(2, $samples[2]->input['history']);
        $this->assertSame('I want to return the boots.', $samples[2]->input['history'][0]['user']);
        $this->assertSame('asks for the order number', $samples[2]->input['history'][0]['assistant']);
    }

    /**
     * The history is the dataset's turns, not the model's own answers: an eval
     * whose input depends on what the model said last run measures a different
     * thing every time and cannot be compared to itself.
     */
    public function test_the_history_holds_the_expected_answers_not_the_models(): void
    {
        $samples = ConversationDataset::fromString($this->yaml());

        foreach ($samples[2]->input['history'] as $turn) {
            $this->assertArrayHasKey('assistant', $turn);
            $this->assertIsString($turn['assistant']);
        }
    }

    public function test_conversation_and_turn_tags_are_merged_into_cohorts(): void
    {
        $samples = ConversationDataset::fromString($this->yaml());

        $this->assertSame(['returns'], $samples[0]->metadata['tags']);
        $this->assertSame(['returns', 'context-retention'], $samples[2]->metadata['tags']);
        $this->assertSame('refund-then-address', $samples[2]->metadata['conversation_id']);
        $this->assertSame(3, $samples[2]->metadata['turn']);
    }

    public function test_extra_metadata_travels_from_both_levels(): void
    {
        $samples = ConversationDataset::fromString(<<<'YAML'
        conversations:
          - id: c1
            metadata:
              channel: chat
            turns:
              - user: 'hello'
                expect: 'greets back'
                metadata:
                  difficulty: easy
        YAML);

        $this->assertSame('chat', $samples[0]->metadata['channel']);
        $this->assertSame('easy', $samples[0]->metadata['difficulty']);
    }

    public function test_a_file_with_no_conversations_is_refused(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("non-empty 'conversations' list");

        ConversationDataset::fromString("name: empty\nconversations: []\n");
    }

    public function test_a_conversation_without_an_id_is_refused(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("missing a string 'id'");

        ConversationDataset::fromString("conversations:\n  - turns:\n      - user: hi\n        expect: hello\n");
    }

    public function test_a_turn_without_an_expectation_is_refused(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("Turn 2 of conversation 'c1' is missing a non-empty 'expect'");

        ConversationDataset::fromString(<<<'YAML'
        conversations:
          - id: c1
            turns:
              - user: 'hello'
                expect: 'greets back'
              - user: 'and then?'
        YAML);
    }

    /**
     * Two conversations sharing an id would produce two rows with the same
     * sample id, and the report would aggregate them as one.
     */
    public function test_duplicate_conversation_ids_are_refused(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("Duplicate conversation turn id 'c1#1'");

        ConversationDataset::fromString(<<<'YAML'
        conversations:
          - id: c1
            turns:
              - user: 'hello'
                expect: 'greets back'
          - id: c1
            turns:
              - user: 'hello again'
                expect: 'greets back again'
        YAML);
    }

    public function test_invalid_yaml_is_refused_with_the_parser_message(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('not valid YAML');

        ConversationDataset::fromString("conversations:\n  - id: c1\n   turns: [");
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('missing or unreadable');

        ConversationDataset::fromFile('/nowhere/at/all.yaml');
    }

    public function test_it_loads_from_a_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'conv').'.yaml';
        file_put_contents($path, $this->yaml());

        try {
            $this->assertCount(3, ConversationDataset::fromFile($path));
        } finally {
            @unlink($path);
        }
    }

    private function yaml(): string
    {
        return <<<'YAML'
        name: support.multi-turn
        conversations:
          - id: refund-then-address
            tags: [returns]
            turns:
              - user: 'I want to return the boots.'
                expect: 'asks for the order number'
              - user: 'It is 44192.'
                expect: 'confirms the 30-day window'
              - user: 'And can you send it to my work address?'
                expect: 'uses the address from the order'
                tags: [context-retention]
        YAML;
    }
}
