<?php

/**
 * CRM (App\Services\Crm, Api\Crm)
 *
 * In English; lang/en and lang/id carry the same keys.
 */

return [

    'customers' => [
        'not_found' => 'Customer not found.',
        'no_access' => 'You do not have access to this customer.',
        'not_linked' => 'This contact has no customer.',
        'invalid_phone' => 'Enter the phone number with its area code, for example (11) 98765-4321, or in full with the country code: +55 11 98765-4321.',
        'tags_need_contact' => 'Tags are kept on the customer\'s channel contacts, and this customer has none yet.',
        'group_cannot_link' => 'A group chat is not a person and cannot be linked to a customer.',
        'contact_not_linked' => 'That contact does not belong to this customer.',
        'last_contact' => 'This is the customer\'s only channel contact. Merge the customer instead.',
        'merge_same' => 'Pick another customer to merge.',
        'delete_has_contacts' => 'This customer writes in from :count channel contact(s). Unlink them or merge the customer instead.',
        'delete_has_sales' => 'This customer has leads or deals. Delete them, or merge the customer instead.',
        'relate_self' => 'A customer cannot be related to themselves.',
        'deleted' => 'Customer deleted.',
        'linked' => 'Contact linked to the customer.',
        'unlinked' => 'Contact separated into a customer of its own.',
        'merged' => 'Customers merged.',
        'relationship_added' => 'Relationship added.',
        'relationship_removed' => 'Relationship removed.',
    ],

    'companies' => [
        'not_found' => 'Company not found.',
        'deleted' => 'Company deleted. Its customers were kept.',
    ],

    'custom_fields' => [
        'not_found' => 'Field not found.',
        'unknown' => 'There is no field called ":key".',
        'invalid' => 'The value of :field does not fit the field.',
        'key_taken' => 'A field with this key already exists.',
        'too_many' => 'A workspace can have at most :max fields here.',
        'options_required' => 'A choice field needs at least one option.',
        'deleted' => 'Field deleted. The values already entered are kept.',
    ],

    'leads' => [
        'not_found' => 'Lead not found.',
        'deleted' => 'Lead deleted.',
        'converted_locked' => 'This lead was converted into a deal; its status can no longer change.',
        'not_convertible' => 'Only an open lead can be converted into a deal.',
    ],

    'deals' => [
        'not_found' => 'Deal not found.',
        'deleted' => 'Deal deleted.',
        'stage_not_found' => 'That stage does not exist.',
        'stage_not_in_pipeline' => 'That stage belongs to another pipeline.',
        'customer_required' => 'Choose the customer this is about, or start it from their conversation.',
    ],

    'pipelines' => [
        'default_name' => 'Sales',
        'default_stages' => [
            'new' => 'New',
            'qualified' => 'Qualified',
            'proposal' => 'Proposal',
            'negotiation' => 'Negotiation',
            'won' => 'Won',
            'lost' => 'Lost',
        ],
        'not_found' => 'Pipeline not found.',
        'stage_not_found' => 'Stage not found.',
        'deleted' => 'Pipeline deleted.',
        'too_many' => 'A workspace can have at most :max pipelines.',
        'too_many_stages' => 'A pipeline can have at most :max stages.',
        'no_stages' => 'This pipeline has no stages.',
        'needs_outcomes' => 'A pipeline needs at least one open stage, one Won stage and one Lost stage.',
        'stage_has_deals' => 'This stage holds :count deal(s). Choose the stage to move them to.',
        'pipeline_has_deals' => 'This pipeline holds :count deal(s). Choose the pipeline to move them to.',
        'move_to_invalid' => 'Choose another stage or pipeline to move the deals to.',
        'stage_kind_locked' => 'Move this stage\'s deals elsewhere before changing whether it is open, won or lost.',
        'last_one' => 'The last pipeline cannot be deleted.',
        'order_incomplete' => 'Send every stage of the pipeline, in the new order.',
    ],

    'tasks' => [
        'not_found' => 'Task not found.',
        'deleted' => 'Task deleted.',
        'remind_after_due' => 'The reminder must come before the due time.',
    ],

    'activities' => [
        'not_found' => 'Activity not found.',
        'deleted' => 'Activity deleted.',
        'system_entry' => 'Entries recorded by the CRM itself cannot be edited.',
        'not_yours' => 'Only the person who logged this, or the owner, can change it.',
    ],

    'subjects' => [
        'not_found' => 'That record was not found.',
        'conversation_not_found' => 'That conversation was not found.',
        'no_customer' => 'This conversation has no person behind it (a group chat cannot hold sales records).',
        'required' => 'Choose what this is about: a customer, lead, deal or company.',
    ],

    'segments' => [
        'not_found' => 'Segment not found.',
        'deleted' => 'Segment deleted.',
        'too_many' => 'A workspace can have at most :max segments.',
        'invalid_rule' => 'This rule is not valid.',
    ],

    'views' => [
        'not_found' => 'View not found.',
        'deleted' => 'View deleted.',
        'too_many' => 'You can keep at most :max views on this page.',
        'not_yours' => 'Only the person who saved this view, or someone who manages CRM settings, can change it.',
        'share_forbidden' => 'Sharing a view with the workspace needs the permission to manage CRM settings.',
    ],

    'scoring' => [
        'invalid_rule' => 'This scoring rule is not valid.',
        'saved' => 'Lead scoring saved.',
    ],

    'bulk' => [
        'forbidden' => 'You do not have permission to do this.',
        'too_many' => 'Bulk actions work on at most :max records at a time. Narrow the filters first.',
        'invalid_tags' => 'Choose tags from this workspace.',
        'row_failed' => 'This record could not be changed.',
    ],

    'import' => [
        'empty' => 'The file needs a header row and at least one customer.',
        'too_many' => 'The file has more than :max customers. Split it into smaller files.',
        'expired' => 'This import expired. Send the file again.',
        'invalid' => 'Choose how to treat customers who already exist.',
        'name_required' => 'Choose the column with the customer name.',
        'name_empty' => 'The name is empty.',
        'unknown_status' => 'Status ":value" was not recognised and was left out.',
        'unknown_owner' => 'Nobody in the workspace is called or has the e-mail ":value"; no person in charge was set.',
        'unknown_tag' => 'There is no tag called ":value".',
        'invalid_field' => 'The value for :field did not fit the field and was left out.',
        'tags_need_contact' => 'Tags were not applied: they live on a channel contact, and this customer has none yet.',
        'matched_skipped' => 'Already a customer (:name); left unchanged.',
    ],

    'automations' => [
        'attribute_actions' => 'actions',
        'invalid_rule' => 'This part of the automation is not valid.',
        'invalid_days' => 'Choose a number of days from 1 to :max.',
        'field_not_for_trigger' => 'This condition does not apply to the record this automation starts from.',
        'action_not_for_trigger' => 'This action cannot be taken on the record this automation starts from.',
        'pick_people' => 'Choose at least one person.',
        'task_title_required' => 'Give the task a title (up to 255 characters).',
        'message_required' => 'Write the notification (up to 500 characters).',
        'note_required' => 'Write the note (up to 2,000 characters).',
        'not_found' => 'Something this automation names no longer exists in this workspace.',
        'not_found_automation' => 'Automation not found.',
        'too_many' => 'A workspace can have up to :max automations.',
        'deleted' => 'Automation deleted.',
    ],

    'notices' => [
        'assigned_many' => [
            'lead' => '{1} :count lead was given to you|[2,*] :count leads were given to you',
            'deal' => '{1} :count deal was given to you|[2,*] :count deals were given to you',
            'task' => '{1} :count task was given to you|[2,*] :count tasks were given to you',
        ],
    ],

];
