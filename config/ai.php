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
        | Which provider listens, when a flow node does not say. Both reach the
        | hub through the same `inputAudio` block but under different field
        | names — see AiTranscription, which is the only place that knows the
        | difference.
        */

        'provider' => env('AI_TRANSCRIPTION_PROVIDER', 'openai'),
        'elevenlabs_model' => env('AI_TRANSCRIPTION_ELEVENLABS_MODEL', 'scribe_v2'),

        /*
        | The same hint in the two shapes the providers accept: OpenAI takes a
        | sentence of context, ElevenLabs a list of terms. Both exist for one
        | reason — product names and jargon a general model spells
        | phonetically otherwise (SOCKS5 becomes "socks five").
        |
        | These two are the *platform's* floor. The words that actually vary
        | belong to the business, and a workspace keeps its own list in
        | `tenants.audio_dictionary` — see App\Services\AiAgentHub\AiVocabulary,
        | which merges the two and is the only thing that reads either.
        */

        'prompt' => env('AI_TRANSCRIPTION_PROMPT'),
        'keyterms' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AI_TRANSCRIPTION_KEYTERMS', '')),
        ))),

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

        /*
        | Which provider speaks, when a flow node does not say. ElevenLabs
        | sounds markedly more human and costs more; OpenAI is the default
        | because it is the one every tenant already has credentials for.
        */

        'provider' => env('AI_TTS_PROVIDER', 'openai'),

        'model' => env('AI_TTS_MODEL', 'gpt-4o-mini-tts'),
        'voice' => env('AI_TTS_VOICE', 'onyx'),
        'speed' => (float) env('AI_TTS_SPEED', 1.0),

        /*
        | ElevenLabs equivalents. `voice_id` has no sensible default — a voice
        | there is an id somebody picked in their own account — so a node that
        | chooses ElevenLabs without one falls back to OpenAI rather than
        | asking the hub to speak with nothing to speak through.
        */

        'elevenlabs_model' => env('AI_TTS_ELEVENLABS_MODEL', 'eleven_flash_v2_5'),
        'elevenlabs_voice_id' => env('AI_TTS_ELEVENLABS_VOICE_ID'),

        /*
        | How the reply is pronounced, as far as the hub lets us influence it —
        | both ElevenLabs-only.
        |
        | `language` matters more than it looks: without it "HTTP", "link" and
        | "site" in a Portuguese sentence are read with English phonemes.
        | `text_normalization` ("auto", "on", "off") expands numbers, dates and
        | abbreviations before the voice sees them.
        |
        | ⚠️ Both are fields on `responseAudio`. A hub that has not shipped them
        | rejects the *whole* run over one unknown field, and the retry drops
        | every optional part — so the symptom is not an error, it is every
        | voice reply quietly arriving as text. Emptying either stops it being
        | sent, which is the fix without a deploy.
        */

        'language' => env('AI_TTS_LANGUAGE', 'pt'),
        'text_normalization' => env('AI_TTS_TEXT_NORMALIZATION', 'auto'),

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

    /*
    |--------------------------------------------------------------------------
    | Rented tokens & prepaid credit
    |--------------------------------------------------------------------------
    |
    | A workspace can run its AI on a key the platform owns instead of pasting
    | one of its own. The key is shared with other workspaces and picked at
    | random from the pool; what the workspace pays for is the usage, out of a
    | prepaid balance.
    |
    | Everything below `enabled` is a **default**, not the live value. The Back
    | Office overrides all five commercial numbers through the `settings` table
    | (Back Office → AI Credits → Pricing), because they are priced by whoever
    | runs the business, not redeployed. Read them through
    | `App\Services\AiCredits\AiCreditPricing`, never with `config()` directly —
    | a floor enforced by the API and a different floor printed on the customer's
    | page is a customer told one number and refused for another.
    |
    */

    'credits' => [

        /*
        | Master switch for the rental offering. Off, the pool is invisible to
        | tenants and nothing can be rented — existing rentals keep working, so
        | flipping this does not break live workspaces, it only closes the door
        | to new ones.
        */

        'enabled' => (bool) env('AI_CREDITS_ENABLED', true),

        /*
        | Markup on the provider's own cost, in percent. 40 means a run the
        | provider charged US$0.01 for is billed at US$0.014 converted to BRL.
        |
        | This is the entire margin of the offering: the platform is buying
        | tokens at retail and reselling them, so a markup of 0 means running
        | somebody else's AI for free and carrying the FX risk as well.
        */

        'markup_pct' => (float) env('AI_CREDITS_MARKUP_PCT', 40),

        /*
        | USD → BRL. A fixed rate, quoted by whoever sets the price, not a live
        | feed: a balance whose purchasing power moves during the day is
        | impossible for a customer to reason about, and the float would arrive
        | in the ledger as unexplainable variation between two identical runs.
        |
        | Every debit stores the rate it used, so raising this never rewrites
        | what an old charge means.
        */

        'usd_brl_rate' => (float) env('AI_CREDITS_USD_BRL_RATE', 5.60),

        /*
        | What a run costs when the hub reports no cost at all.
        |
        | Not a rounding detail: `ai_hub_runs.cost_usd` is already null for a
        | share of rows (the Back Office AI Usage page reports `costed_runs`
        | separately for exactly this reason — older hub versions, and stages
        | that do not price themselves). Treating those as free would mean the
        | rental model silently becomes a giveaway the moment the hub stops
        | reporting, with nothing in the product to show it happened.
        |
        | Deliberately a floor rather than an average: it is a fallback for a
        | broken signal, and it is logged every time it is used.
        */

        'fallback_run_cents' => (int) env('AI_CREDITS_FALLBACK_RUN_CENTS', 5),

        /*
        | Smallest top-up we will issue a Pix for. Below this the MercadoPago
        | fee eats the transaction.
        */

        'min_topup_cents' => (int) env('AI_CREDITS_MIN_TOPUP_CENTS', 1000),

        /*
        | Balance under which the workspace is warned it is about to lose its
        | AI. Warned once, cleared on the next top-up — see
        | `ai_credit_wallets.low_balance_notified_at`.
        */

        'low_balance_cents' => (int) env('AI_CREDITS_LOW_BALANCE_CENTS', 500),

    ],

];
