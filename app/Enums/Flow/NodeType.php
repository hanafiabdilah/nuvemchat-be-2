<?php

namespace App\Enums\Flow;

enum NodeType: string
{
    case Start = 'start';
    case Message = 'message';
    case Interval = 'interval';
    case Response = 'response';
    case WaitResponse = 'wait_response';
    case Tagging = 'tagging';
    case Condition = 'condition';
    case Status = 'status';
    case Action = 'action';
    case AIAgent = 'ai_agent';

    /**
     * "Agente IA com ações": an AI Agent that can also *do* things — look up
     * the workspace's product catalog, keep a cart, issue a Pix — deciding by
     * itself when. Runs on the AI Agent's own machinery (service hours, burst
     * window, typing, handoff); see App\Services\Flow\AiToolNodes.
     */
    case AiTools = 'ai_tools';
    case HttpRequest = 'http_request';
    case Interactive = 'interactive';
    case Payment = 'payment';
    case Invoice = 'invoice';
    case Pixel = 'pixel';
    case Receipt = 'receipt';
    case AiMedia = 'ai_media';
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
                // Never waits: pausing for the customer is the WaitResponse node's job.
            ],
            // Waits for the clock, then carries on. One output, nothing sent.
            //
            // ⚠️ Not the same waiting as WaitResponse below, which ends when the
            // *customer* writes. This one ends whether they write or not, and
            // a message that arrives during it does not move the flow.
            //
            // `presence` is off by default here and on for a Message node's
            // delay: a bubble's pause paces a sequence that is about to land,
            // so "digitando…" is the point of it, while an interval is usually
            // room for the customer to read or go and look something up, where
            // typing at them is hurrying them. See App\Services\Flow\IntervalNodes.
            self::Interval => [
                'seconds' => 5,
                'unit' => 'seconds', // display only: seconds | minutes | hours
                'presence' => false, // show "digitando…" for the wait
            ],
            // Asks, waits indefinitely for a valid answer, stores it. One output.
            self::Response => [
                'body' => '',
                'message_type' => 'text', // text, image, audio, video, document
                'attachment' => null, // for non-text messages
                'variable_key' => '',
                'validation' => null, // e.g. "any", "number", "email", "phone"
                "error_message" => '', // message to show if validation fails
            ],
            // Parks the flow until the customer writes. Two outputs: `replied`
            // and `timeout` (only when there is a limit). See
            // App\Services\Flow\WaitResponseNodes.
            self::WaitResponse => [
                'message' => '', // optional, sent before waiting; supports {{variable}}
                'variable_key' => '', // where the reply is stored; empty = not stored
                'timeout_seconds' => 0, // 0 = wait indefinitely
                'timeout_unit' => 'minutes', // display only: seconds | minutes | hours | days
                'buffer_seconds' => 0, // 0 = the first message is the reply; otherwise wait for the customer to stop typing
                'validation' => 'any', // any | number | email | phone
                'error_message' => '', // sent when the reply fails validation
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
            // Asks the workspace's own nota fiscal platform for an invoice and
            // waits for the authority to authorize it. Two outputs: `issued`
            // (the document went to the customer) and `failed`. See
            // App\Services\Flow\InvoiceNodes.
            self::Invoice => [
                'integration_id' => null, // FK to integrations.id (an invoice platform)
                'amount' => '', // "49,90" or "{{payment_value}}"
                'description' => '', // what was sold, printed on the document
                'customer_name' => '', // blank = the contact's name
                'customer_document' => '', // CPF/CNPJ, usually "{{cpf}}"
                'customer_email' => '', // blank = the contact's e-mail, when there is one
                'customer_address' => [], // optional: postal_code, street, number, complement, district, city, state
                'wait_minutes' => 30, // how long the flow waits for the authorization
                'message' => '', // sent with the PDF; supports {{invoice_number}}, {{invoice_pdf_url}}
                'send_pdf' => true,
                'send_email' => true, // the platform e-mails the document too
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
            // Everything an AI Agent node carries, plus what it may act on.
            // Every capability starts off: the node is auto-saved the moment
            // it lands on the canvas, and one that could already charge
            // customers before its author chose an account would be a side
            // effect nobody asked for. Outputs follow the capabilities — see
            // AiToolNodes::branches().
            // Has a model make an image, an audio or a video and sends it.
            // See App\Services\Flow\AiMediaNodes.
            self::AiMedia => \App\Services\Flow\AiMediaNodes::defaults(),
            // Asks for proof of payment, has an AI read it, branches on the
            // result. See App\Services\Flow\ReceiptNodes.
            self::Receipt => \App\Services\Flow\ReceiptNodes::defaults(),
            self::AiTools => array_merge(self::AIAgent->data(), [
                'capabilities' => [
                    'catalog' => false,
                    'cart' => false,
                    'payment' => [
                        'enabled' => false,
                        'integration_id' => null,
                        'method' => 'pix',
                        'expires_in_minutes' => 60,
                        'payer_document' => '', // supports {{variable}}; some gateways require it
                    ],
                ],
            ]),
        };
    }

    /**
     * Both AI node types. They share one execution path — the turn, the burst
     * window, the handoff — so every check that asks "is the flow parked on
     * an AI?" must ask it of both, or the newer node silently stops answering
     * wherever the check was written for the older one only.
     */
    public function isAiAgent(): bool
    {
        return $this === self::AIAgent || $this === self::AiTools;
    }
}
