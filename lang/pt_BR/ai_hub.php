<?php

/**
 * Agentes de IA e credenciais (App\Services\AiAgentHub\AiHubFieldErrors).
 *
 * Os nomes dos campos são os mesmos rótulos do formulário no dashboard, para
 * que a mensagem aponte para o campo que a pessoa está vendo.
 * lang/en, lang/id e lang/pt_BR têm as mesmas chaves.
 */

return [

    'attributes' => [
        'name' => 'Nome',
        'description' => 'Descrição',
        'systemPrompt' => 'Prompt do Sistema',
        'model' => 'Modelo',
        'temperature' => 'Temperatura',
        'maxTokens' => 'Máximo de Tokens',
        'apiKey' => 'Chave de API',
        'defaultModel' => 'Modelo Padrão',
    ],

    'rules' => [
        'min_length' => 'O campo :attribute precisa ter pelo menos :min caracteres.',
        'max_length' => 'O campo :attribute pode ter no máximo :max caracteres.',
        'required' => 'Preencha o campo :attribute.',
        'min_value' => 'O campo :attribute precisa ser no mínimo :min.',
        'max_value' => 'O campo :attribute pode ser no máximo :max.',
        'invalid' => 'O valor do campo :attribute não foi aceito. Revise-o e tente novamente.',
        'too_large' => 'O campo :attribute ficou grande demais para ser salvo. Encurte o texto e tente novamente.',
    ],

    'credential_status_fixed' => 'O status de uma chave não pode ser alterado. Para deixar de usá-la, exclua a chave.',

];
