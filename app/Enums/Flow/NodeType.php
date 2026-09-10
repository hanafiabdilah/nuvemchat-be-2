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
    case Payment = 'payment';
    case Pixel = 'pixel';
    case GoToFlow = 'go_to_flow';
    case Lead = 'lead';

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
            // Closes the conversation. Holds a status value rather than a bare
            // flag because the column it writes is `conversations.status` — but
            // Resolved is the only one on offer, and the other three are
            // refusals rather than omissions: Active without an assignee is a
            // thread that has left the queue and belongs to nobody, Pending is
            // where a flow already runs, and AiHandling is the engine's own
            // bookkeeping. See FlowExecutor::executeStatusNode.
            self::Status => [
                'value' => 'resolved',
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
            // Does something to the conversation itself. `type` is one of
            // App\Services\Flow\ActionNodes::TYPES and starts as null on
            // purpose: a node is auto-saved the moment it lands on the canvas,
            // and one that transferred every customer to a human before its
            // author had picked anything would be a side effect nobody asked
            // for. The executor skips an unconfigured node.
            //
            // `parameters` by type:
            //   assign_agent   => ['agent_id' => int, 'when_unavailable' => 'queue'|'assign_anyway']
            //   transfer_human => []  (the reason is a fixed code, not authored)
            //   internal_note  => ['note' => string]  (supports {{variable}})
            self::Action => [
                'type' => null,
                'parameters' => [],
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
                // Seconds to wait after the customer's last message before
                // answering, so a question typed in four bursts is answered
                // once. null = config('ai.turn_delay_seconds'); 0 = answer on
                // arrival.
                'response_delay_seconds' => null,
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
            // Charges the customer in the workspace's own gateway and waits
            // for the money. Two outputs: `paid`, and `failed` for a charge
            // that expired unpaid or could not be created at all. See
            // App\Services\Flow\PaymentNodes.
            self::Payment => [
                'integration_id' => null, // FK to integrations.id (a payment provider)
                'method' => 'pix', // pix | checkout (checkout: Mercado Pago only)
                'amount' => '', // "49,90" or "{{valor}}" — parsed after interpolation
                'description' => '',
                'expires_in_minutes' => 60,
                'message' => '', // sent before the Pix; supports {{payment_amount}}, {{payment_link}}
                'send_qr_code' => true,
                'send_copy_paste' => true,
                'send_link' => false,
                'payer_email' => '', // optional, supports {{variable}}
                'payer_document' => '', // optional CPF/CNPJ, supports {{variable}}
            ],
            // Reports a conversion to one or more pixel integrations and moves
            // straight on — tracking never holds a customer up.
            self::Pixel => [
                'integration_ids' => [],
                'event' => 'lead', // App\Services\Integrations\Pixels\PixelEvents::EVENTS
                'custom_event_name' => '',
                'value' => '',
                'currency' => 'BRL',
                'parameters' => [], // [{ key, value }] — values support {{variable}}
            ],
            // Hands the conversation to another flow's start node. Terminal:
            // whatever the other flow does is where this one ends.
            self::GoToFlow => [
                'flow_id' => null,
                'carry_variables' => true,
            ],
            // Puts the contact on the sales board — opens a lead when they have
            // no open one — and moves the card to a stage. One output; see
            // App\Services\Flow\LeadNodes.
            self::Lead => [
                'pipeline_id' => null,
                'stage_id' => null, // null = only make sure the lead exists
                'only_forward' => true, // never drag a card back to an earlier stage
                'title' => '', // supports {{variable}}
                'value' => '', // "1.500,00" or "{{payment_value}}"
                'owner_id' => null,
                'lost_reason' => '', // used only when the stage is a lost stage
            ],
        };
    }
}
