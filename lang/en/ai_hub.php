<?php

/**
 * AI agents and credentials (App\Services\AiAgentHub\AiHubFieldErrors).
 *
 * Field names are the dashboard form's own labels, so a message points at the
 * field the person is looking at. lang/en, lang/id and lang/pt_BR share keys.
 */

return [

    'attributes' => [
        'name' => 'Name',
        'description' => 'Description',
        'systemPrompt' => 'System Prompt',
        'model' => 'Model',
        'temperature' => 'Temperature',
        'maxTokens' => 'Max Tokens',
        'apiKey' => 'API Key',
        'defaultModel' => 'Default Model',
    ],

    'rules' => [
        'min_length' => ':attribute must be at least :min characters.',
        'max_length' => ':attribute can be at most :max characters.',
        'required' => 'Fill in :attribute.',
        'min_value' => ':attribute must be at least :min.',
        'max_value' => ':attribute can be at most :max.',
        'invalid' => 'The value in :attribute was not accepted. Check it and try again.',
        'too_large' => ':attribute is too long to be saved. Shorten the text and try again.',
    ],

];
