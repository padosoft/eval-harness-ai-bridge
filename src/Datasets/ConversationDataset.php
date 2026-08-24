<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Datasets;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Multi-turn conversations as golden dataset rows.
 *
 * A single-turn dataset asks a question and grades an answer. It cannot express
 * the failure that costs the most in a real assistant: **the model that answers
 * turn one perfectly and forgets it by turn three.**
 *
 * ```yaml
 * name: support.multi-turn
 * conversations:
 *   - id: refund-then-address
 *     turns:
 *       - user: 'I want to return the boots I bought last week.'
 *         expect: 'asks for the order number'
 *       - user: 'It is 44192.'
 *         expect: 'confirms the 30-day window'
 *       - user: 'And can you send it to my work address instead?'
 *         expect: 'uses the address from the order, does not re-ask for the order number'
 *         tags: [context-retention]
 * ```
 *
 * ## One row per turn, carrying its history
 *
 * Each turn becomes a dataset row whose `input.history` is every turn before it.
 * That shape is deliberate, and it is the useful one:
 *
 * - **The report names the turn that broke**, not "conversation 4 failed". A
 *   pass rate over turns tells you *where* an assistant loses the thread, which
 *   is the number that leads to a fix.
 * - **Every existing metric works unchanged** — an exact match, a judge, a
 *   trajectory assertion all take a row and an answer, and a turn is a row.
 * - **Turns are independently addressable**, so the regression gate can join
 *   turn 3 of a conversation across runs by content hash the same way it joins
 *   any other row.
 *
 * The history is the *dataset's* turns, not the model's own previous answers:
 * an eval where turn 3 depends on what the model said at turn 2 measures a
 * different thing on every run, and cannot be compared to itself.
 */
final class ConversationDataset
{
    /**
     * @return list<DatasetSample>
     */
    public static function fromFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DatasetSchemaException(sprintf('Conversation YAML file is missing or unreadable: %s', $path));
        }

        return self::fromString((string) file_get_contents($path));
    }

    /**
     * @return list<DatasetSample>
     */
    public static function fromString(string $yaml): array
    {
        try {
            $decoded = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new DatasetSchemaException(
                sprintf('Conversation YAML is not valid YAML: %s', $e->getMessage()),
                previous: $e,
            );
        }

        return self::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<DatasetSample>
     */
    public static function fromArray(array $decoded): array
    {
        $conversations = $decoded['conversations'] ?? null;

        if (! is_array($conversations) || $conversations === []) {
            throw new DatasetSchemaException("Conversation YAML must declare a non-empty 'conversations' list.");
        }

        $samples = [];
        $seen = [];

        foreach ($conversations as $index => $conversation) {
            if (! is_array($conversation)) {
                throw new DatasetSchemaException(sprintf('Conversation at index %d is not an associative array.', $index));
            }

            foreach (self::turnsOf($conversation, $index, $seen) as $sample) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }

    /**
     * @param  array<mixed>  $conversation
     * @param  array<string, true>  $seen
     * @return list<DatasetSample>
     */
    private static function turnsOf(array $conversation, int $index, array &$seen): array
    {
        $conversationId = $conversation['id'] ?? null;

        if (! is_string($conversationId) || $conversationId === '') {
            throw new DatasetSchemaException(sprintf("Conversation at index %d is missing a string 'id'.", $index));
        }

        $turns = $conversation['turns'] ?? null;

        if (! is_array($turns) || $turns === []) {
            throw new DatasetSchemaException(sprintf("Conversation '%s' must declare a non-empty 'turns' list.", $conversationId));
        }

        $samples = [];
        $history = [];

        foreach (array_values($turns) as $position => $turn) {
            if (! is_array($turn)) {
                throw new DatasetSchemaException(sprintf("Turn %d of conversation '%s' is not an associative array.", $position + 1, $conversationId));
            }

            $user = $turn['user'] ?? null;

            if (! is_string($user) || $user === '') {
                throw new DatasetSchemaException(sprintf("Turn %d of conversation '%s' is missing a non-empty 'user' string.", $position + 1, $conversationId));
            }

            $expected = $turn['expect'] ?? null;

            if (! is_string($expected) || $expected === '') {
                throw new DatasetSchemaException(sprintf("Turn %d of conversation '%s' is missing a non-empty 'expect' string.", $position + 1, $conversationId));
            }

            $id = sprintf('%s#%d', $conversationId, $position + 1);

            // A duplicate conversation id would silently produce two rows with
            // the same sample id, and the report would aggregate them as one.
            if (isset($seen[$id])) {
                throw new DatasetSchemaException(sprintf("Duplicate conversation turn id '%s'.", $id));
            }

            $seen[$id] = true;

            $samples[] = new DatasetSample(
                id: $id,
                input: [
                    'question' => $user,
                    // The history is the dataset's own turns, never the model's
                    // previous answers: an eval whose input depends on what the
                    // model said last run cannot be compared to itself.
                    'history' => $history,
                ],
                expectedOutput: $expected,
                metadata: self::metadataFor($conversation, $turn, $conversationId, $position + 1),
            );

            $history[] = ['user' => $user, 'assistant' => $expected];
        }

        return $samples;
    }

    /**
     * @param  array<mixed>  $conversation
     * @param  array<mixed>  $turn
     * @return array<string, mixed>
     */
    private static function metadataFor(array $conversation, array $turn, string $conversationId, int $turnNumber): array
    {
        $tags = [];

        foreach ([$conversation['tags'] ?? [], $turn['tags'] ?? []] as $source) {
            if (is_array($source)) {
                foreach ($source as $tag) {
                    if (is_string($tag) && $tag !== '') {
                        $tags[$tag] = true;
                    }
                }
            }
        }

        $metadata = [
            'conversation_id' => $conversationId,
            'turn' => $turnNumber,
            'tags' => array_keys($tags),
        ];

        foreach ([$conversation['metadata'] ?? null, $turn['metadata'] ?? null] as $extra) {
            if (is_array($extra)) {
                /** @var array<string, mixed> $extra */
                $metadata = array_merge($metadata, $extra);
            }
        }

        return $metadata;
    }
}
