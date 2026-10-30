<?php

/**
 * CRM (App\Services\Crm, Api\Crm)
 *
 * Em português do Brasil; lang/en, lang/id e lang/pt_BR têm as mesmas chaves.
 */

return [

    'customers' => [
        'not_found' => 'Cliente não encontrado.',
        'no_access' => 'Você não tem acesso a este cliente.',
        'not_linked' => 'Este contato não tem um cliente.',
        'invalid_phone' => 'Informe o telefone com DDD, por exemplo (11) 98765-4321, ou completo com o código do país: +55 11 98765-4321.',
        'tags_need_contact' => 'As etiquetas ficam nos contatos de canal do cliente, e este cliente ainda não tem nenhum.',
        'group_cannot_link' => 'Um grupo não é uma pessoa e não pode ser vinculado a um cliente.',
        'contact_not_linked' => 'Esse contato não pertence a este cliente.',
        'last_contact' => 'Este é o único contato de canal do cliente. Mescle o cliente em vez disso.',
        'merge_same' => 'Escolha outro cliente para mesclar.',
        'delete_has_contacts' => 'Este cliente escreve por :count contato(s) de canal. Desvincule-os ou mescle o cliente em vez disso.',
        'delete_has_sales' => 'Este cliente tem leads ou negócios. Exclua-os ou mescle o cliente em vez disso.',
        'relate_self' => 'Um cliente não pode ter relação consigo mesmo.',
        'deleted' => 'Cliente excluído.',
        'linked' => 'Contato vinculado ao cliente.',
        'unlinked' => 'Contato separado em um cliente próprio.',
        'merged' => 'Clientes mesclados.',
        'relationship_added' => 'Relação adicionada.',
        'relationship_removed' => 'Relação removida.',
    ],

    'companies' => [
        'not_found' => 'Empresa não encontrada.',
        'deleted' => 'Empresa excluída. Os clientes dela foram mantidos.',
    ],

    'custom_fields' => [
        'not_found' => 'Campo não encontrado.',
        'unknown' => 'Não existe um campo chamado ":key".',
        'invalid' => 'O valor de :field não cabe no campo.',
        'key_taken' => 'Já existe um campo com esta chave.',
        'too_many' => 'Um workspace pode ter no máximo :max campos aqui.',
        'options_required' => 'Um campo de escolha precisa de pelo menos uma opção.',
        'deleted' => 'Campo excluído. Os valores já preenchidos foram mantidos.',
    ],

    'leads' => [
        'not_found' => 'Lead não encontrado.',
        'deleted' => 'Lead excluído.',
        'converted_locked' => 'Este lead foi convertido em negócio; o status dele não pode mais mudar.',
        'not_convertible' => 'Só um lead em aberto pode ser convertido em negócio.',
    ],

    'deals' => [
        'not_found' => 'Negócio não encontrado.',
        'deleted' => 'Negócio excluído.',
        'stage_not_found' => 'Essa etapa não existe.',
        'stage_not_in_pipeline' => 'Essa etapa pertence a outro funil.',
        'customer_required' => 'Escolha o cliente deste negócio, ou crie-o a partir da conversa dele.',
    ],

    'pipelines' => [
        'default_name' => 'Vendas',
        'default_stages' => [
            'new' => 'Novo',
            'qualified' => 'Qualificado',
            'proposal' => 'Proposta',
            'negotiation' => 'Negociação',
            'won' => 'Ganho',
            'lost' => 'Perdido',
        ],
        'not_found' => 'Funil não encontrado.',
        'stage_not_found' => 'Etapa não encontrada.',
        'deleted' => 'Funil excluído.',
        'too_many' => 'Um workspace pode ter no máximo :max funis.',
        'too_many_stages' => 'Um funil pode ter no máximo :max etapas.',
        'no_stages' => 'Este funil não tem etapas.',
        'needs_outcomes' => 'Um funil precisa de pelo menos uma etapa em aberto, uma de Ganho e uma de Perdido.',
        'stage_has_deals' => 'Esta etapa tem :count negócio(s). Escolha para qual etapa movê-los.',
        'pipeline_has_deals' => 'Este funil tem :count negócio(s). Escolha para qual funil movê-los.',
        'move_to_invalid' => 'Escolha outra etapa ou outro funil para onde mover os negócios.',
        'stage_kind_locked' => 'Mova os negócios desta etapa para outro lugar antes de mudar se ela é em aberto, ganho ou perdido.',
        'last_one' => 'O último funil não pode ser excluído.',
        'order_incomplete' => 'Envie todas as etapas do funil, na nova ordem.',
    ],

    'tasks' => [
        'not_found' => 'Tarefa não encontrada.',
        'deleted' => 'Tarefa excluída.',
        'remind_after_due' => 'O lembrete precisa vir antes do prazo.',
    ],

    'activities' => [
        'not_found' => 'Atividade não encontrada.',
        'deleted' => 'Atividade excluída.',
        'system_entry' => 'Registros feitos pelo próprio CRM não podem ser editados.',
        'not_yours' => 'Só quem registrou isto, ou o proprietário, pode alterar.',
    ],

    'subjects' => [
        'not_found' => 'Esse registro não foi encontrado.',
        'conversation_not_found' => 'Essa conversa não foi encontrada.',
        'no_customer' => 'Esta conversa não tem uma pessoa por trás (um grupo não pode ter registros de venda).',
        'required' => 'Escolha sobre o que é: um cliente, lead, negócio ou empresa.',
    ],

    'segments' => [
        'not_found' => 'Segmento não encontrado.',
        'deleted' => 'Segmento excluído.',
        'too_many' => 'Um workspace pode ter no máximo :max segmentos.',
        'invalid_rule' => 'Esta regra não é válida.',
    ],

    'views' => [
        'not_found' => 'Visualização não encontrada.',
        'deleted' => 'Visualização excluída.',
        'too_many' => 'Você pode guardar no máximo :max visualizações nesta página.',
        'not_yours' => 'Só quem salvou esta visualização, ou quem gerencia as configurações do CRM, pode alterá-la.',
        'share_forbidden' => 'Compartilhar uma visualização com o workspace exige a permissão de gerenciar as configurações do CRM.',
    ],

    'scoring' => [
        'invalid_rule' => 'Esta regra de pontuação não é válida.',
        'saved' => 'Pontuação de leads salva.',
    ],

    'bulk' => [
        'forbidden' => 'Você não tem permissão para fazer isto.',
        'too_many' => 'As ações em massa funcionam com no máximo :max registros por vez. Refine os filtros primeiro.',
        'invalid_tags' => 'Escolha etiquetas deste workspace.',
        'row_failed' => 'Este registro não pôde ser alterado.',
    ],

    'import' => [
        'empty' => 'O arquivo precisa de uma linha de cabeçalho e de pelo menos um cliente.',
        'too_many' => 'O arquivo tem mais de :max clientes. Divida-o em arquivos menores.',
        'expired' => 'Esta importação expirou. Envie o arquivo de novo.',
        'invalid' => 'Escolha o que fazer com os clientes que já existem.',
        'name_required' => 'Escolha a coluna com o nome do cliente.',
        'name_empty' => 'O nome está vazio.',
        'unknown_status' => 'O status ":value" não foi reconhecido e ficou de fora.',
        'unknown_owner' => 'Ninguém no workspace se chama ou tem o e-mail ":value"; nenhum responsável foi definido.',
        'unknown_tag' => 'Não existe uma etiqueta chamada ":value".',
        'invalid_field' => 'O valor de :field não cabia no campo e ficou de fora.',
        'tags_need_contact' => 'As etiquetas não foram aplicadas: elas ficam em um contato de canal, e este cliente ainda não tem nenhum.',
        'matched_skipped' => 'Já é cliente (:name); mantido sem alterações.',
    ],

    'automations' => [
        'attribute_actions' => 'ações',
        'invalid_rule' => 'Esta parte da automação não é válida.',
        'invalid_days' => 'Escolha um número de dias de 1 a :max.',
        'field_not_for_trigger' => 'Esta condição não se aplica ao registro de onde esta automação parte.',
        'action_not_for_trigger' => 'Esta ação não pode ser feita no registro de onde esta automação parte.',
        'pick_people' => 'Escolha pelo menos uma pessoa.',
        'task_title_required' => 'Dê um título à tarefa (até 255 caracteres).',
        'message_required' => 'Escreva a notificação (até 500 caracteres).',
        'note_required' => 'Escreva a nota (até 2.000 caracteres).',
        'not_found' => 'Algo que esta automação cita não existe mais neste workspace.',
        'not_found_automation' => 'Automação não encontrada.',
        'too_many' => 'Um workspace pode ter até :max automações.',
        'deleted' => 'Automação excluída.',
    ],

    'notices' => [
        'assigned_many' => [
            'lead' => '{1} :count lead foi atribuído a você|[2,*] :count leads foram atribuídos a você',
            'deal' => '{1} :count negócio foi atribuído a você|[2,*] :count negócios foram atribuídos a você',
            'task' => '{1} :count tarefa foi atribuída a você|[2,*] :count tarefas foram atribuídas a você',
        ],
    ],

];
