<?php

namespace App\Enums\Flow;

enum NodeType: string
{
    case Start = 'start';
    case Message = 'message';
    case Response = 'response';
    case Status = 'status';
    case Tagging = 'tagging';
    case Condition = 'condition';
    case Action = 'action';

    public function data(): array
    {
        return match($this) {
            self::Message => [
                'body' => '',
                'message_type' => 'text', // text, image, audio, video, document
                'attachment' => null, // for non-text messages
                'delay' => 0, // delay in seconds before sending the message
            ],
            self::Response => [
                'body' => '',
                'message_type' => 'text', // text, image, audio, video, document
                'attachment' => null, // for non-text messages
                'variable_key' => '',
                'validation' => null, // e.g. "any", "number", "email", "phone"
                "error_message" => '', // message to show if validation fails
            ],
            self::Status => [
                'value' => 'open', // open, pending, resolved
            ],
            self::Tagging => [
                'tags' => [], // array of tag IDs
            ],
            self::Condition => [
                'field' => '', // e.g. "contact.custom_field"
                'operator' => 'equals', // equals, not_equals, contains, not_contains
                'value' => '', // value to compare against
            ],
            self::Action => [
                'type' => '', // e.g. "assign_agent", "add_tag", "remove_tag"
                'parameters' => [], // parameters for the action
            ],
        };
    }
}
