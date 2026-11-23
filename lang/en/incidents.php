<?php

return [
    'errors' => [
        'over' => 'This incident has ended and can no longer be changed.',
        'not_active' => 'This incident is not active.',
        'not_scheduled' => 'Only a scheduled incident can be canceled. To end an active incident, resolve it.',
        'cannot_send' => 'This notice cannot be sent by hand.',
        'overlap' => 'The incident ":title" already covers :connections at that time. End it or pick other connections.',
        'keywords_required' => 'Add at least one keyword, or choose "All messages".',
        'keyword_too_short' => 'The keyword ":keyword" is too short. Use at least 3 letters.',
        'connection_unknown' => 'One of the chosen connections does not exist or you do not have access to it.',
        'connection_email' => 'Incidents do not apply to e-mail connections.',
        'min_duration' => 'An incident has to last at least 15 minutes.',
        'max_duration' => 'An incident can last at most 72 hours.',
        'ends_in_past' => 'The incident has to end in the future.',
        'bad_time' => 'Invalid date or time.',
        'resolution_required' => 'Write the closing message, or untick sending it.',
        'ai_agent_required' => 'Choose an active AI agent of this workspace to write the notice.',
        'ai_brief_required' => 'Write what the AI should tell customers about the incident.',
        'template_limit' => 'This workspace already has the most incident templates it can keep. Delete one that is no longer used.',
    ],
];
