<?php

namespace App\Enums\Flow;

enum NodeType: string
{
    case Start = 'start';
    case Message = 'message';
    case Response = 'response';
    case Tagging = 'tagging';
    case Condition = 'condition';
    case Status = 'status';
    case Action = 'action';
    case AIAgent = 'ai_agent';
    case HttpRequest = 'http_request';
    case Interactive = 'interactive';

    public function data(): array
    {
        return match($this) {
            self::Message => [
                'body' => '',
                'message_type' => 'text', // text, image, audio, video, document
                'attachment' => null, // for non-text messages
                'delay' => 0, // delay in seconds before sending the message
                'wait_for_reply' => true, // true = wait for user reply before moving to next node, false = move immediately
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
                'action' => 'add', // 'add' or 'remove'
                'tags' => [], // array of tag IDs
            ],
            self::Condition => [
                'field' => '', // Field to check:
                               // - "variable.{key}" for flow state variables (e.g. "variable.user_age")
                               // - "contact.name", "contact.phone", "contact.email"
                               // - "conversation.status"
                'operator' => 'equals', // equals, not_equals, contains, not_contains, greater_than, less_than, is_empty, is_not_empty
                'value' => '', // Value to compare against (not used for is_empty/is_not_empty)
            ],
            self::Action => [
                'type' => '', // e.g. "assign_agent", "add_tag", "remove_tag"
                'parameters' => [], // parameters for the action
            ],
            self::AIAgent => [
                'ai_hub_agent_id' => null, // FK to ai_hub_agents.id
                'welcoming_message' => '', // optional: static greeting sent on first turn instead of calling AI
                'store_summary_to_variable' => '', // optional: variable key in flow state to store the run summary
                // Service-hours behaviour (gates AI vs human queue):
                //   always_ai           = AI always handles; handoff just moves to the next node
                //   handoff_in_hours    = AI handles, then hands a human the chat within service hours
                //   human_only_in_hours = within service hours skip AI entirely → human queue; AI otherwise
                'service_hours_behavior' => 'always_ai',
            ],
            // WhatsApp Official only — reply buttons / a list menu / a media
            // carousel, where every option is its own outgoing branch (edge
            // condition_value = option id).
            self::Interactive => [
                'interactive_type' => 'button', // button | list | carousel
                'header' => '', // not used by carousel — the Cloud API rejects one
                'body' => '',
                'footer' => '', // ditto
                // [{ 'id' => 'btn_ab12', 'title' => 'Yes' }] — max 3
                'buttons' => [],
                'button_label' => '', // list opener label
                // [{ 'title' => 'Section', 'rows' => [{ 'id' => 'row_ab12', 'title' => '', 'description' => '' }] }]
                'sections' => [],
                // Carousel: the button shape is picked once for the whole strip
                // (Meta requires every card to match), so it lives here.
                'card_button_type' => 'quick_reply', // quick_reply | cta_url
                // 2–10 cards: [{
                //   'header_type' => 'image'|'video', 'header_url' => '', 'body' => '',
                //   'buttons' => [{ 'id' => 'card_ab12', 'title' => '' }],  // quick_reply
                //   'button_label' => '', 'button_url' => '',               // cta_url
                // }]
                'cards' => [],
            ],
            self::HttpRequest => [
                'method' => 'GET', // GET, POST, PUT, PATCH, DELETE
                'url' => '', // supports {{variable}} interpolation from flow state
                'headers' => [], // list of { key, value } — values support {{variable}}
                'body' => null, // raw string / JSON (POST/PUT/PATCH/DELETE); supports {{variable}}
                'timeout' => 15, // seconds
                // Map parts of the JSON response into flow variables:
                //   [{ 'path' => 'data.user.name', 'variable' => 'name' }]
                //   special paths: "http_status" (status code), "raw_body" (whole body)
                'response_mappings' => [],
            ],
        };
    }
}
