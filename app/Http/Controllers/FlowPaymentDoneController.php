<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The page a customer lands on after paying a Stripe Checkout link, when the
 * workspace set no page of its own.
 *
 * Deliberately says nothing about the payment: the URL is unsigned and anyone
 * could open it, so "paid" is never read from here — the flow learns that from
 * Stripe. All the page does is send the customer back to the conversation,
 * which is where the bot will confirm it.
 */
class FlowPaymentDoneController extends Controller
{
    public function show(): Response
    {
        $html = <<<'HTML'
        <!doctype html>
        <html lang="pt-BR">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <meta name="robots" content="noindex">
          <title>Pagamento enviado</title>
          <style>
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f7f9; color: #111827;
                   font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding: 24px; box-sizing: border-box; }
            main { max-width: 420px; text-align: center; background: #fff; border-radius: 16px; padding: 32px 24px;
                   box-shadow: 0 1px 3px rgba(0,0,0,.08); }
            .mark { width: 56px; height: 56px; border-radius: 50%; background: #dcfce7; color: #15803d; display: grid;
                    place-items: center; margin: 0 auto 16px; font-size: 28px; }
            h1 { font-size: 20px; margin: 0 0 8px; }
            p { margin: 0; color: #4b5563; }
          </style>
        </head>
        <body>
          <main>
            <div class="mark" aria-hidden="true">✓</div>
            <h1>Pagamento enviado</h1>
            <p>Você já pode voltar para a conversa. Assim que o pagamento for confirmado, avisaremos por lá.</p>
          </main>
        </body>
        </html>
        HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
