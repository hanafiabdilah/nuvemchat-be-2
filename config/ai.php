<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI agent turn delay (the "boom chat" window)
    |--------------------------------------------------------------------------
    |
    | How long an AIAgent node waits after the customer's last message before
    | it answers. Every message that lands inside the window pushes the wait
    | back, and when it finally elapses the whole burst is answered once, as a
    | single turn.
    |
    | People do not write one message per thought. "Oi" / "tenho uma dúvida" /
    | "sobre o pedido 123" is one question typed in three bursts, and without
    | this window it was three hub runs and three replies — the first two
    | answering a question the customer had not finished asking, all three
    | billed, and the customer left reading a bot talking over them.
    |
    | The cost is latency on the common case: a customer who really did send
    | one message waits this long for the first word back. Chat tolerates that
    | far better than it tolerates being interrupted, which is why the default
    | is a real pause and not a token one.
    |
    | 0 answers the instant a message arrives (the behaviour before this
    | existed). A single AIAgent node overrides it with `response_delay_seconds`
    | in its node data.
    |
    */

    'turn_delay_seconds' => (int) env('AI_TURN_DELAY_SECONDS', 4),

    /*
    | Ceiling for the per-node override. A flow builder typo must not be able
    | to park a live conversation for an hour.
    */

    'max_turn_delay_seconds' => (int) env('AI_MAX_TURN_DELAY_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Voice notes as agent input
    |--------------------------------------------------------------------------
    |
    | A customer who records a voice note has said nothing the agent can read:
    | the message carries no text at all, so before this the model was handed
    | the literal string "[audio]" and answered the silence. The hub transcribes
    | the file itself — we hand over the same signed URL the dashboard renders
    | and add the `inputAudio` block below.
    |
    | `enabled` is a kill switch, not a preference: if the hub ever refuses the
    | block, flipping this off restores the previous behaviour (the customer is
    | still answered, just not listened to) without a deploy.
    |
    */

    'audio' => [

        'enabled' => (bool) env('AI_AUDIO_INPUT_ENABLED', true),

        /*
        | Whisper-family model the hub runs the file through, and the language
        | it should assume. Naming the language matters more than it looks:
        | left to guess, a two-word voice note in Portuguese is regularly
        | transcribed as Spanish.
        */

        'transcription_model' => env('AI_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
        'language' => env('AI_TRANSCRIPTION_LANGUAGE', 'pt'),

        /*
        | Optional hint carried into the transcription — product names and
        | jargon a general model spells phonetically otherwise.
        */

        'prompt' => env('AI_TRANSCRIPTION_PROMPT'),

        /*
        | Voice notes per turn. Someone who records three in a row is asking
        | one question, so the burst still has to travel whole — but unlike an
        | image, transcription is billed by the minute, so the tail is cut.
        */

        'max_per_run' => (int) env('AI_AUDIO_MAX_PER_RUN', 3),

        /*
        | Crude size ceiling, and crude on purpose: what it costs to transcribe
        | is minutes, and minutes-per-byte depends entirely on the codec (an
        | Opus voice note is ~1 KB/s, an MP3 sixteen times that). This is not a
        | price — it is the line between a voice note and a forwarded hour-long
        | recording, which is the only case worth refusing. 0 disables it.
        */

        'max_bytes' => (int) env('AI_AUDIO_MAX_BYTES', 10 * 1024 * 1024),

    ],

    /*
    |--------------------------------------------------------------------------
    | Voice replies (the agent answering out loud)
    |--------------------------------------------------------------------------
    |
    | Whether an AI node speaks is decided per node in the flow builder — see
    | App\Services\AiAgentHub\AiVoiceReply. What lives here is only the voice
    | itself: the defaults a node inherits when its author did not pick one,
    | so changing how the product sounds does not mean editing every flow.
    |
    | `enabled` is the platform kill switch. Off, no node speaks and no
    | `responseAudio` block is sent — the same failure the hub's missing audio
    | support caused, but chosen rather than discovered.
    |
    */

    'voice' => [

        'enabled' => (bool) env('AI_VOICE_REPLY_ENABLED', true),

        'model' => env('AI_TTS_MODEL', 'gpt-4o-mini-tts'),
        'voice' => env('AI_TTS_VOICE', 'onyx'),
        'speed' => (float) env('AI_TTS_SPEED', 1.0),

        /*
        | Left unset on purpose: the format decides whether WhatsApp draws a
        | voice note or a file attachment, so the channel picks it
        | (Channel::voiceReplyFormat()). Setting this forces one format
        | everywhere — useful to pin down a hub that rejects a codec, and a
        | regression the rest of the time. The hub accepts: mp3, opus, aac,
        | flac, wav, pcm.
        */

        'format' => env('AI_TTS_FORMAT'),

        /*
        | How the voice should sound. Style, not content — the words are the
        | agent's reply, already written by the time this is read.
        */

        'instructions' => env('AI_TTS_INSTRUCTIONS'),

    ],

];
