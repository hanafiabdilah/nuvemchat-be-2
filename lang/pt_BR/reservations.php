<?php

/**
 * Reservas (App\Services\Reservations, Api\Reservations)
 *
 * Em português do Brasil; lang/en, lang/id e lang/pt_BR têm as mesmas chaves.
 */

return [
    'not_open' => 'Esta reserva não pode mais ser respondida — já foi decidida, ou o horário já passou.',
    'offer_time_required' => 'Escolha a data e o horário a oferecer.',
    'offer_in_the_past' => 'O horário a oferecer já passou.',
    'service_required' => 'Escolha um serviço.',
    'service_has_upcoming' => 'Este serviço tem reservas futuras. Desative-o para parar novas reservas, ou cancele-as antes.',
    'only_manual_delete' => 'Só reservas anotadas pela equipe podem ser excluídas. Cancele esta em vez disso.',
    'deposit_required' => 'Informe o valor do sinal.',
    'price_required' => 'Informe o preço.',
    'invalid_date' => 'Use uma data no formato AAAA-MM-DD.',
    'invalid_time' => 'Use um horário no formato HH:MM.',
    'conversation_exclusive' => 'Esta conversa é exclusiva de outro atendente.',
    'problems' => [
        'inactive' => 'Este serviço não está aceitando reservas.',
        'closed' => 'O serviço está fechado nesse horário.',
        'not_a_slot' => 'Esse horário está fora do funcionamento, ou a reserva não termina antes de fechar.',
        'in_the_past' => 'Esse horário já passou.',
        'too_soon' => 'É mais cedo do que o serviço aceita reservas.',
        'too_far' => 'É mais à frente do que o serviço aceita reservas.',
        'full' => 'Esse horário está lotado.',
        'day_full' => 'Esse dia está marcado como lotado.',
        'party_too_large' => 'Mais pessoas do que o serviço aceita por reserva.',
    ],
];
