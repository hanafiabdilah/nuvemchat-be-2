<?php

namespace App\Services\Mcp\Tools;

use RuntimeException;

/**
 * A tool refused to do the thing, for a reason the model can act on.
 *
 * ⚠️ This becomes `isError: true` in the *result*, not a JSON-RPC error. The
 * distinction is the whole point: a JSON-RPC error says the request was
 * malformed and the model is unlikely to recover, while a tool error is
 * feedback — "node 'ask_name' cannot be reached from the start node" is
 * something a model fixes and retries, and clients are told to hand these
 * straight to it.
 *
 * So the message is written for a model: say what is wrong and, where there is
 * one, what to do instead. Never an exception class name, never an id on its own.
 */
class ToolException extends RuntimeException
{
    /**
     * @param  list<string>  $problems  each a complete sentence the model can act on
     */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }

    public function text(): string
    {
        if ($this->problems === []) {
            return $this->getMessage();
        }

        return $this->getMessage()."\n\n- ".implode("\n- ", $this->problems);
    }
}
