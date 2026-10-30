<?php

namespace App\Services\Crm\Automation;

use App\Enums\Crm\AutomationTrigger;
use App\Enums\Crm\CustomerStatus;
use App\Enums\Crm\LeadStatus;
use App\Enums\Crm\StageKind;
use App\Enums\Crm\TaskPriority;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a CRM automation may say: its triggers, the fields its conditions can
 * read, and the actions it can take — and the check that a definition only
 * says those things, about this workspace's own users, tags, flows,
 * pipelines and stages.
 *
 * The dashboard reads the same vocabulary (`GET /crm/automations/meta`), so a
 * field or action offered in the editor is one the engine runs.
 */
final class AutomationDefinition
{
    public const MAX_AUTOMATIONS = 100;

    public const MAX_CONDITIONS = 10;

    public const MAX_ACTIONS = 10;

    public const MAX_DAYS = 365;

    public const SUBJECTS = ['lead', 'deal', 'customer', 'task'];

    /** field => [subjects it applies to, operators] */
    public const FIELDS = [
        'lead_status' => [['lead'], ['in', 'not_in']],
        'lead_source' => [['lead'], ['in', 'not_in']],
        'lead_score' => [['lead'], ['at_least', 'below']],
        'value' => [['lead', 'deal'], ['at_least', 'below', 'is_empty', 'is_not_empty']],
        'pipeline' => [['deal'], ['is', 'is_not']],
        'stage' => [['deal'], ['in', 'not_in']],
        'deal_status' => [['deal'], ['in', 'not_in']],
        'owner' => [['lead', 'deal', 'customer', 'task'], ['is', 'is_not', 'is_empty', 'is_not_empty']],
        'task_priority' => [['task'], ['in', 'not_in']],
        'customer_status' => [self::SUBJECTS, ['in', 'not_in']],
        'customer_source' => [self::SUBJECTS, ['in', 'not_in']],
        'customer_tags' => [self::SUBJECTS, ['has_any', 'has_none']],
        'customer_company' => [self::SUBJECTS, ['is_empty', 'is_not_empty']],
    ];

    /** Operators whose condition needs no value. */
    public const VALUELESS = ['is_empty', 'is_not_empty'];

    /** action => subjects it can act on */
    public const ACTIONS = [
        'assign_owner' => ['lead', 'deal', 'customer', 'task'],
        'set_lead_status' => ['lead'],
        'move_deal_stage' => ['deal'],
        'create_task' => self::SUBJECTS,
        'notify' => self::SUBJECTS,
        'add_customer_tags' => self::SUBJECTS,
        'remove_customer_tags' => self::SUBJECTS,
        'set_customer_status' => self::SUBJECTS,
        'start_flow' => self::SUBJECTS,
        'add_note' => ['lead', 'deal', 'customer'],
    ];

    public const ASSIGN_MODES = ['user', 'round_robin', 'least_busy'];

    public const TASK_ASSIGNEES = ['owner', 'user', 'none'];

    public const NOTIFY_RECIPIENTS = ['owner', 'users', 'owner_and_users'];

    /** For the editor: everything it needs to draw a definition. */
    public static function meta(): array
    {
        return [
            'triggers' => collect(AutomationTrigger::cases())->map(fn (AutomationTrigger $trigger) => [
                'key' => $trigger->value,
                'subject' => $trigger->subject(),
                'scanned' => $trigger->scanned(),
                'takes_days' => $trigger->takesDays(),
            ])->values(),
            'fields' => collect(self::FIELDS)->map(fn ($spec, $field) => ['key' => $field, 'subjects' => $spec[0], 'operators' => $spec[1]])->values(),
            'actions' => collect(self::ACTIONS)->map(fn ($subjects, $action) => ['key' => $action, 'subjects' => $subjects])->values(),
            'limits' => [
                'automations' => self::MAX_AUTOMATIONS,
                'conditions' => self::MAX_CONDITIONS,
                'actions' => self::MAX_ACTIONS,
                'days' => self::MAX_DAYS,
            ],
        ];
    }

    /**
     * A definition checked and normalised. Unknown fields, operators that do
     * not fit, actions that cannot act on the trigger's record and references
     * to another workspace's records are refused, not dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>  trigger, trigger_config, match, conditions, actions
     */
    public static function validate(int $tenantId, array $input): array
    {
        $data = validator($input, [
            'trigger' => ['required', Rule::in(AutomationTrigger::values())],
            'trigger_config' => ['nullable', 'array'],
            'match' => ['nullable', Rule::in(['all', 'any'])],
            'conditions' => ['present', 'array', 'max:'.self::MAX_CONDITIONS],
            'conditions.*.field' => ['required', Rule::in(array_keys(self::FIELDS))],
            'conditions.*.operator' => ['required', 'string', 'max:20'],
            'conditions.*.value' => ['nullable'],
            'actions' => ['required', 'array', 'min:1', 'max:'.self::MAX_ACTIONS],
            'actions.*.type' => ['required', Rule::in(array_keys(self::ACTIONS))],
        ], [], [
            'actions' => __('crm.automations.attribute_actions'),
        ])->validate();

        $trigger = AutomationTrigger::from($data['trigger']);
        $subject = $trigger->subject();
        $refs = new AutomationReferences($tenantId);

        $conditions = [];
        foreach ($data['conditions'] as $index => $condition) {
            $conditions[] = self::condition($refs, $subject, $condition, "conditions.{$index}");
        }

        $actions = [];
        foreach ($data['actions'] as $index => $action) {
            $actions[] = self::action($refs, $subject, (array) ($input['actions'][$index] ?? []), "actions.{$index}");
        }

        return [
            'trigger' => $trigger->value,
            'trigger_config' => self::triggerConfig($refs, $trigger, (array) ($input['trigger_config'] ?? [])) ?: null,
            'match' => $data['match'] ?? 'all',
            'conditions' => $conditions,
            'actions' => $actions,
        ];
    }

    /** @param  array<string, mixed>  $config */
    private static function triggerConfig(AutomationReferences $refs, AutomationTrigger $trigger, array $config): array
    {
        $clean = [];

        if ($trigger->takesDays()) {
            $days = $config['days'] ?? null;

            if (! is_numeric($days) || (int) $days < 1 || (int) $days > self::MAX_DAYS) {
                self::fail('trigger_config.days', __('crm.automations.invalid_days', ['max' => self::MAX_DAYS]));
            }

            $clean['days'] = (int) $days;
        }

        if (in_array($trigger, [AutomationTrigger::LeadStatusChanged, AutomationTrigger::CustomerStatusChanged], true) && ! empty($config['to'])) {
            $allowed = $trigger === AutomationTrigger::LeadStatusChanged ? LeadStatus::values() : CustomerStatus::values();
            $clean['to'] = self::listOf((array) $config['to'], $allowed, 'trigger_config.to');
        }

        if ($trigger->subject() === 'deal' && ! empty($config['pipeline_id'])) {
            $clean['pipeline_id'] = $refs->pipeline($config['pipeline_id'], 'trigger_config.pipeline_id');
        }

        if (in_array($trigger, [AutomationTrigger::DealStageChanged, AutomationTrigger::DealStale], true) && ! empty($config['stage_ids'])) {
            $clean['stage_ids'] = $refs->stages((array) $config['stage_ids'], 'trigger_config.stage_ids');
        }

        return $clean;
    }

    /** @param  array<string, mixed>  $condition */
    private static function condition(AutomationReferences $refs, string $subject, array $condition, string $path): array
    {
        [$subjects, $operators] = self::FIELDS[$condition['field']];
        $operator = $condition['operator'];

        if (! in_array($subject, $subjects, true)) {
            self::fail("{$path}.field", __('crm.automations.field_not_for_trigger'));
        }

        if (! in_array($operator, $operators, true)) {
            self::fail("{$path}.operator", __('crm.automations.invalid_rule'));
        }

        $clean = ['field' => $condition['field'], 'operator' => $operator];

        if (in_array($operator, self::VALUELESS, true)) {
            return $clean;
        }

        $value = $condition['value'] ?? null;
        $valuePath = "{$path}.value";

        $clean['value'] = match ($condition['field']) {
            'lead_status' => self::listOf((array) $value, LeadStatus::values(), $valuePath),
            'deal_status' => self::listOf((array) $value, StageKind::values(), $valuePath),
            'customer_status' => self::listOf((array) $value, CustomerStatus::values(), $valuePath),
            'task_priority' => self::listOf((array) $value, TaskPriority::values(), $valuePath),
            'lead_source', 'customer_source' => self::strings($value, $valuePath),
            'lead_score' => self::number($value, $valuePath, 0, 100),
            'value' => self::number($value, $valuePath, 0, 1_000_000_000_000_000),
            'pipeline' => $refs->pipeline($value, $valuePath),
            'stage' => $refs->stages((array) $value, $valuePath),
            'owner' => $refs->user($value, $valuePath),
            'customer_tags' => $refs->tags((array) $value, $valuePath),
            default => self::fail($valuePath, __('crm.automations.invalid_rule')),
        };

        return $clean;
    }

    /** @param  array<string, mixed>  $action */
    private static function action(AutomationReferences $refs, string $subject, array $action, string $path): array
    {
        $type = $action['type'];

        if (! in_array($subject, self::ACTIONS[$type], true)) {
            self::fail("{$path}.type", __('crm.automations.action_not_for_trigger'));
        }

        $clean = ['type' => $type];

        switch ($type) {
            case 'assign_owner':
                $mode = $action['mode'] ?? 'user';
                in_array($mode, self::ASSIGN_MODES, true) || self::fail("{$path}.mode", __('crm.automations.invalid_rule'));
                $clean['mode'] = $mode;

                if ($mode === 'user') {
                    $clean['user_id'] = $refs->user($action['user_id'] ?? null, "{$path}.user_id");
                } else {
                    $ids = $refs->users((array) ($action['user_ids'] ?? []), "{$path}.user_ids");
                    count($ids) >= 1 || self::fail("{$path}.user_ids", __('crm.automations.pick_people'));
                    $clean['user_ids'] = $ids;
                }
                break;

            case 'set_lead_status':
                $settable = array_values(array_diff(LeadStatus::values(), [LeadStatus::Converted->value]));
                in_array($action['status'] ?? null, $settable, true) || self::fail("{$path}.status", __('crm.automations.invalid_rule'));
                $clean['status'] = $action['status'];
                break;

            case 'move_deal_stage':
                $clean['stage_id'] = $refs->stages([$action['stage_id'] ?? null], "{$path}.stage_id")[0];
                break;

            case 'create_task':
                $title = trim((string) ($action['title'] ?? ''));
                ($title !== '' && mb_strlen($title) <= 255) || self::fail("{$path}.title", __('crm.automations.task_title_required'));
                $clean['title'] = $title;
                $description = trim((string) ($action['description'] ?? ''));
                if ($description !== '') {
                    $clean['description'] = mb_substr($description, 0, 2000);
                }
                $clean['due_in_days'] = self::number($action['due_in_days'] ?? 1, "{$path}.due_in_days", 0, self::MAX_DAYS);
                $priority = $action['priority'] ?? TaskPriority::Normal->value;
                in_array($priority, TaskPriority::values(), true) || self::fail("{$path}.priority", __('crm.automations.invalid_rule'));
                $clean['priority'] = $priority;
                $assignee = $action['assignee'] ?? 'owner';
                in_array($assignee, self::TASK_ASSIGNEES, true) || self::fail("{$path}.assignee", __('crm.automations.invalid_rule'));
                $clean['assignee'] = $assignee;
                if ($assignee === 'user') {
                    $clean['user_id'] = $refs->user($action['user_id'] ?? null, "{$path}.user_id");
                }
                break;

            case 'notify':
                $recipients = $action['recipients'] ?? 'owner';
                in_array($recipients, self::NOTIFY_RECIPIENTS, true) || self::fail("{$path}.recipients", __('crm.automations.invalid_rule'));
                $clean['recipients'] = $recipients;
                if ($recipients !== 'owner') {
                    $ids = $refs->users((array) ($action['user_ids'] ?? []), "{$path}.user_ids");
                    count($ids) >= 1 || self::fail("{$path}.user_ids", __('crm.automations.pick_people'));
                    $clean['user_ids'] = $ids;
                }
                $message = trim((string) ($action['message'] ?? ''));
                ($message !== '' && mb_strlen($message) <= 500) || self::fail("{$path}.message", __('crm.automations.message_required'));
                $clean['message'] = $message;
                break;

            case 'add_customer_tags':
            case 'remove_customer_tags':
                $clean['tag_ids'] = $refs->tags((array) ($action['tag_ids'] ?? []), "{$path}.tag_ids");
                break;

            case 'set_customer_status':
                in_array($action['status'] ?? null, CustomerStatus::values(), true) || self::fail("{$path}.status", __('crm.automations.invalid_rule'));
                $clean['status'] = $action['status'];
                break;

            case 'start_flow':
                $clean['flow_id'] = $refs->flow($action['flow_id'] ?? null, "{$path}.flow_id");
                break;

            case 'add_note':
                $body = trim((string) ($action['body'] ?? ''));
                ($body !== '' && mb_strlen($body) <= 2000) || self::fail("{$path}.body", __('crm.automations.note_required'));
                $clean['body'] = $body;
                break;
        }

        return $clean;
    }

    /** @return list<string> */
    private static function listOf(array $values, array $allowed, string $path): array
    {
        $list = array_values(array_unique(array_filter($values, fn ($v) => is_string($v) && in_array($v, $allowed, true))));

        if ($list === [] || count($list) !== count(array_unique($values))) {
            self::fail($path, __('crm.automations.invalid_rule'));
        }

        return $list;
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $path): array
    {
        $list = array_values(array_unique(array_filter(array_map(
            fn ($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 40) : '',
            (array) $value,
        ))));

        $list === [] && self::fail($path, __('crm.automations.invalid_rule'));

        return $list;
    }

    private static function number(mixed $value, string $path, int $min, int $max): int
    {
        if (! is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            self::fail($path, __('crm.automations.invalid_rule'));
        }

        return (int) $value;
    }

    private static function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}

